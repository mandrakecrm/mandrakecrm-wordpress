/**
 * MandrakeCRM: keeps the customer's last click on the e-mail and WhatsApp checkboxes of the checkout.
 *
 * WooCommerce draws the payment section (where the boxes are) again after each order review refresh. A refresh that
 * started before a click and came back after it was drawn with the old state, so after each refresh this puts back
 * what the customer last chose. The server already draws the boxes with the state the browser sent (since 3.32).
 *
 * Each click also goes to a cookie (mandrakecrm_optin, «e1w0»: only the customer's own clicks, no identifier) that lasts
 * 2 hours and is removed when the cart is emptied, so a full page load (F5, back from the cart, a gateway that reloads the
 * checkout) draws the boxes as the customer left them, in the classic and in the block checkout.
 *
 * The clicks are listened to without jQuery, so a click is never lost when an optimizer loads jQuery late; the
 * «updated_checkout» event of WooCommerce needs jQuery and is listened to as soon as it is there, however late it comes:
 * checked every 100 ms for the first minute and on each interaction with the page. A refresh that could draw an old state
 * always follows an interaction (the customer's click or change), so after a minute only the interactions check (3.33).
 *
 * @since 3.32
 * @since 3.33 No check every second after the first minute.
 */
( function () {
	'use strict';

	var names = { mandrakecrm_marketing_optin: 'e', mandrakecrm_whatsapp_optin: 'w' };
	// The same boxes in the block checkout (React keeps their state; only the clicks are kept here).
	var blockIds = {
		'order-mandrakecrm-marketing-optin': 'mandrakecrm_marketing_optin',
		'order-mandrakecrm-whatsapp-optin': 'mandrakecrm_whatsapp_optin',
	};
	var chosen = {};

	function writeCookie() {
		var value = '';
		if ( Object.prototype.hasOwnProperty.call( chosen, 'mandrakecrm_marketing_optin' ) ) {
			value += 'e' + ( chosen.mandrakecrm_marketing_optin ? '1' : '0' );
		}
		if ( Object.prototype.hasOwnProperty.call( chosen, 'mandrakecrm_whatsapp_optin' ) ) {
			value += 'w' + ( chosen.mandrakecrm_whatsapp_optin ? '1' : '0' );
		}
		document.cookie = 'mandrakecrm_optin=' + value + '; max-age=7200; path=/; SameSite=Lax' + ( 'https:' === window.location.protocol ? '; Secure' : '' );
	}

	function readCookie() {
		var match = document.cookie.match( /(?:^|;\s*)mandrakecrm_optin=(e[01])?(w[01])?(?:;|$)/ );
		if ( ! match ) {
			return;
		}
		if ( match[ 1 ] ) {
			chosen.mandrakecrm_marketing_optin = '1' === match[ 1 ].charAt( 1 );
		}
		if ( match[ 2 ] ) {
			chosen.mandrakecrm_whatsapp_optin = '1' === match[ 2 ].charAt( 1 );
		}
	}

	function putBack() {
		Object.keys( chosen ).forEach( function ( name ) {
			var boxes = document.querySelectorAll( 'input[name="' + name + '"]' );
			for ( var i = 0; i < boxes.length; i++ ) {
				boxes[ i ].checked = chosen[ name ];
			}
		} );
	}

	readCookie();

	document.addEventListener(
		'change',
		function ( event ) {
			var box = event.target;
			if ( ! box || 'checkbox' !== box.type ) {
				return;
			}
			var name = Object.prototype.hasOwnProperty.call( names, box.name ) ? box.name : blockIds[ box.id ];
			if ( name ) {
				chosen[ name ] = box.checked;
				writeCookie();
			}
		},
		true
	);

	// «updated_checkout» as soon as jQuery is there, with no time limit: an optimizer may load it only after the first
	// interaction, minutes later. Every refresh follows an interaction, which tries again; the timer only covers the first minute.
	var listening = false;
	var tries = 0;
	var interactions = [ 'pointerdown', 'keydown', 'focusin', 'change' ];
	function listenRefresh() {
		if ( listening || ! window.jQuery || ! document.body ) {
			return listening;
		}
		listening = true;
		window.jQuery( document.body ).on( 'updated_checkout', putBack );
		interactions.forEach( function ( type ) {
			document.removeEventListener( type, listenRefresh, true );
		} );
		return true;
	}
	interactions.forEach( function ( type ) {
		document.addEventListener( type, listenRefresh, true );
	} );
	( function poll() {
		if ( ! listenRefresh() && tries++ < 600 ) {
			window.setTimeout( poll, 100 );
		}
	} )();
} )();
