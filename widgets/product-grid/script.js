/**
 * Wise Product Grid (Pro): "Load more".
 *
 * Uses the admin-ajax endpoint when the page says it is available, otherwise
 * fetches the next page URL (the button's href) and takes the new cards from it.
 * Either way the server renders the cards from the element's saved settings.
 */
( function () {
	'use strict';

	var NAME = 'wea-product-grid';

	function format( text, n ) {
		return String( text || '' ).replace( '%d', n );
	}

	/**
	 * Cards from the admin-ajax endpoint.
	 */
	function viaAjax( cfg, page ) {
		var body = new FormData();
		body.append( 'action', cfg.action );
		body.append( 'nonce', cfg.nonce );
		body.append( 'post_id', cfg.postId );
		body.append( 'element_id', cfg.elementId );
		body.append( 'page', page );

		return fetch( cfg.ajax, { method: 'POST', body: body, credentials: 'same-origin' } )
			.then( function ( res ) {
				return res.ok ? res.json() : Promise.reject( res.status );
			} )
			.then( function ( json ) {
				if ( ! json || ! json.success ) {
					return Promise.reject( 'error' );
				}
				var tpl = document.createElement( 'template' );
				tpl.innerHTML = json.data.html;
				return {
					items: Array.prototype.slice.call( tpl.content.children ),
					hasMore: !! json.data.hasMore,
					next: null
				};
			} );
	}

	/**
	 * Cards from the next page of the current URL.
	 */
	function viaPage( cfg, url ) {
		return fetch( url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'wea-product-grid' } } )
			.then( function ( res ) {
				return res.ok ? res.text() : Promise.reject( res.status );
			} )
			.then( function ( text ) {
				var doc = new DOMParser().parseFromString( text, 'text/html' );
				var scope = doc.querySelector( '.elementor-element-' + cfg.elementId );
				var list = scope && scope.querySelector( '.wea-product-grid__items' );
				if ( ! list ) {
					return Promise.reject( 'missing' );
				}
				var next = scope.querySelector( '.wea-product-grid__more' );
				return {
					items: Array.prototype.map.call( list.children, function ( node ) {
						return document.importNode( node, true );
					} ),
					hasMore: !! next,
					next: next ? next.getAttribute( 'href' ) : null
				};
			} );
	}

	function setup( root ) {
		var button = root.querySelector( '.wea-product-grid__more' );
		var list = root.querySelector( '.wea-product-grid__items' );
		var status = root.querySelector( '.wea-product-grid__status' );
		var state = { events: [] };

		if ( ! button || ! list ) {
			return state;
		}

		var cfg = WiseAddons.data( button, 'wea-load-more', {} );
		var page = parseInt( cfg.page, 10 ) || 1;
		var busy = false;

		button.setAttribute( 'role', 'button' );

		function announce( text ) {
			if ( status ) {
				status.textContent = '';
				window.setTimeout( function () {
					status.textContent = text;
				}, 50 );
			}
		}

		function finish( result ) {
			var first = null;
			result.items.forEach( function ( node ) {
				if ( node.nodeType !== 1 ) {
					return;
				}
				if ( ! WiseAddons.reducedMotion() ) {
					node.classList.add( 'is-new' );
				}
				list.appendChild( node );
				first = first || node;
			} );

			page += 1;
			if ( result.next ) {
				button.setAttribute( 'href', result.next );
			} else {
				try {
					var url = new URL( button.getAttribute( 'href' ), window.location.href );
					url.searchParams.set( 'wea-pg-' + cfg.elementId, page + 1 );
					button.setAttribute( 'href', url.toString() );
				} catch ( e ) {}
			}

			// Let WooCommerce and other scripts know new products arrived.
			if ( window.jQuery ) {
				window.jQuery( document.body ).trigger( 'wea-product-grid:loaded', [ root, result.items ] );
			}

			var more = result.hasMore && ( ! cfg.pages || page < cfg.pages );
			if ( first ) {
				var link = first.querySelector( '.wea-product-grid__title a' ) || first.querySelector( 'a[href]:not([tabindex="-1"])' );
				if ( link ) {
					link.focus( { preventScroll: true } );
				}
			}
			announce( format( cfg.loaded, result.items.length ) + ( more ? '' : ' ' + ( cfg.done || '' ) ) );

			if ( ! more ) {
				var wrap = button.parentNode;
				( wrap && wrap.classList.contains( 'wea-product-grid__load-more' ) ? wrap : button ).remove();
			}
		}

		function load( event ) {
			if ( event ) {
				event.preventDefault();
			}
			if ( busy ) {
				return;
			}
			busy = true;
			button.setAttribute( 'aria-busy', 'true' );
			button.textContent = cfg.loading || cfg.text;

			var next = page + 1;
			var href = button.getAttribute( 'href' );
			var request = cfg.ajax
				? viaAjax( cfg, next ).catch( function () {
					return viaPage( cfg, href );
				} )
				: viaPage( cfg, href );

			request.then( finish, function () {
				announce( cfg.failed || '' );
			} ).then( function () {
				busy = false;
				button.removeAttribute( 'aria-busy' );
				button.textContent = cfg.text;
			} );
		}

		function onKey( event ) {
			if ( ' ' === event.key ) {
				load( event );
			}
		}

		button.addEventListener( 'click', load );
		button.addEventListener( 'keydown', onKey );
		state.events.push( [ button, 'click', load ], [ button, 'keydown', onKey ] );

		return state;
	}

	function destroy( state ) {
		if ( state ) {
			state.events.forEach( function ( e ) {
				e[ 0 ].removeEventListener( e[ 1 ], e[ 2 ] );
			} );
		}
	}

	WiseAddons.onWidget( NAME, function ( el ) {
		destroy( el.__weaProductGrid );
		el.__weaProductGrid = null;
		var root = el.querySelector( '.wea-product-grid' );
		if ( root ) {
			el.__weaProductGrid = setup( root );
		}
	} );
}() );
