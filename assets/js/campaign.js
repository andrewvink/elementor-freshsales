/**
 * First-touch campaign capture.
 *
 * Stores the campaign parameters from the URL the visitor *arrived* on, so they survive
 * the visitor browsing the site before reaching a form. The cookie rides along with the
 * form's admin-ajax POST, where Freshsales_Action::get_campaign_data() reads it back.
 *
 * First-touch: once a visit has been attributed, a later UTM-tagged URL does not
 * overwrite it. The original campaign is the one that earned the lead.
 *
 * The tracked parameter list and cookie settings come from PHP
 * (window.CornerstoneFreshsalesCampaign). No libraries.
 *
 * @package Cornerstone\Elementor_Freshsales
 */
( function () {
	'use strict';

	// Budget for the stored cookie, well under any server's request-header limit even once
	// WordPress's own auth cookies and other plugins' cookies are added alongside it.
	var MAX_COOKIE_BYTES = 2048;

	var config = window.CornerstoneFreshsalesCampaign;

	if ( ! config || ! config.cookie || ! config.params || ! config.params.length ) {
		return;
	}

	function readCookie( name ) {
		var parts = document.cookie ? document.cookie.split( ';' ) : [];

		for ( var i = 0; i < parts.length; i++ ) {
			var part = parts[ i ].trim();

			if ( 0 === part.indexOf( name + '=' ) ) {
				return part.substring( name.length + 1 );
			}
		}

		return '';
	}

	function writeCookie( name, value, days ) {
		var expires = new Date( Date.now() + ( days * 86400000 ) ).toUTCString(),
			secure = 'https:' === location.protocol ? '; Secure' : '';

		document.cookie = name + '=' + value + '; expires=' + expires +
			'; path=/; SameSite=Lax' + secure;
	}

	// First touch wins — if this visitor is already attributed, leave it alone.
	if ( readCookie( config.cookie ) ) {
		return;
	}

	var query = new URLSearchParams( location.search ),
		max = config.max || 200,
		captured = {},
		found = false;

	config.params.forEach( function ( param ) {
		var value = query.get( param );

		if ( null === value ) {
			return;
		}

		value = value.trim().slice( 0, max );

		if ( value ) {
			captured[ param ] = value;
			found = true;
		}
	} );

	// Nothing to attribute — do not set a cookie, so a later campaign visit still counts
	// as this visitor's first touch.
	if ( ! found ) {
		return;
	}

	// Context that makes the campaign readable in the CRM later. Query strings are dropped
	// from both: they routinely carry personal data and session tokens that have no business
	// being copied into a CRM.
	captured.landing_page = ( location.origin + location.pathname ).slice( 0, max );

	if ( document.referrer && 0 !== document.referrer.indexOf( location.origin ) ) {
		try {
			var ref = new URL( document.referrer );
			captured.referrer = ( ref.origin + ref.pathname ).slice( 0, max );
		} catch ( e ) {
			// Unparseable referrer — skip it rather than store something unexpected.
		}
	}

	// This cookie rides on every subsequent request to the site. A crafted link stuffing all
	// the tracked parameters could otherwise push the request headers past the server's limit
	// and lock the visitor out, so drop the optional context — and then the cookie itself —
	// rather than ever exceed the budget.
	var encoded = encodeURIComponent( JSON.stringify( captured ) );

	if ( encoded.length > MAX_COOKIE_BYTES ) {
		delete captured.referrer;
		encoded = encodeURIComponent( JSON.stringify( captured ) );
	}

	if ( encoded.length > MAX_COOKIE_BYTES ) {
		delete captured.landing_page;
		encoded = encodeURIComponent( JSON.stringify( captured ) );
	}

	if ( encoded.length > MAX_COOKIE_BYTES ) {
		return;
	}

	try {
		writeCookie( config.cookie, encoded, config.days || 180 );
	} catch ( e ) {
		// Cookies unavailable (private mode, blocked) — capture is best-effort and must
		// never interfere with the page.
	}
}() );
