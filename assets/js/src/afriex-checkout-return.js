/**
 * The "confirming your payment" panel on the order-received page.
 *
 * Afriex confirms a Checkout payment through its webhook, which usually lands
 * within a second or two of the shopper coming back — but not always, and the
 * redirect itself proves nothing. So the page asks the store, every five
 * seconds for two minutes, what the store has heard from Afriex, and reloads
 * itself as soon as the answer stops being "pending".
 *
 * The endpoint is a plain GET that takes the order key from the URL the store
 * built: no nonce, no cookies (`credentials: 'omit'`), nothing this script
 * could leak. A cache-busting parameter is added because the answer changes
 * while the URL does not.
 */

( function () {
	const config = window.afriexCheckoutReturn;

	if ( ! config || ! config.statusUrl ) {
		return;
	}

	const panel = document.getElementById(
		config.panelId || 'afriex-checkout-status'
	);

	if ( ! panel ) {
		return;
	}

	const message =
		panel.querySelector( '.afriex-checkout-status__message' ) || panel;
	const interval = parseInt( config.interval, 10 ) || 5000;
	const pollWindow = parseInt( config.window, 10 ) || 120000;
	const strings = config.i18n || {};
	const startedAt = Date.now();

	/**
	 * Say the last thing this panel will say, and stop polling.
	 *
	 * @param {string} text What to say.
	 */
	function finish( text ) {
		if ( text ) {
			message.textContent = text;
		}
	}

	/**
	 * Poll again, unless the two minutes are up.
	 */
	function schedule() {
		if ( Date.now() - startedAt >= pollWindow ) {
			finish( strings.timedOut );
			return;
		}

		window.setTimeout( poll, interval );
	}

	/**
	 * Ask the store what it has heard, once.
	 */
	function poll() {
		const url =
			config.statusUrl +
			( config.statusUrl.indexOf( '?' ) === -1 ? '?' : '&' ) +
			'_=' +
			Date.now();

		window
			.fetch( url, {
				credentials: 'omit',
				headers: { Accept: 'application/json' },
			} )
			.then( function ( response ) {
				return response.ok ? response.json() : null;
			} )
			.then( function ( body ) {
				const status = body && body.status;

				// Anything but "pending" is something the page itself should
				// be showing: WooCommerce's own paid or failed content, which
				// is rendered server-side, so the page is reloaded rather
				// than patched up here.
				if ( status === 'paid' || status === 'failed' ) {
					finish(
						status === 'paid' ? strings.settled : strings.failed
					);
					window.location.reload();
					return;
				}

				schedule();
			} )
			.catch( function () {
				// A hiccup on one poll is not news: try again next time.
				schedule();
			} );
	}

	// The store already asked Afriex when the shopper came back, so the first
	// poll waits out a full interval rather than firing immediately.
	schedule();
} )();
