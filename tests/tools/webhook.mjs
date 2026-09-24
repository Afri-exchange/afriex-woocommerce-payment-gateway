#!/usr/bin/env node
/**
 * Drives Afriex events at a local WordPress site.
 *
 * Two ways in, for two different jobs:
 *
 * 1. `send` signs a payload here and POSTs it straight at the webhook route.
 *    Use it to test the route itself: a tampered body, a missing signature, a
 *    replay, an event the plugin has never seen. Nothing else on the site
 *    knows the transaction exists.
 *
 * 2. `deposit` and `status` go through the development mock API
 *    (tests/mu-plugins/afriex-mock-api.php). The transaction is recorded in
 *    the mock's fake account first, and the site signs and delivers the
 *    webhook itself. Use these when the plugin will look the transaction up
 *    again — every re-fetch, list and sweep then finds it, exactly as it
 *    would against the real Afriex. This is the normal way to pay an order.
 *
 * The plugin verifies with openssl_verify( $raw_body, $sig, $public_key,
 * OPENSSL_ALGO_SHA256 ). Both paths sign with RSA-SHA256 over the exact bytes
 * that go on the wire, so the real verification path runs; nothing is stubbed.
 *
 * No dependencies: Node's own crypto and fetch.
 *
 *   node tests/tools/webhook.mjs keygen
 *   node tests/tools/webhook.mjs deposit --payment-method-id pm_123 --amount 49.99
 *   node tests/tools/webhook.mjs deposit --payment-method-id pm_123 --amount 49.99 --status PENDING
 *   node tests/tools/webhook.mjs status --transaction-id 69eb... --status SUCCESS
 *   node tests/tools/webhook.mjs session --reference wc_12_ab... --transaction-id 69eb...
 *   node tests/tools/webhook.mjs fail --endpoint checkout_session --mode unavailable --times 1
 *   node tests/tools/webhook.mjs state --section requests
 *   node tests/tools/webhook.mjs reset
 *   node tests/tools/webhook.mjs send --payment-method-id pm_123 --status SUCCESS --amount 49.99
 *   node tests/tools/webhook.mjs send --payment-method-id pm_123 --status SUCCESS --amount 1.00 --tamper
 */

import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = path.dirname( fileURLToPath( import.meta.url ) );
const KEY_DIR = path.join( HERE, '.keys' );
const PRIVATE_KEY_PATH = path.join( KEY_DIR, 'private.pem' );
const PUBLIC_KEY_PATH = path.join( KEY_DIR, 'public.pem' );

const DEFAULT_SITE = 'http://localhost:8888';
const WEBHOOK_PATH = '/wp-json/afriex/v1/webhook';
const MOCK_PATH = '/wp-json/afriex-mock/v1';

// SUCCESS, not COMPLETED: COMPLETED is not in Afriex's status enum at all, so
// a payload carrying it only ever exercised the plugin's fallback handling.
const DEFAULT_STATUS = 'SUCCESS';

function parseArgs( argv ) {
	const args = {};
	for ( let i = 0; i < argv.length; i++ ) {
		const token = argv[ i ];
		if ( ! token.startsWith( '--' ) ) continue;
		const key = token.slice( 2 );
		const next = argv[ i + 1 ];
		if ( next === undefined || next.startsWith( '--' ) ) {
			args[ key ] = true;
		} else {
			args[ key ] = next;
			i++;
		}
	}
	return args;
}

const site = ( args ) => String( args.site || DEFAULT_SITE ).replace( /\/+$/, '' );

function keygen() {
	const { publicKey, privateKey } = crypto.generateKeyPairSync( 'rsa', {
		modulusLength: 2048,
		publicKeyEncoding: { type: 'spki', format: 'pem' },
		privateKeyEncoding: { type: 'pkcs8', format: 'pem' },
	} );

	fs.mkdirSync( KEY_DIR, { recursive: true, mode: 0o700 } );
	// Owner-only. The mode option applies only when the file is created, so
	// chmod as well to tighten a key that is being overwritten.
	fs.writeFileSync( PRIVATE_KEY_PATH, privateKey, { mode: 0o600 } );
	fs.chmodSync( PRIVATE_KEY_PATH, 0o600 );
	fs.writeFileSync( PUBLIC_KEY_PATH, publicKey );

	console.log( `Wrote ${ PRIVATE_KEY_PATH }` );
	console.log( `Wrote ${ PUBLIC_KEY_PATH }\n` );
	console.log(
		'Paste the public key into WooCommerce > Settings > Payments > Afriex >'
	);
	console.log( 'Webhook Public Key, or load it (this re-seeds the gateway settings) with:\n' );
	console.log( '  npm run env:setup\n' );
	console.log( publicKey );
}

