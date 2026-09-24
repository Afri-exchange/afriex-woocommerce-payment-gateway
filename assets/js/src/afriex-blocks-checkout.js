/**
 * Registers the Afriex payment methods on the Cart & Checkout blocks.
 *
 * This module only describes the methods to the block checkout UI. The `name`
 * of each is what tells WooCommerce which server-side gateway class to invoke,
 * so processing still runs through that gateway's process_payment() — one path
 * for both checkouts.
 *
 * One bundle registers both methods, each from its own settings key
 * (`afriex_data`, `afriex_checkout_data`). WooCommerce prints a method's data
 * only while the method is active, so a method the store has switched off, or
 * one hidden by its own availability rules, arrives here with no settings and
 * is simply not registered.
 */

import { registerPaymentMethod } from '@woocommerce/blocks-registry';
import { getSetting } from '@woocommerce/settings';
import { decodeEntities } from '@wordpress/html-entities';
import { __ } from '@wordpress/i18n';

/**
 * The Afriex mark. Inline rather than an image file so it costs no HTTP
 * request and scales with the label.
 *
 * @param {Object} props           Component props.
 * @param {string} props.ariaLabel Accessible name for the mark.
 */
const AfriexMark = ( { ariaLabel } ) => (
	<svg
		width="24"
		height="24"
		viewBox="0 0 24 24"
		role="img"
		aria-label={ ariaLabel }
		focusable="false"
		style={ { verticalAlign: 'middle', marginRight: '0.5em' } }
	>
		<circle cx="12" cy="12" r="11" fill="#0b6e4f" />
		<path
			d="M12 5.5 17.5 18h-2.6l-1-2.4H9.1l-1 2.4H5.5L11 5.5h1zm-.5 3.9-1.6 3.9h3.2l-1.6-3.9z"
			fill="#ffffff"
		/>
	</svg>
);

/**
 * Label: the mark and the admin's title.
 *
 * @param {Object} props       Component props.
 * @param {string} props.title Method title.
 */
const AfriexLabel = ( { title } ) => (
	<span className="wc-block-components-payment-method-label wc-afriex-label">
		<AfriexMark ariaLabel={ title } />
		{ title }
	</span>
);

/**
 * Description, plus a badge per payment option Afriex offers for this store's
 * currency. The badges are omitted entirely while Afriex has never said which
 * options it supports here, rather than guessed at.
 *
 * @param {Object} props             Component props.
 * @param {string} props.description Method description.
 * @param {Array}  props.channels    Known channels as { code, label }.
 */
const AfriexContent = ( { description, channels } ) => (
	<div className="wc-afriex-method-description">
		{ description ? <p>{ description }</p> : null }
		{ channels.length > 0 ? (
			<ul
				className="wc-afriex-channels"
				aria-label={ __(
					'Payment options on the Afriex page',
					'afriex-gateway-for-woocommerce'
				) }
				style={ {
					display: 'flex',
					flexWrap: 'wrap',
					gap: '0.5em',
					listStyle: 'none',
					margin: '0.5em 0 0',
					padding: 0,
				} }
			>
				{ channels.map( ( channel ) => (
					<li
						key={ channel.code }
						className={ `wc-afriex-channel wc-afriex-channel--${ channel.code.toLowerCase() }` }
						style={ {
							border: '1px solid currentColor',
							borderRadius: '1em',
							fontSize: '0.8em',
							opacity: 0.8,
							padding: '0.15em 0.75em',
						} }
					>
						{ channel.label }
					</li>
				) ) }
			</ul>
		) : null }
	</div>
);

/**
 * Register one Afriex method from its server-side settings key.
 *
 * @param {string} settingsKey  Key the server printed the data under.
 * @param {string} defaultLabel Label to use when the title is empty.
 */
const registerAfriexMethod = ( settingsKey, defaultLabel ) => {
	const settings = getSetting( settingsKey, {} );

	// No data means the server did not offer this method on this page.
	if ( ! settings || ! settings.id ) {
		return;
	}

	const label = decodeEntities( settings.title || '' ) || defaultLabel;
	const description = decodeEntities( settings.description || '' );
	const channels = Array.isArray( settings.channels )
		? settings.channels.filter( ( channel ) => channel && channel.code )
		: [];

	const content = (
		<AfriexContent description={ description } channels={ channels } />
	);

	const method = {
		name: settings.id,
		label: <AfriexLabel title={ label } />,
		content,
		edit: content,
		// Availability is decided server-side by the gateway's is_available(),
		// which is_active() delegates to. If the method reached the client at
		// all, it can be used.
		canMakePayment: () => true,
		ariaLabel: label,
		supports: {
			features: settings.supports ?? [ 'products' ],
		},
	};

	if ( settings.placeOrderButtonLabel ) {
		method.placeOrderButtonLabel = decodeEntities(
			settings.placeOrderButtonLabel
		);
	}

	registerPaymentMethod( method );
};

registerAfriexMethod(
	'afriex_data',
	__( 'Bank Transfer', 'afriex-gateway-for-woocommerce' )
);

registerAfriexMethod(
	'afriex_checkout_data',
	__( 'Pay with Afriex', 'afriex-gateway-for-woocommerce' )
);
