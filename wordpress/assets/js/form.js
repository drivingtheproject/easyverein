/**
 * easyVerein Membership Form – front-end behaviour (no dependencies).
 * The server validates everything again; this only improves the experience.
 */
( function () {
	'use strict';

	var cfg = window.evmfForm || { maxChildren: 4, minAge: 18, familyTypes: [], i18n: {} };
	var t = cfg.i18n || {};

	function sprintf( str, n ) {
		return String( str || '' ).replace( '%d', n );
	}

	function ibanIsValid( value ) {
		var iban = value.toUpperCase().replace( /[^A-Z0-9]/g, '' );
		if ( ! /^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/.test( iban ) ) {
			return false;
		}
		var rearranged = iban.slice( 4 ) + iban.slice( 0, 4 );
		var numeric = '';
		for ( var i = 0; i < rearranged.length; i++ ) {
			var c = rearranged.charAt( i );
			numeric += /[A-Z]/.test( c ) ? String( c.charCodeAt( 0 ) - 55 ) : c;
		}
		var remainder = 0;
		for ( var j = 0; j < numeric.length; j += 7 ) {
			remainder = parseInt( String( remainder ) + numeric.substr( j, 7 ), 10 ) % 97;
		}
		return remainder === 1;
	}

	function formatIban( value ) {
		return value.toUpperCase().replace( /[^A-Z0-9]/g, '' ).replace( /(.{4})/g, '$1 ' ).trim();
	}

	function ageOn( dobString ) {
		var dob = new Date( dobString + 'T00:00:00' );
		if ( isNaN( dob.getTime() ) ) {
			return -1;
		}
		var today = new Date();
		var age = today.getFullYear() - dob.getFullYear();
		var m = today.getMonth() - dob.getMonth();
		if ( m < 0 || ( m === 0 && today.getDate() < dob.getDate() ) ) {
			age--;
		}
		return age;
	}

	function init( form ) {
		var typeSelect = form.querySelector( '#evmf-membership-type' );
		var family = form.querySelector( '#evmf-family' );
		var container = form.querySelector( '#evmf-children' );
		var addBtn = form.querySelector( '#evmf-add-child' );
		var tpl = form.querySelector( '#evmf-child-template' );
		var iban = form.querySelector( '#evmf-iban' );
		var bic = form.querySelector( '#evmf-bic' );
		var dob = form.querySelector( '#evmf-dob' );
		var submit = form.querySelector( 'button[type="submit"]' );
		var nextIndex = container ? container.querySelectorAll( '.evmf-child' ).length : 0;

		function isFamily() {
			var opt = typeSelect && typeSelect.options[ typeSelect.selectedIndex ];
			return !! ( opt && opt.getAttribute( 'data-family' ) === '1' );
		}

		function toggleFamily() {
			if ( ! family ) {
				return;
			}
			var show = isFamily();
			family.hidden = ! show;
			family.disabled = ! show; // Disabled fields are neither validated nor submitted.
		}

		function relabelChildren() {
			if ( ! container ) {
				return;
			}
			var rows = container.querySelectorAll( '.evmf-child' );
			rows.forEach( function ( row, i ) {
				var labels = row.querySelectorAll( 'label' );
				if ( labels[ 0 ] ) {
					labels[ 0 ].textContent = sprintf( t.childName, i + 1 );
				}
				if ( labels[ 1 ] ) {
					labels[ 1 ].textContent = sprintf( t.childDob, i + 1 );
				}
			} );
			if ( addBtn ) {
				addBtn.disabled = rows.length >= cfg.maxChildren;
			}
		}

		if ( typeSelect ) {
			typeSelect.addEventListener( 'change', toggleFamily );
			toggleFamily();
		}

		if ( addBtn && container && tpl ) {
			addBtn.addEventListener( 'click', function () {
				if ( container.querySelectorAll( '.evmf-child' ).length >= cfg.maxChildren ) {
					return;
				}
				var html = tpl.innerHTML
					.replace( /__i__/g, String( nextIndex++ ) )
					.replace( '__name_label__', '' )
					.replace( '__dob_label__', '' );
				container.insertAdjacentHTML( 'beforeend', html );
				relabelChildren();
				var rows = container.querySelectorAll( '.evmf-child' );
				var last = rows[ rows.length - 1 ];
				if ( last ) {
					last.querySelector( 'input' ).focus();
				}
			} );
			container.addEventListener( 'click', function ( e ) {
				var btn = e.target.closest( '.evmf-remove-child' );
				if ( btn ) {
					btn.closest( '.evmf-child' ).remove();
					relabelChildren();
				}
			} );
			relabelChildren();
		}

		if ( iban ) {
			iban.addEventListener( 'input', function () {
				var atEnd = iban.selectionStart === iban.value.length;
				iban.value = formatIban( iban.value );
				if ( atEnd ) {
					iban.setSelectionRange( iban.value.length, iban.value.length );
				}
				iban.setCustomValidity( '' );
			} );
			iban.addEventListener( 'blur', function () {
				iban.setCustomValidity( iban.value && ! ibanIsValid( iban.value ) ? t.invalidIban : '' );
			} );
		}

		if ( bic ) {
			bic.addEventListener( 'input', function () {
				bic.value = bic.value.toUpperCase().replace( /\s/g, '' );
				bic.setCustomValidity( '' );
			} );
			bic.addEventListener( 'blur', function () {
				var ok = ! bic.value || /^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/.test( bic.value );
				bic.setCustomValidity( ok ? '' : t.invalidBic );
			} );
		}

		if ( dob && cfg.minAge > 0 ) {
			dob.addEventListener( 'change', function () {
				var tooYoung = dob.value && ageOn( dob.value ) < cfg.minAge;
				dob.setCustomValidity( tooYoung ? sprintf( t.tooYoung, cfg.minAge ) : '' );
			} );
		}

		// The submit event only fires once native validation passed.
		form.addEventListener( 'submit', function ( e ) {
			if ( iban && iban.value && ! ibanIsValid( iban.value ) ) {
				e.preventDefault();
				iban.setCustomValidity( t.invalidIban );
				iban.reportValidity();
				return;
			}
			if ( form.querySelector( '.g-recaptcha' ) && window.grecaptcha && ! window.grecaptcha.getResponse() ) {
				e.preventDefault();
				window.alert( t.captcha );
				return;
			}
			if ( submit ) {
				submit.disabled = true;
				submit.textContent = t.submitting;
			}
		} );

		var notice = form.parentNode.querySelector( '.evmf-notice--error' );
		if ( notice ) {
			notice.focus();
		}
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		document.querySelectorAll( '.evmf-form' ).forEach( init );
	} );
} )();
