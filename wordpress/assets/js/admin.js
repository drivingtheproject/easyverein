/**
 * easyVerein Membership Form – settings screen.
 */
( function () {
	'use strict';

	var cfg = window.evmfAdmin || {};
	var t = cfg.i18n || {};

	function initTabs() {
		var tabs = document.querySelectorAll( '.evmf-tabs .nav-tab' );
		var panels = document.querySelectorAll( '.evmf-tab-panel' );
		if ( ! tabs.length ) {
			return;
		}
		function show( id ) {
			var found = false;
			tabs.forEach( function ( tab ) {
				var active = tab.getAttribute( 'data-tab' ) === id;
				tab.classList.toggle( 'nav-tab-active', active );
				found = found || active;
			} );
			if ( ! found ) {
				id = tabs[ 0 ].getAttribute( 'data-tab' );
				tabs[ 0 ].classList.add( 'nav-tab-active' );
			}
			panels.forEach( function ( panel ) {
				panel.hidden = panel.getAttribute( 'data-panel' ) !== id;
			} );
			// Keep the active tab after saving (options.php redirects back to the referer).
			var referer = document.querySelector( 'input[name="_wp_http_referer"]' );
			if ( referer ) {
				referer.value = referer.value.split( '#' )[ 0 ] + '#' + id;
			}
		}
		tabs.forEach( function ( tab ) {
			tab.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				var id = tab.getAttribute( 'data-tab' );
				history.replaceState( null, '', '#' + id );
				show( id );
			} );
		} );
		show( ( window.location.hash || '' ).replace( '#', '' ) );
	}

	function initTypes() {
		var table = document.getElementById( 'evmf-types' );
		var add = document.getElementById( 'evmf-add-type' );
		var tpl = document.getElementById( 'evmf-type-template' );
		if ( ! table || ! add || ! tpl ) {
			return;
		}
		var body = table.querySelector( 'tbody' );
		var next = body.querySelectorAll( 'tr' ).length;
		add.addEventListener( 'click', function () {
			body.insertAdjacentHTML( 'beforeend', tpl.innerHTML.replace( /__i__/g, String( next++ ) ) );
			var rows = body.querySelectorAll( 'tr' );
			rows[ rows.length - 1 ].querySelector( 'input' ).focus();
		} );
		body.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest( '.evmf-remove-type' );
			if ( btn && body.querySelectorAll( 'tr' ).length > 1 ) {
				btn.closest( 'tr' ).remove();
			}
		} );
	}

	function ajax( action, message ) {
		var result = document.getElementById( 'evmf-connection-result' );
		var status = document.getElementById( 'evmf-token-status' );
		result.className = 'evmf-result';
		result.textContent = message;

		var body = new FormData();
		body.append( 'action', action );
		body.append( 'nonce', cfg.nonce );

		return fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( r ) {
				return r.json();
			} )
			.then( function ( json ) {
				var data = json.data || {};
				result.classList.add( json.success ? 'is-ok' : 'is-error' );
				result.textContent = data.message || '';
				if ( data.status && status ) {
					status.innerHTML = data.status;
				}
			} )
			.catch( function ( err ) {
				result.classList.add( 'is-error' );
				result.textContent = ( t.failed || 'Request failed:' ) + ' ' + err.message;
			} );
	}

	function initConnection() {
		var test = document.getElementById( 'evmf-test-connection' );
		var refresh = document.getElementById( 'evmf-refresh-token' );
		if ( test ) {
			test.addEventListener( 'click', function () {
				test.disabled = true;
				ajax( 'evmf_test_connection', t.testing ).then( function () {
					test.disabled = false;
				} );
			} );
		}
		if ( refresh ) {
			refresh.addEventListener( 'click', function () {
				if ( ! window.confirm( t.confirmRefresh ) ) {
					return;
				}
				refresh.disabled = true;
				ajax( 'evmf_refresh_token', t.refreshing ).then( function () {
					refresh.disabled = false;
				} );
			} );
		}
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		initTabs();
		initTypes();
		initConnection();
	} );
} )();