function readPrivateKey() {
	if ( ! fs.existsSync( PRIVATE_KEY_PATH ) ) {
		console.error(
			'No signing key found. Run "node tests/tools/webhook.mjs keygen" first.'
		);
		process.exit( 1 );
	}
	return fs.readFileSync( PRIVATE_KEY_PATH, 'utf8' );
}

/**
 * A transaction payload shaped like Afriex's own.
 *
 * Amounts are major-unit decimal STRINGS, and the merchant reference appears
 * both as merchantReference and as meta.reference, which is what the docs
 * show. --legacy-reference additionally sets the top-level `reference` field
 * that 1.0's pool-mode matching looked for, for testing that path on its own.
 */
function buildPayload( args ) {
	const status = String( args.status || DEFAULT_STATUS ).toUpperCase();
	const amount = String( args.amount ?? '0' );
	const currency = String( args.currency || 'NGN' ).toUpperCase();
	const reference = args.reference ? String( args.reference ) : undefined;

	const data = {
		transactionId: String( args[ 'transaction-id' ] || 'txn_test_1' ),
		type: String( args.type || 'DEPOSIT' ).toUpperCase(),
		channel: String( args.channel || 'VIRTUAL_BANK_ACCOUNT' ).toUpperCase(),
		status,
		sourceAmount: amount,
		sourceCurrency: currency,
		destinationAmount: amount,
		destinationCurrency: currency,
		destinationId: String( args[ 'payment-method-id' ] || 'pm_test_1' ),
		customerId: String( args[ 'customer-id' ] || 'cus_test_1' ),
		merchantReference: reference,
		meta: reference ? { reference, idempotencyKey: reference } : {},
		createdAt: String( args[ 'created-at' ] || new Date().toISOString() ),
		updatedAt: String( args[ 'updated-at' ] || new Date().toISOString() ),
	};

	if ( args[ 'legacy-reference' ] && reference ) {
		data.reference = reference;
	}

	return {
		event: String( args.event || 'TRANSACTION.UPDATED' ),
		data,
	};
}

async function send( args ) {
	const privateKey = readPrivateKey();
	const url = String( args.url || site( args ) + WEBHOOK_PATH );
	const repeat = Number( args.repeat || 1 );

	// Sign the exact bytes that go on the wire. Re-encoding between signing and
	// sending is the classic way to make a correct signature fail.
	const body = JSON.stringify( buildPayload( args ) );

	const signature = crypto
		.createSign( 'RSA-SHA256' )
		.update( body, 'utf8' )
		.sign( privateKey, 'base64' );

	// Change the body after signing: the signature is valid for what was signed
	// but not for what is sent, which is exactly what an attacker's request
	// looks like. This must be rejected at permission_callback.
	const sentBody = args.tamper
		? body.replace( /"destinationAmount":\s*"?[\d.]+"?/, '"destinationAmount":"999999"' )
		: body;

	if ( args.tamper ) {
		console.log( 'Tampering with the body after signing.\n' );
	}

	for ( let attempt = 1; attempt <= repeat; attempt++ ) {
		const response = await fetch( url, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'x-webhook-signature': signature,
			},
			body: sentBody,
		} );

		const text = await response.text();
		const tag = repeat > 1 ? ` (delivery ${ attempt }/${ repeat })` : '';
		console.log( `${ response.status } ${ response.statusText }${ tag }  ${ text }` );
	}

	console.log( `\nSent to ${ url }` );
	console.log( `Body: ${ sentBody }` );
}

/**
 * Call one of the mock's control routes.
 *
 * They answer only to localhost, which is also the only host the tunnel guard
 * lets past, so this never works from anywhere but this machine.
 */
