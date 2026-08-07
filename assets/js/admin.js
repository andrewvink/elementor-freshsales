/**
 * Powers the "Validate Connection" button on Elementor → Settings → Integrations.
 *
 * Vanilla JS, no dependencies. Posts the entered domain + API key to the plugin's
 * AJAX endpoint and shows the result inline.
 *
 * @package Cornerstone\Elementor_Freshsales
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var button = document.getElementById( 'cornerstone_freshsales_validate_button' );

		if ( ! button || 'undefined' === typeof ajaxurl ) {
			return;
		}

		var apiKeyField = document.getElementById( 'elementor_cornerstone_freshsales_api_key' );
		var domainField = document.getElementById( 'elementor_cornerstone_freshsales_domain' );

		var status = document.createElement( 'span' );
		status.style.marginLeft = '8px';
		status.style.fontWeight = '600';
		button.parentNode.insertBefore( status, button.nextSibling );

		var COLOURS = { error: '#d63638', success: '#008a20', unsaved: '#996800' };

		function setState( state, message ) {
			button.classList.remove( 'loading', 'success', 'error', 'unsaved' );
			if ( state ) {
				button.classList.add( state );
			}
			status.textContent = message || '';
			status.style.color = COLOURS[ state ] || '';
		}

		button.addEventListener( 'click', function ( event ) {
			event.preventDefault();
			setState( 'loading', '' );

			var body = new URLSearchParams();
			body.append( 'action', button.getAttribute( 'data-action' ) );
			body.append( '_nonce', button.getAttribute( 'data-nonce' ) );
			body.append( 'api_key', apiKeyField ? apiKeyField.value : '' );
			body.append( 'domain', domainField ? domainField.value : '' );

			fetch( ajaxurl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: body.toString()
			} ).then( function ( response ) {
				return response.json();
			} ).then( function ( result ) {
				var data = result && result.data ? result.data : '';
				var message = 'string' === typeof data ? data : ( data.message || '' );

				if ( ! ( result && result.success ) ) {
					setState( 'error', message );
					return;
				}

				// A working key that is not the stored one is not a working integration yet.
				setState( false === data.saved ? 'unsaved' : 'success', message );
			} ).catch( function () {
				setState( 'error', '' );
			} );
		} );
	} );
}() );
