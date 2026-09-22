#!/usr/bin/env node
/**
 * Sends genuinely signed Afriex webhook payloads at a local WordPress site.
 *
 * The plugin verifies deliveries with openssl_verify( $raw_body, $sig,
 * $public_key, OPENSSL_ALGO_SHA256 ). This signs with RSA-SHA256 over the exact
 * bytes it posts, so the real verification path is exercised — not stubbed.
 *
 * No dependencies: Node's own crypto and fetch.
 *
 *   node tests/tools/webhook.mjs keygen
 *   node tests/tools/webhook.mjs send --payment-method-id pm_123 --status COMPLETED --amount 49.99
 *   node tests/tools/webhook.mjs send --payment-method-id pm_123 --status COMPLETED --amount 49.99 --repeat 2
 *   node tests/tools/webhook.mjs send --payment-method-id pm_123 --status COMPLETED --amount 1.00 --tamper
 */

import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = path.dirname( fileURLToPath( import.meta.url ) );
const KEY_DIR = path.join( HERE, '.keys' );
const PRIVATE_KEY_PATH = path.join( KEY_DIR, 'private.pem' );
const PUBLIC_KEY_PATH = path.join( KEY_DIR, 'public.pem' );

const DEFAULT_URL = 'http://localhost:8888/wp-json/afriex/v1/webhook';

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

function buildPayload( args ) {
	const status = String( args.status || 'COMPLETED' ).toUpperCase();
	const amount = Number( args.amount ?? 0 );

	return {
		event: String( args.event || 'TRANSACTION.UPDATED' ),
		data: {
			transactionId: String( args[ 'transaction-id' ] || 'txn_test_1' ),
			destinationId: String( args[ 'payment-method-id' ] || 'pm_test_1' ),
			reference: args.reference ? String( args.reference ) : undefined,
			status,
			destinationAmount: amount,
			updatedAt: String( args[ 'updated-at' ] || new Date().toISOString() ),
		},
	};
}

async function send( args ) {
	const privateKey = readPrivateKey();
	const url = String( args.url || DEFAULT_URL );
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
		? body.replace( /"destinationAmount":\s*[\d.]+/, '"destinationAmount":999999' )
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

const [ command, ...rest ] = process.argv.slice( 2 );
const args = parseArgs( rest );

switch ( command ) {
	case 'keygen':
		keygen();
		break;
	case 'send':
		await send( args );
		break;
	default:
		console.log(
			'Usage:\n  node tests/tools/webhook.mjs keygen\n  node tests/tools/webhook.mjs send [--url U] [--payment-method-id ID] [--status S] [--amount N] [--event E] [--transaction-id T] [--reference R] [--repeat N] [--tamper]'
		);
		process.exit( 1 );
}
