/**
 * Wise Live Copy: "Copy" button on containers and sections that allow Live Copy.
 *
 * The element JSON comes from the public route wiseaddons-pro/v1/live-copy/element/<post>/<id>,
 * which only answers for elements switched on in the editor. The text is put on the
 * clipboard, ready for "Wise Paste" (or Elementor's "Paste from other site") on another site.
 *
 * Standalone vanilla JS: no jQuery, no Elementor frontend needed.
 */
( function ( window, document ) {
	'use strict';

	if ( window.WEALiveCopyReady ) {
		return;
	}
	window.WEALiveCopyReady = true;

	var config = window.WiseAddonsLiveCopy || {};
	var i18n = config.i18n || {};
	var SELECTOR = '[data-wea-live-copy]';
	var ACTIVE = 'wea-live-copy--active';
	var requests = {};
	var texts = {};
	var current = null;
	var toastTimer = 0;

	if ( ! config.endpoint ) {
		// Fallback: WordPress prints the REST root in <head>.
		var api = document.querySelector( 'link[rel="https://api.w.org/"]' );
		if ( ! api || ! api.href ) {
			return;
		}
		config.endpoint = api.href + ( /[/=]$/.test( api.href ) ? '' : '/' ) + 'wiseaddons-pro/v1/live-copy/element/';
	}

	function t( key, fallback ) {
		return i18n[ key ] || fallback;
	}

	function keyOf( el ) {
		var post = el.getAttribute( 'data-wea-live-copy' ) || '';
		var id = el.getAttribute( 'data-id' ) || '';
		return /^\d+$/.test( post ) && /^[a-z0-9]{1,20}$/.test( id ) ? post + '/' + id : '';
	}

	/**
	 * Fetch the element JSON once per page view.
	 */
	function load( el ) {
		var key = keyOf( el );
		if ( ! key ) {
			return Promise.reject( new Error( 'invalid' ) );
		}
		if ( ! requests[ key ] ) {
			requests[ key ] = window.fetch( config.endpoint + key, {
				credentials: 'omit',
				headers: { Accept: 'application/json' },
			} ).then( function ( response ) {
				if ( ! response.ok ) {
					throw new Error( 'HTTP ' + response.status );
				}
				return response.text();
			} ).then( function ( text ) {
				var data = JSON.parse( text );
				if ( ! data || ! data.wiseaddons_live_copy || ! Array.isArray( data.elements ) ) {
					throw new Error( 'invalid' );
				}
				texts[ key ] = text;
				return text;
			} );
			requests[ key ].catch( function () {
				delete requests[ key ];
			} );
		}
		return requests[ key ];
	}

	/* Clipboard ---------------------------------------------------------- */

	function copyWithTextarea( text ) {
		var area = document.createElement( 'textarea' );
		area.value = text;
		area.setAttribute( 'readonly', '' );
		area.style.position = 'fixed';
		area.style.top = '-9999px';
		area.style.opacity = '0';
		document.body.appendChild( area );
		area.select();
		var ok = false;
		try {
			ok = document.execCommand( 'copy' );
		} catch ( e ) {
			ok = false;
		}
		document.body.removeChild( area );
		return ok;
	}

	function write( text ) {
		if ( navigator.clipboard && window.isSecureContext && navigator.clipboard.writeText ) {
			return navigator.clipboard.writeText( text ).then( function () {
				return true;
			}, function () {
				return copyWithTextarea( text );
			} );
		}
		return Promise.resolve( copyWithTextarea( text ) );
	}

	/* Feedback ------------------------------------------------------------ */

	function setLabel( button, text, state ) {
		var label = button.querySelector( '.wea-live-copy__label' );
		if ( label ) {
			label.textContent = text;
		}
		button.setAttribute( 'data-state', state || '' );
	}

	function toast( message ) {
		var node = document.querySelector( '.wea-live-copy__toast' );
		if ( ! node ) {
			node = document.createElement( 'div' );
			node.className = 'wea-live-copy__toast';
			node.setAttribute( 'role', 'status' );
			node.setAttribute( 'aria-live', 'polite' );
			document.body.appendChild( node );
		}
		node.textContent = message;
		node.classList.add( 'is-visible' );
		window.clearTimeout( toastTimer );
		toastTimer = window.setTimeout( function () {
			node.classList.remove( 'is-visible' );
		}, 4500 );
	}

	/**
	 * Last resort: show the text so the visitor can copy it by hand.
	 */
	function manual( text, opener ) {
		var overlay = document.createElement( 'div' );
		overlay.className = 'wea-live-copy__dialog';
		var box = document.createElement( 'div' );
		box.className = 'wea-live-copy__dialog-box';
		box.setAttribute( 'role', 'dialog' );
		box.setAttribute( 'aria-modal', 'true' );
		box.setAttribute( 'aria-label', t( 'dialog', 'Copy section' ) );
		var note = document.createElement( 'p' );
		note.textContent = t( 'manual', 'Copy the text below with Ctrl+C.' );
		var area = document.createElement( 'textarea' );
		area.readOnly = true;
		area.value = text;
		area.rows = 6;
		area.setAttribute( 'aria-label', t( 'dialog', 'Copy section' ) );
		var close = document.createElement( 'button' );
		close.type = 'button';
		close.className = 'wea-live-copy__dialog-close';
		close.textContent = t( 'close', 'Close' );

		function done() {
			document.removeEventListener( 'keydown', onKey, true );
			if ( overlay.parentNode ) {
				overlay.parentNode.removeChild( overlay );
			}
			if ( opener && opener.focus ) {
				opener.focus();
			}
		}
		function onKey( event ) {
			if ( 'Escape' === event.key ) {
				done();
			} else if ( 'Tab' === event.key ) {
				event.preventDefault();
				( document.activeElement === area ? close : area ).focus();
			}
		}

		close.addEventListener( 'click', done );
		overlay.addEventListener( 'mousedown', function ( event ) {
			if ( event.target === overlay ) {
				done();
			}
		} );
		document.addEventListener( 'keydown', onKey, true );

		box.appendChild( note );
		box.appendChild( area );
		box.appendChild( close );
		overlay.appendChild( box );
		document.body.appendChild( overlay );
		area.focus();
		area.select();
	}

	/* Copy ------------------------------------------------------------------ */

	function finish( button, ok, text ) {
		if ( ok ) {
			setLabel( button, t( 'copied', 'Copied!' ), 'copied' );
			toast( t( 'hint', 'Copied.' ) );
		} else if ( text ) {
			setLabel( button, t( 'copy', 'Copy' ), '' );
			manual( text, button );
			return;
		} else {
			setLabel( button, t( 'copy', 'Copy' ), 'error' );
			toast( t( 'failed', 'Copy failed. Please try again.' ) );
		}
		window.setTimeout( function () {
			setLabel( button, t( 'copy', 'Copy' ), '' );
		}, 2200 );
	}

	function copy( el, button ) {
		if ( 'copying' === button.getAttribute( 'data-state' ) ) {
			return;
		}
		var key = keyOf( el );
		setLabel( button, t( 'copying', 'Copying…' ), 'copying' );

		// Ready: write at once, while the click still counts as a user gesture.
		if ( texts[ key ] ) {
			var ready = texts[ key ];
			write( ready ).then( function ( ok ) {
				finish( button, ok, ready );
			} );
			return;
		}

		// Safari only keeps the gesture when the clipboard item is a promise.
		if ( window.ClipboardItem && navigator.clipboard && navigator.clipboard.write && window.isSecureContext ) {
			var blob = load( el ).then( function ( text ) {
				return new Blob( [ text ], { type: 'text/plain' } );
			} );
			var item;
			try {
				item = new window.ClipboardItem( { 'text/plain': blob } );
			} catch ( e ) {
				item = null;
			}
			if ( item ) {
				navigator.clipboard.write( [ item ] ).then( function () {
					finish( button, true );
				}, function () {
					load( el ).then( function ( text ) {
						finish( button, copyWithTextarea( text ), text );
					}, function () {
						finish( button, false );
					} );
				} );
				return;
			}
		}

		load( el ).then( function ( text ) {
			return write( text ).then( function ( ok ) {
				finish( button, ok, text );
			} );
		}, function () {
			finish( button, false );
		} );
	}

	/* Buttons --------------------------------------------------------------- */

	function icon() {
		var ns = 'http://www.w3.org/2000/svg';
		var svg = document.createElementNS( ns, 'svg' );
		svg.setAttribute( 'viewBox', '0 0 24 24' );
		svg.setAttribute( 'aria-hidden', 'true' );
		svg.setAttribute( 'focusable', 'false' );
		var a = document.createElementNS( ns, 'rect' );
		a.setAttribute( 'x', '8' );
		a.setAttribute( 'y', '8' );
		a.setAttribute( 'width', '12' );
		a.setAttribute( 'height', '12' );
		a.setAttribute( 'rx', '2' );
		var b = document.createElementNS( ns, 'path' );
		b.setAttribute( 'd', 'M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2' );
		[ a, b ].forEach( function ( node ) {
			node.setAttribute( 'fill', 'none' );
			node.setAttribute( 'stroke', 'currentColor' );
			node.setAttribute( 'stroke-width', '2' );
			svg.appendChild( node );
		} );
		return svg;
	}

	function setup( el ) {
		if ( el.__weaLiveCopy || ! keyOf( el ) ) {
			return;
		}
		el.__weaLiveCopy = true;

		var button = document.createElement( 'button' );
		button.type = 'button';
		button.className = 'wea-live-copy__button';
		button.setAttribute( 'aria-label', t( 'label', 'Copy this section' ) );
		var label = document.createElement( 'span' );
		label.className = 'wea-live-copy__label';
		label.textContent = t( 'copy', 'Copy' );
		button.appendChild( icon() );
		button.appendChild( label );

		button.addEventListener( 'click', function ( event ) {
			event.preventDefault();
			event.stopPropagation();
			copy( el, button );
		} );
		// Fetch while the pointer approaches, so the click can write at once.
		var warm = function () {
			load( el ).catch( function () {} );
		};
		button.addEventListener( 'pointerenter', warm );
		button.addEventListener( 'focus', warm );

		// Last child: never changes :first-child rules of the element's content.
		el.appendChild( button );
	}

	function activate( el ) {
		if ( current === el ) {
			return;
		}
		if ( current ) {
			current.classList.remove( ACTIVE );
		}
		current = el;
		if ( el ) {
			el.classList.add( ACTIVE );
		}
	}

	function onPointer( event ) {
		var target = event.target;
		if ( target && 1 !== target.nodeType ) {
			target = target.parentElement;
		}
		// Only the innermost copyable element shows its button.
		activate( target && target.closest ? target.closest( SELECTOR ) : null );
	}

	function init() {
		var nodes = document.querySelectorAll( SELECTOR );
		Array.prototype.forEach.call( nodes, setup );
		if ( nodes.length ) {
			document.addEventListener( 'mouseover', onPointer, { passive: true } );
			document.documentElement.addEventListener( 'mouseleave', function () {
				activate( null );
			} );
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}( window, document ) );