async function mock( args, route, body, method = 'POST' ) {
	const url = site( args ) + MOCK_PATH + route;
	const response = await fetch( url, {
		method,
		headers: { 'Content-Type': 'application/json' },
		body: method === 'GET' ? undefined : JSON.stringify( body ?? {} ),
	} );

	const text = await response.text();

	if ( ! response.ok ) {
		console.error( `${ response.status } ${ response.statusText } from ${ url }` );
		console.error( text.slice( 0, 500 ) );
		if ( response.status === 404 ) {
			console.error(
				'\nIs the mock enabled? It needs AFRIEX_DEV_MOCK_API true and a local\n' +
					'environment type. Check with: npm run env:cli -- config get AFRIEX_DEV_MOCK_API'
			);
		}
		process.exit( 1 );
	}

	try {
		return JSON.parse( text );
	} catch ( error ) {
		console.error( `Unreadable answer from ${ url }:\n${ text.slice( 0, 500 ) }` );
		process.exit( 1 );
	}
}

function reportDelivery( delivery ) {
	if ( ! delivery ) {
		console.log( 'Webhook: not delivered (--no-deliver).' );
		return;
	}

	if ( ! delivery.ok ) {
		console.log(
			`Webhook: FAILED  ${ delivery.status || '' } ${ delivery.error || delivery.body || '' }`
		);
		return;
	}

	console.log( `Webhook: ${ delivery.event } -> ${ delivery.status }  ${ delivery.body }` );
}

/**
 * Record a deposit in the mock's fake account and let the site deliver the
 * signed webhook for it.
 */
async function deposit( args ) {
	const payload = {
		transactionId: args[ 'transaction-id' ],
		type: String( args.type || 'DEPOSIT' ).toUpperCase(),
		channel: String( args.channel || 'VIRTUAL_BANK_ACCOUNT' ).toUpperCase(),
		status: String( args.status || DEFAULT_STATUS ).toUpperCase(),
		amount: String( args.amount ?? '0' ),
		currency: String( args.currency || 'NGN' ).toUpperCase(),
		destinationId: args[ 'payment-method-id' ],
		sourceId: args[ 'source-id' ],
		customerId: args[ 'customer-id' ],
		merchantReference: args.reference,
		fee: args.fee,
		deliver: args[ 'no-deliver' ]
			? 'none'
			: String( args.event || 'created' )
					.replace( /^TRANSACTION\./i, '' )
					.toLowerCase(),
	};

	const result = await mock( args, '/transaction', payload );

	console.log( `Transaction: ${ result.transaction.transactionId }  ${ result.transaction.status }` );
	console.log( `Reference:   ${ result.transaction.merchantReference || '(none)' }` );
	console.log( `Destination: ${ result.transaction.destinationId || '(none)' }` );
	reportDelivery( result.delivery );
	console.log( '\nMove it on with:' );
	console.log(
		`  node tests/tools/webhook.mjs status --transaction-id ${ result.transaction.transactionId } --status SUCCESS`
	);
}

/**
 * Move a mock transaction to another status and re-deliver the webhook.
 */
async function status( args ) {
	const id = String( args[ 'transaction-id' ] || '' );

	if ( ! id ) {
		console.error( 'status needs --transaction-id.' );
		process.exit( 2 );
	}

	const result = await mock( args, `/transaction/${ encodeURIComponent( id ) }/status`, {
		status: String( args.status || DEFAULT_STATUS ).toUpperCase(),
		updatedAt: args[ 'updated-at' ],
		deliver: args[ 'no-deliver' ] ? 'none' : true,
		failureReason: args[ 'failure-code' ]
			? {
					code: String( args[ 'failure-code' ] ),
					message: String(
						args[ 'failure-message' ] || 'The payment could not be completed.'
					),
					retryable: Boolean( args[ 'failure-retryable' ] ),
			  }
			: undefined,
	} );

	console.log( `Transaction: ${ result.transaction.transactionId }  ${ result.transaction.status }` );
	reportDelivery( result.delivery );
}

/**
 * Deliver a CHECKOUT_SESSION.CREATED event for a session the mock knows.
 */
