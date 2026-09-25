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
 * The Afriex mark, the same drawing as assets/images/afriex-icon.svg. Inline
 * rather than an image file so it costs no HTTP request and scales with the
 * label.
 *
 * @param {Object} props           Component props.
 * @param {string} props.ariaLabel Accessible name for the mark.
 */
const AfriexMark = ( { ariaLabel } ) => (
	<svg
		width="24"
		height="24"
		viewBox="0 0 48 48"
		role="img"
		aria-label={ ariaLabel }
		focusable="false"
		style={ { verticalAlign: 'middle', marginRight: '0.5em' } }
	>
		<rect width="48" height="48" rx="11" fill="#0065FF" />
		<path
			transform="translate(24 24) scale(0.82) translate(-16 -20.1)"
			d="M15.7605 8.72194L10.516 17.0377L6.23948 14.8725C5.91096 14.7066 5.54548 14.6276 5.17778 14.6429C4.81009 14.6582 4.45239 14.7673 4.13878 14.9599C3.82516 15.1524 3.56602 15.422 3.386 15.743C3.20598 16.0639 3.11107 16.4256 3.1103 16.7936C3.11115 17.1445 3.19755 17.4898 3.362 17.7996C3.52645 18.1095 3.764 18.3746 4.05405 18.5719L7.887 21.1929L3.1103 28.767L0 33.6853H7.56343L13.1733 24.8047L25.0253 32.902C25.7734 33.4126 26.6582 33.6856 27.564 33.6853C28.2827 33.6849 28.9903 33.5076 29.6243 33.1689C30.2583 32.8302 30.799 32.3406 31.1988 31.7433C31.5986 31.146 31.8451 30.4594 31.9166 29.7442C31.9881 29.029 31.8823 28.3072 31.6086 27.6426L24.114 9.4419C22.6566 5.90151 17.807 5.48225 15.7605 8.72194ZM16.2404 19.9458L19.4384 14.8806L22.9194 23.3365L16.2404 19.9458Z"
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
