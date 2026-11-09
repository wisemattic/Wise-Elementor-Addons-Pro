/**
 * Wise Table of Contents.
 *
 * Builds the list from the headings in the chosen area. Headings without an id get a
 * unique slug id; existing ids are kept. Links scroll with an offset (setting plus the
 * WordPress toolbar), move focus to the heading and update the address bar. Scroll spy
 * marks the current heading. In the editor the list is rebuilt when the page changes.
 */
( function ( window, document ) {
	'use strict';

	var WiseAddons = window.WiseAddons;
	if ( ! WiseAddons || ! WiseAddons.onWidget ) {
		return;
	}

	var CLEANUP = '__weaTocCleanup';
	var IGNORE = [
		'.wea-table-of-contents',
		'.wea-toc-exclude',
		'[data-wea-toc-exclude]',
		'.screen-reader-text',
		'.elementor-screen-only',
		'.wea-sr-only',
		'.sr-only',
		'#wpadminbar',
		'.elementor-add-section',
		'.elementor-element-overlay',
		'template',
	].join( ',' );

	/* Helpers ---------------------------------------------------------------- */

	function safeClosest( node, selector ) {
		if ( ! selector ) {
			return null;
		}
		try {
			return node.closest( selector );
		} catch ( e ) {
			return null; // Invalid selector typed by the user.
		}
	}

	/**
	 * The valid part of a user-typed selector list. One typo should not switch off the rest.
	 */
	function validSelector( selector ) {
		selector = String( selector || '' ).trim();
		if ( ! selector ) {
			return '';
		}
		try {
			document.createDocumentFragment().querySelector( selector );
			return selector;
		} catch ( e ) {
			return selector.split( ',' ).map( function ( part ) {
				return part.trim();
			} ).filter( function ( part ) {
				try {
					return part && ( document.createDocumentFragment().querySelector( part ), true );
				} catch ( err ) {
					return false;
				}
			} ).join( ',' );
		}
	}

	function findRoot( selectors ) {
		for ( var i = 0; i < selectors.length; i++ ) {
			try {
				var node = document.querySelector( selectors[ i ] );
				if ( node ) {
					return node;
				}
			} catch ( e ) {
				// Invalid selector: try the next one.
			}
		}
		return null;
	}

	function headingText( heading ) {
		return ( heading.textContent || '' ).replace( /\s+/g, ' ' ).trim();
	}

	var unicodeOk = ( function () {
		try {
			return 'é' === 'é'.replace( new RegExp( '[^\\p{L}]', 'gu' ), '' );
		} catch ( e ) {
			return false;
		}
	}() );

	function slugify( text ) {
		var slug = String( text ).toLowerCase();
		if ( slug.normalize ) {
			slug = slug.normalize( 'NFKC' );
		}
		slug = unicodeOk
			? slug.replace( new RegExp( '[^\\p{L}\\p{M}\\p{N}\\s_-]+', 'gu' ), '' )
			: slug.replace( /[^a-z0-9\s_-]+/g, '' );
		slug = slug.trim().replace( /[\s_-]+/g, '-' ).replace( /^-+|-+$/g, '' );
		slug = Array.from( slug ).slice( 0, 60 ).join( '' ).replace( /-+$/, '' );
		return slug || 'section';
	}

	function uniqueId( base, heading, used ) {
		var id = base;
		var n = 2;
		while ( used[ id ] || ( document.getElementById( id ) && document.getElementById( id ) !== heading ) ) {
			id = base + '-' + n;
			n++;
		}
		used[ id ] = true;
		return id;
	}

	function currentDevice() {
		var fe = window.elementorFrontend;
		if ( fe && fe.getCurrentDeviceMode ) {
			try {
				return fe.getCurrentDeviceMode();
			} catch ( e ) {
				// Fall through.
			}
		}
		var w = window.innerWidth;
		return w < 768 ? 'mobile' : ( w < 1025 ? 'tablet' : 'desktop' );
	}

	/* Widget ------------------------------------------------------------------ */

	function setup( el ) {
		if ( 'function' === typeof el[ CLEANUP ] ) {
			el[ CLEANUP ]();
		}

		var nav = el.querySelector( '.wea-table-of-contents' );
		if ( ! nav ) {
			return;
		}
		var opts = WiseAddons.data( nav, 'wea-toc', {} ) || {};
		opts.exclude = validSelector( opts.exclude );
		opts.root = Array.isArray( opts.root ) ? opts.root.slice() : [ 'body' ];
		if ( 'content' === opts.scope ) {
			// The page's own post ID from the body class: right even when the widget's HTML is cached in a template.
			var postId = ( document.body.className.match( /(?:^|\s)(?:postid|page-id)-(\d+)(?:\s|$)/ ) || [] )[ 1 ];
			if ( postId ) {
				opts.root.unshift( '.elementor-' + postId + '[data-elementor-type]' );
			}
		}
		var body = nav.querySelector( '.wea-table-of-contents__body' );
		var toggle = nav.querySelector( '.wea-table-of-contents__toggle' );
		var header = nav.querySelector( '.wea-table-of-contents__header' );
		var empty = nav.querySelector( '.wea-table-of-contents__empty' );
		var iconTemplate = nav.querySelector( '.wea-table-of-contents__icon-template' );
		var editMode = WiseAddons.isEditMode();
		var levels = ( opts.levels || [ 2, 3, 4 ] ).filter( function ( n ) {
			return n >= 1 && n <= 6;
		} );
		var listeners = [];
		var items = [];
		var activeItem = null;
		var frame = 0;
		var observer = null;
		var rebuildTimer = 0;

		function on( target, type, fn, options ) {
			target.addEventListener( type, fn, options );
			listeners.push( function () {
				target.removeEventListener( type, fn, options );
			} );
		}

		/* Offset ---------------------------------------------------------- */

		function offset() {
			var value = parseFloat( window.getComputedStyle( nav ).getPropertyValue( '--wea-toc-offset' ) ) || 0;
			var bar = document.getElementById( 'wpadminbar' );
			if ( bar && 'fixed' === window.getComputedStyle( bar ).position ) {
				value += bar.offsetHeight;
			}
			// Themes with a sticky header often set scroll-padding-top; native jumps use it too.
			var padding = parseFloat( window.getComputedStyle( document.documentElement ).scrollPaddingTop ) || 0;
			return Math.max( value, padding );
		}

		/* Collect -------------------------------------------------------- */

		function collect() {
			var root = findRoot( opts.root );
			if ( ! root || ! levels.length ) {
				return [];
			}
			var selector = levels.map( function ( n ) {
				return 'h' + n;
			} ).join( ',' );
			var used = {};

			return Array.prototype.filter.call( root.querySelectorAll( selector ), function ( heading ) {
				if ( safeClosest( heading, IGNORE ) || safeClosest( heading, opts.exclude ) ) {
					return false;
				}
				if ( ! heading.getClientRects().length ) {
					return false; // Hidden (closed tab, display: none).
				}
				return '' !== headingText( heading );
			} ).map( function ( heading ) {
				var text = headingText( heading );
				var id = heading.id;
				if ( ! id ) {
					id = uniqueId( slugify( text ), heading, used );
					heading.id = id;
				} else {
					used[ id ] = true;
				}
				return {
					heading: heading,
					level: parseInt( heading.tagName.slice( 1 ), 10 ),
					text: text,
					id: id,
					children: [],
					parent: null,
				};
			} );
		}

		/* Tree and markup -------------------------------------------------- */

		function tree( flatItems ) {
			var rootNode = { level: 0, children: [], parent: null };
			var stack = [ rootNode ];
			flatItems.forEach( function ( item ) {
				while ( stack.length > 1 && stack[ stack.length - 1 ].level >= item.level ) {
					stack.pop();
				}
				var parent = stack[ stack.length - 1 ];
				item.parent = parent === rootNode ? null : parent;
				parent.children.push( item );
				stack.push( item );
			} );
			return rootNode.children;
		}

		function number( nodes, prefix ) {
			nodes.forEach( function ( node, index ) {
				node.path = prefix.concat( index + 1 );
				number( node.children, node.path );
			} );
		}

		function markerFor( item, flatIndex ) {
			var marker = document.createElement( 'span' );
			marker.className = 'wea-table-of-contents__marker';
			marker.setAttribute( 'aria-hidden', 'true' );
			if ( 'numbers' === opts.marker ) {
				marker.textContent = ( 'flat' === opts.hierarchy ? flatIndex + 1 : item.path[ item.path.length - 1 ] ) + '.';
			} else if ( 'nested' === opts.marker ) {
				marker.textContent = item.path.join( '.' ) + '.';
			} else if ( 'icon' === opts.marker && iconTemplate ) {
				marker.appendChild( document.importNode( iconTemplate.content, true ) );
			}
			return marker;
		}

		function makeList( depth ) {
			var ordered = 'numbers' === opts.marker || 'nested' === opts.marker;
			var list = document.createElement( ordered ? 'ol' : 'ul' );
			list.className = 'wea-table-of-contents__list';
			list.setAttribute( 'data-depth', String( depth ) );
			return list;
		}

		function makeItem( item, depth, flatIndex ) {
			var li = document.createElement( 'li' );
			li.className = 'wea-table-of-contents__item wea-table-of-contents__item--h' + item.level;
			li.setAttribute( 'data-depth', String( depth ) );
			var link = document.createElement( 'a' );
			link.className = 'wea-table-of-contents__link';
			link.setAttribute( 'href', '#' + item.id );
			if ( 'none' !== opts.marker ) {
				link.appendChild( markerFor( item, flatIndex ) );
			}
			var text = document.createElement( 'span' );
			text.className = 'wea-table-of-contents__text';
			text.textContent = item.text;
			link.appendChild( text );
			li.appendChild( link );
			item.li = li;
			item.link = link;
			return li;
		}

		function renderNested( nodes, depth ) {
			var list = makeList( depth );
			nodes.forEach( function ( node ) {
				var li = makeItem( node, depth, 0 );
				if ( node.children.length ) {
					li.appendChild( renderNested( node.children, depth + 1 ) );
				}
				list.appendChild( li );
			} );
			return list;
		}

		function renderFlat( flatItems ) {
			var list = makeList( 0 );
			var minLevel = Math.min.apply( null, flatItems.map( function ( i ) {
				return i.level;
			} ) );
			flatItems.forEach( function ( item, index ) {
				list.appendChild( makeItem( item, item.level - minLevel, index ) );
			} );
			return list;
		}

		/* Build ------------------------------------------------------------- */

		function build() {
			items = collect();
			activeItem = null;

			var old = body.querySelectorAll( '.wea-table-of-contents__list, .wea-table-of-contents__placeholder' );
			Array.prototype.forEach.call( old, function ( node ) {
				node.parentNode.removeChild( node );
			} );

			if ( ! items.length ) {
				nav.classList.add( 'is-empty' );
				if ( empty ) {
					empty.hidden = false;
				}
				if ( ! editMode ) {
					el.style.display = 'none';
				}
				return;
			}

			nav.classList.remove( 'is-empty' );
			if ( empty ) {
				empty.hidden = true;
			}
			if ( ! editMode && 'none' === el.style.display ) {
				el.style.display = '';
			}

			var nodes = tree( items );
			number( nodes, [] );
			var list = 'flat' === opts.hierarchy ? renderFlat( items ) : renderNested( nodes, 0 );
			body.insertBefore( list, empty || null );
			spy();
		}

		/* Scrolling --------------------------------------------------------- */

		function reducedMotion() {
			return WiseAddons.reducedMotion ? WiseAddons.reducedMotion() : false;
		}

		function scrollToHeading( heading, smooth ) {
			var top = heading.getBoundingClientRect().top + window.pageYOffset - offset();
			try {
				window.scrollTo( { top: Math.max( 0, top ), behavior: smooth && ! reducedMotion() ? 'smooth' : 'auto' } );
			} catch ( e ) {
				window.scrollTo( 0, Math.max( 0, top ) );
			}
		}

		function focusHeading( heading ) {
			if ( ! heading.hasAttribute( 'tabindex' ) ) {
				heading.setAttribute( 'tabindex', '-1' );
				heading.classList.add( 'wea-toc-target' );
			}
			try {
				heading.focus( { preventScroll: true } );
			} catch ( e ) {
				heading.focus();
			}
		}

		function onListClick( event ) {
			var link = event.target && event.target.closest ? event.target.closest( '.wea-table-of-contents__link' ) : null;
			if ( ! link || ! body.contains( link ) || event.defaultPrevented || event.button > 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey ) {
				return;
			}
			var id = decodeURIComponent( ( link.getAttribute( 'href' ) || '' ).slice( 1 ) );
			var heading = document.getElementById( id );
			if ( ! heading ) {
				return;
			}
			event.preventDefault();
			scrollToHeading( heading, !! opts.smooth );
			focusHeading( heading );
			if ( ! editMode && window.history && window.history.replaceState ) {
				try {
					window.history.replaceState( null, '', '#' + id );
				} catch ( e ) {
					// Not critical.
				}
			}
			items.forEach( function ( item ) {
				if ( item.heading === heading ) {
					setActive( item );
				}
			} );
		}

		/* Scroll spy -------------------------------------------------------- */

		function setActive( item ) {
			if ( item === activeItem ) {
				return;
			}
			if ( activeItem && activeItem.li ) {
				activeItem.li.classList.remove( 'is-active' );
				activeItem.link.removeAttribute( 'aria-current' );
				for ( var p = activeItem.parent; p; p = p.parent ) {
					if ( p.li ) {
						p.li.classList.remove( 'is-active-parent' );
					}
				}
			}
			activeItem = item;
			if ( ! item || ! item.li ) {
				return;
			}
			item.li.classList.add( 'is-active' );
			item.link.setAttribute( 'aria-current', 'location' );
			if ( 'flat' !== opts.hierarchy ) {
				for ( var q = item.parent; q; q = q.parent ) {
					if ( q.li ) {
						q.li.classList.add( 'is-active-parent' );
					}
				}
			}
			// Keep the active link visible when the list scrolls inside a max height.
			if ( body.scrollHeight > body.clientHeight + 2 ) {
				var linkTop = item.link.offsetTop - body.offsetTop;
				if ( item.link.offsetParent !== body ) {
					linkTop = item.link.getBoundingClientRect().top - body.getBoundingClientRect().top + body.scrollTop;
				}
				if ( linkTop < body.scrollTop || linkTop + item.link.offsetHeight > body.scrollTop + body.clientHeight ) {
					body.scrollTop = Math.max( 0, linkTop - body.clientHeight / 3 );
				}
			}
		}

		function spy() {
			frame = 0;
			if ( ! opts.spy || ! items.length ) {
				return;
			}
			var line = offset() + 24;
			var current = null;
			for ( var i = 0; i < items.length; i++ ) {
				if ( items[ i ].heading.getBoundingClientRect().top - line <= 0 ) {
					current = items[ i ];
				} else {
					break;
				}
			}
			var doc = document.documentElement;
			if ( window.pageYOffset > 0 && window.innerHeight + window.pageYOffset >= doc.scrollHeight - 2 ) {
				// At the very bottom the last headings can never reach the top.
				var last = items[ items.length - 1 ];
				if ( last.heading.getBoundingClientRect().top < window.innerHeight ) {
					current = last;
				}
			}
			setActive( current );
		}

		function requestSpy() {
			if ( ! frame ) {
				frame = window.requestAnimationFrame( spy );
			}
		}

		/* Collapse ------------------------------------------------------------ */

		function setExpanded( expanded ) {
			if ( ! toggle ) {
				return;
			}
			toggle.setAttribute( 'aria-expanded', expanded ? 'true' : 'false' );
			body.hidden = ! expanded;
			nav.classList.toggle( 'is-collapsed', ! expanded );
		}

		if ( toggle ) {
			setExpanded( ( opts.collapsedOn || [] ).indexOf( currentDevice() ) === -1 );
			on( toggle, 'click', function ( event ) {
				event.preventDefault();
				setExpanded( 'true' !== toggle.getAttribute( 'aria-expanded' ) );
				if ( 'true' === toggle.getAttribute( 'aria-expanded' ) ) {
					requestSpy();
				}
			} );
			if ( header ) {
				// A click anywhere on the header toggles too (the button stays the keyboard target).
				on( header, 'click', function ( event ) {
					if ( event.target.closest && ( event.target.closest( '.wea-table-of-contents__toggle' ) || event.target.closest( '.elementor-inline-editing' ) ) ) {
						return;
					}
					toggle.click();
				} );
			}
		}

		/* Start ----------------------------------------------------------------- */

		build();
		on( body, 'click', onListClick );
		if ( opts.spy ) {
			on( window, 'scroll', requestSpy, { passive: true } );
			on( window, 'resize', requestSpy, { passive: true } );
		}

		// A shared link to a generated id: the browser could not find it before the ids existed.
		if ( ! editMode && window.location.hash && items.length ) {
			var hashId = '';
			try {
				hashId = decodeURIComponent( window.location.hash.slice( 1 ) );
			} catch ( e ) {
				hashId = '';
			}
			var match = items.filter( function ( item ) {
				return item.id === hashId;
			} )[ 0 ];
			if ( match ) {
				window.setTimeout( function () {
					scrollToHeading( match.heading, false );
				}, 60 );
			}
		}

		// Editor: rebuild when headings are added, removed or edited.
		if ( editMode && 'MutationObserver' in window ) {
			var observed = findRoot( opts.root );
			if ( observed ) {
				observer = new window.MutationObserver( function ( mutations ) {
					if ( ! document.documentElement.contains( nav ) ) {
						cleanup();
						return;
					}
					var relevant = mutations.some( function ( mutation ) {
						var target = 1 === mutation.target.nodeType ? mutation.target : mutation.target.parentElement;
						return target && ! nav.contains( target ) && ! el.contains( target ) && ! target.closest( '.elementor-element-overlay' );
					} );
					if ( relevant ) {
						window.clearTimeout( rebuildTimer );
						rebuildTimer = window.setTimeout( build, 350 );
					}
				} );
				observer.observe( observed, { childList: true, subtree: true, characterData: true } );
			}
		}

		function cleanup() {
			listeners.forEach( function ( off ) {
				off();
			} );
			listeners = [];
			if ( observer ) {
				observer.disconnect();
				observer = null;
			}
			window.clearTimeout( rebuildTimer );
			if ( frame ) {
				window.cancelAnimationFrame( frame );
				frame = 0;
			}
			el[ CLEANUP ] = null;
		}

		el[ CLEANUP ] = cleanup;
	}

	WiseAddons.onWidget( 'wea-table-of-contents', setup );
}( window, document ) );