async function session( args ) {
	const result = await mock( args, '/checkout-session-event', {
		reference: args.reference,
		sessionId: args[ 'session-id' ],
		expiresAt: args[ 'expires-at' ],
		amount: args.amount,
		currency: args.currency,
		afriexTransactionId: args[ 'transaction-id' ],
		paidAt: args[ 'paid-at' ],
	} );

	console.log( `Session: ${ result.data.sessionId }  expires ${ result.data.expiresAt }` );
	reportDelivery( result.delivery );
}

/**
 * Move a virtual account's status in the mock and deliver
 * PAYMENT_METHOD.UPDATED for it. Defaults to `expired`, which is the only
 * status the plugin acts on.
 */
async function paymentMethod( args ) {
	const result = await mock( args, '/payment-method-event', {
		paymentMethodId: args[ 'payment-method-id' ],
		status: args.status || 'expired',
		customerId: args[ 'customer-id' ],
		currency: args.currency,
	} );

	console.log(
		`Payment method: ${ result.paymentMethod.paymentMethodId }  ${ result.paymentMethod.status }`
	);
	reportDelivery( result.delivery );
}

/** Arm (or clear) a forced failure on one mock endpoint. */
async function fail( args ) {
	const result = await mock( args, '/failure', {
		endpoint: String( args.endpoint || '' ),
		mode: String( args.mode || 'none' ),
		times: Number( args.times || 0 ),
	} );

	console.log( JSON.stringify( result.failures, null, 2 ) );
}

/** Print the mock's fake account, or one section of it. */
async function state( args ) {
	const section = args.section
		? `?section=${ encodeURIComponent( String( args.section ) ) }`
		: '';
	const result = await mock( args, `/state${ section }`, null, 'GET' );

	console.log( JSON.stringify( result, null, 2 ) );
}

/** Forget every fake customer, account, transaction and session. */
async function reset( args ) {
	await mock( args, '/reset', {} );
	console.log( 'The mock Afriex account is empty again.' );
}

const [ command, ...rest ] = process.argv.slice( 2 );
const args = parseArgs( rest );

switch ( command ) {
	case 'keygen':
		keygen();
		break;
	case 'send':
		await send( args );
		break;
	case 'deposit':
		await deposit( args );
		break;
	case 'status':
		await status( args );
		break;
	case 'session':
		await session( args );
		break;
	case 'pm':
		await paymentMethod( args );
		break;
	case 'fail':
		await fail( args );
		break;
	case 'state':
		await state( args );
		break;
	case 'reset':
		await reset( args );
		break;
	default:
		console.log(
			[
				'Usage:',
				'  node tests/tools/webhook.mjs keygen',
				'',
				'  Through the mock API (the transaction exists afterwards):',
				'  node tests/tools/webhook.mjs deposit [--payment-method-id ID] [--reference R] [--amount N] [--currency C] [--status S] [--type T] [--channel C] [--event created|updated] [--no-deliver]',
				'  node tests/tools/webhook.mjs status --transaction-id T [--status S] [--failure-code AFX_...] [--no-deliver]',
				'  node tests/tools/webhook.mjs session [--reference R] [--transaction-id T] [--expires-at ISO]',
				'  node tests/tools/webhook.mjs pm --payment-method-id ID [--status expired|active]',
				'  node tests/tools/webhook.mjs fail --endpoint E --mode M [--times N]',
				'  node tests/tools/webhook.mjs state [--section requests|deliveries|transactions|sessions|customers|payment_methods|failures|settings]',
				'  node tests/tools/webhook.mjs reset',
				'',
				'  Straight at the webhook route (nothing else knows about it):',
				'  node tests/tools/webhook.mjs send [--url U] [--payment-method-id ID] [--status S] [--amount N] [--event E] [--transaction-id T] [--reference R] [--legacy-reference] [--repeat N] [--tamper]',
				'',
				'  --site U overrides http://localhost:8888 for every command.',
				'  Failure modes: validation, unsupported_channels, unavailable,',
				'  created_then_unavailable, rate_limited, gateway, auth, server,',
				'  network, empty_body.',
				'  Endpoints: customer_create, customer_list, virtual_account_create,',
				'  payment_method_get, transaction_get, transaction_list, checkout_session.',
			].join( '\n' )
		);
		process.exit( 1 );
}
