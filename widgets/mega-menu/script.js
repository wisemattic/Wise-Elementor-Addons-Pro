/**
 * Wise Mega Menu: dropdowns and mega panels that open on hover (with intent delay)
 * or click, keyboard support (arrows, Home/End, Space, Esc), and below the breakpoint
 * an off-canvas or dropdown menu whose sub-menus open as accordions.
 * The same server-rendered markup serves both modes.
 */
( function ( window, document ) {
	'use strict';

	var WiseAddons = window.WiseAddons;
	if ( ! WiseAddons || ! WiseAddons.onWidget ) {
		return;
	}

	var CLEANUP = '__weaMegaMenu';
	var LOCK_CLASS = 'wea-mega-menu-lock';
	var FOCUSABLE = 'a[href], area[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), iframe, [tabindex]:not([tabindex="-1"]), [contenteditable="true"]';
	var locks = 0;

	// One page-wide scroll lock shared by every menu on the page.
	function lockScroll() {
		locks++;
		document.documentElement.classList.add( LOCK_CLASS );
	}

	function unlockScroll() {
		locks = Math.max( 0, locks - 1 );
		if ( ! locks ) {
			document.documentElement.classList.remove( LOCK_CLASS );
		}
	}

	function childByClass( el, cls ) {
		if ( ! el ) {
			return null;
		}
		for ( var i = 0; i < el.children.length; i++ ) {
			if ( el.children[ i ].classList.contains( cls ) ) {
				return el.children[ i ];
			}
		}
		return null;
	}

	function isVisible( el ) {
		return !! ( el && ( el.offsetWidth || el.offsetHeight || el.getClientRects().length ) );
	}

	function focusables( container ) {
		return Array.prototype.filter.call( container.querySelectorAll( FOCUSABLE ), function ( el ) {
			return isVisible( el ) && window.getComputedStyle( el ).visibility !== 'hidden';
		} );
	}

	function setup( root ) {
		var opts = WiseAddons.data( root, 'wea-mega-menu', {} ) || {};
		var panel = childByClass( root, 'wea-mega-menu__panel' );
		var nav = childByClass( panel, 'wea-mega-menu__nav' );
		var menu = childByClass( nav, 'wea-mega-menu__menu' );

		if ( ! menu ) {
			return function () {};
		}

		var toggle = childByClass( root, 'wea-mega-menu__toggle' );
		var overlay = childByClass( root, 'wea-mega-menu__overlay' );
		var closeBtn = childByClass( childByClass( panel, 'wea-mega-menu__panel-head' ), 'wea-mega-menu__close' );

		var bp = parseInt( opts.bp, 10 ) || 0;
		var hover = opts.trigger !== 'click';
		var vertical = opts.layout === 'vertical';
		var offcanvas = opts.mobile === 'offcanvas';
		var openDelay = typeof opts.openDelay === 'number' ? opts.openDelay : 120;
		var closeDelay = typeof opts.closeDelay === 'number' ? opts.closeDelay : 300;
		var animated = opts.animation !== 'none';

		var listeners = [];
		var items = [];
		var byLi = new WeakMap();
		var mobile = false;
		var panelOpen = false;
		var panelOpened = false;
		var locked = false;
		var lastPointer = 'mouse';
		var pinned = null;
		var frame = 0;
		var mq = null;

		function on( target, type, fn, options ) {
			if ( ! target ) {
				return;
			}
			target.addEventListener( type, fn, options || false );
			listeners.push( function () {
				target.removeEventListener( type, fn, options || false );
			} );
		}

		// Item models, in document order. Items of another mega menu inside a template are skipped.
		Array.prototype.forEach.call( menu.querySelectorAll( 'li.wea-mega-menu__item' ), function ( li ) {
			if ( li.closest( '.wea-mega-menu' ) !== root ) {
				return;
			}
			var row = childByClass( li, 'wea-mega-menu__row' );
			var item = {
				li: li,
				link: childByClass( row, 'wea-mega-menu__link' ),
				arrow: childByClass( row, 'wea-mega-menu__arrow' ),
				sub: childByClass( li, 'wea-mega-menu__sub' ),
				mega: childByClass( li, 'wea-mega-menu__mega' ),
				top: li.classList.contains( 'wea-mega-menu__item--top' ),
				parent: null,
				timer: 0,
				closing: 0,
				openedAt: 0,
				opener: null,
				seen: false
			};
			byLi.set( li, item );
			items.push( item );
		} );

		items.forEach( function ( item ) {
			var up = item.li.parentElement ? item.li.parentElement.closest( 'li.wea-mega-menu__item' ) : null;
			item.parent = up && byLi.has( up ) ? byLi.get( up ) : null;
		} );

		function isRtl() {
			return window.getComputedStyle( root ).direction === 'rtl';
		}

		function cssPx( el, name ) {
			var value = parseFloat( window.getComputedStyle( el ).getPropertyValue( name ) );
			return isNaN( value ) ? 0 : value;
		}

		function popupOf( item ) {
			if ( item.mega && ( ! mobile || opts.megaMobile ) ) {
				return item.mega;
			}
			return item.sub;
		}

		function isOpen( item ) {
			return item.li.classList.contains( 'is-open' ) && ! item.li.classList.contains( 'is-closing' );
		}

		function isInside( item, ancestor ) {
			for ( var p = item.parent; p; p = p.parent ) {
				if ( p === ancestor ) {
					return true;
				}
			}
			return false;
		}

		function level( item ) {
			return items.filter( function ( other ) {
				return other.parent === item.parent && other.link && isVisible( other.link );
			} );
		}

		// Width of the boxed container that holds the menu, for "Container width" panels.
		function containerEl() {
			var inner = root.closest( '.e-con-inner, .elementor-container' );
			if ( inner ) {
				return inner;
			}
			var outer = null;
			for ( var node = root.parentElement; node; node = node.parentElement ) {
				if ( node.classList && ( node.classList.contains( 'e-con' ) || node.classList.contains( 'elementor-section' ) ) ) {
					outer = node;
				}
			}
			return outer || root;
		}

		function placeSub( item ) {
			var sub = item.sub;
			sub.classList.remove( 'is-flip' );
			var rect = sub.getBoundingClientRect();
			var vw = document.documentElement.clientWidth;
			if ( rect.right > vw - 4 || rect.left < 4 ) {
				sub.classList.add( 'is-flip' );
			}
		}

		function placeMega( item ) {
			var mega = item.mega;
			var mode = mega.getAttribute( 'data-width' ) || 'container';
			var align = mega.getAttribute( 'data-align' ) || 'start';
			var vw = document.documentElement.clientWidth;
			var base = menu.getBoundingClientRect();
			var rtl = isRtl();
			var x;
			var w = null;
			var rect;

			mega.style.removeProperty( '--wea-mm-w' );

			if ( vertical ) {
				var gap = cssPx( root, '--wea-mm-mega-offset' );
				if ( mode === 'custom' ) {
					w = Math.min( mega.offsetWidth, vw );
				} else if ( mode === 'full' ) {
					w = rtl ? base.left - gap : vw - base.right - gap;
				} else {
					rect = containerEl().getBoundingClientRect();
					w = rtl ? base.left - rect.left - gap : rect.right - base.right - gap;
				}
				w = Math.max( 240, w );
				x = rtl ? -w - gap : base.width + gap;
				mega.style.setProperty( '--wea-mm-x', x + 'px' );
				if ( mode !== 'custom' ) {
					mega.style.setProperty( '--wea-mm-w', w + 'px' );
				}
				return;
			}

			if ( mode === 'full' ) {
				x = -base.left;
				w = vw;
			} else if ( mode === 'container' ) {
				rect = containerEl().getBoundingClientRect();
				x = rect.left - base.left;
				w = rect.width;
			} else {
				var width = Math.min( mega.offsetWidth, vw );
				var at = item.li.getBoundingClientRect();
				var left;
				if ( align === 'center' ) {
					left = at.left + at.width / 2 - width / 2;
				} else if ( ( align === 'start' ) !== rtl ) {
					left = at.left;
				} else {
					left = at.right - width;
				}
				// Keep a small gap from the screen edges when the panel fits with it.
				var edge = width + 32 <= vw ? 16 : 0;
				left = Math.max( edge, Math.min( left, vw - width - edge ) );
				x = left - base.left;
			}

			mega.style.setProperty( '--wea-mm-x', x + 'px' );
			if ( w !== null ) {
				mega.style.setProperty( '--wea-mm-w', w + 'px' );
			}
		}

		function place( item ) {
			var pop = popupOf( item );
			if ( ! pop || mobile ) {
				return;
			}
			if ( pop === item.mega ) {
				placeMega( item );
			} else {
				placeSub( item );
			}
		}

		function placePanel() {
			if ( ! panel || ! mobile || offcanvas ) {
				return;
			}
			if ( opts.full ) {
				var rect = root.getBoundingClientRect();
				panel.style.setProperty( '--wea-mm-px', -rect.left + 'px' );
				panel.style.setProperty( '--wea-mm-pw', document.documentElement.clientWidth + 'px' );
			} else {
				panel.style.removeProperty( '--wea-mm-px' );
				panel.style.removeProperty( '--wea-mm-pw' );
			}
		}

		function finishClosing( item ) {
			if ( item.closing ) {
				window.clearTimeout( item.closing );
				item.closing = 0;
			}
			if ( item.li.classList.contains( 'is-closing' ) ) {
				item.li.classList.remove( 'is-closing', 'is-open' );
			}
		}

		function close( item, instant ) {
			window.clearTimeout( item.timer );
			item.timer = 0;
			if ( ! item.li.classList.contains( 'is-open' ) ) {
				return;
			}
			if ( pinned === item ) {
				pinned = null;
			}
			items.forEach( function ( other ) {
				if ( isInside( other, item ) ) {
					close( other, true );
				}
			} );
			if ( item.arrow ) {
				item.arrow.setAttribute( 'aria-expanded', 'false' );
			}

			var pop = popupOf( item );
			if ( instant || mobile || ! animated || ! pop || WiseAddons.reducedMotion() ) {
				finishClosing( item );
				item.li.classList.remove( 'is-open' );
				return;
			}
			if ( item.li.classList.contains( 'is-closing' ) ) {
				return;
			}
			item.li.classList.add( 'is-closing' );
			var done = function ( event ) {
				if ( event && event.target !== pop ) {
					return;
				}
				pop.removeEventListener( 'animationend', done );
				finishClosing( item );
			};
			pop.addEventListener( 'animationend', done );
			item.closing = window.setTimeout( done, 600 );
		}

		function closeAll( instant ) {
			items.forEach( function ( item ) {
				if ( item.top && item !== pinned ) {
					close( item, instant );
				}
			} );
		}

		function open( item, focus ) {
			var pop = popupOf( item );
			if ( ! pop ) {
				return false;
			}
			window.clearTimeout( item.timer );
			item.timer = 0;
			items.forEach( function ( other ) {
				if ( other !== item && other.parent === item.parent ) {
					close( other, true );
				}
			} );
			finishClosing( item );

			if ( ! item.li.classList.contains( 'is-open' ) ) {
				item.li.classList.add( 'is-open' );
				item.openedAt = Date.now();
				if ( item.arrow ) {
					item.arrow.setAttribute( 'aria-expanded', 'true' );
				}
				place( item );
				// Carousels and other widgets in a template measure themselves once visible.
				if ( ! item.seen ) {
					item.seen = true;
					if ( pop === item.mega && pop.querySelector( '.elementor-element' ) ) {
						window.dispatchEvent( new Event( 'resize' ) );
					}
				}
			}

			if ( focus ) {
				var list = focusables( pop );
				if ( list.length ) {
					list[ focus === 'last' ? list.length - 1 : 0 ].focus();
				}
			}
			return true;
		}

		function toggleItem( item ) {
			if ( isOpen( item ) ) {
				close( item );
			} else {
				open( item );
			}
		}

		function focusItem( item ) {
			if ( item && item.link ) {
				item.link.focus();
			}
		}

		function returnFocus( item ) {
			var target = item.opener && isVisible( item.opener ) ? item.opener : item.link;
			if ( target ) {
				target.focus();
			}
		}

		/* Mobile panel */

		function openPanel() {
			if ( panelOpen || ! mobile || ! panel ) {
				return;
			}
			panelOpen = true;
			root.classList.add( 'is-panel-open' );
			if ( toggle ) {
				toggle.setAttribute( 'aria-expanded', 'true' );
			}

			// First time: open the accordions that lead to the current page.
			if ( ! panelOpened ) {
				panelOpened = true;
				items.forEach( function ( item ) {
					if ( item.li.classList.contains( 'is-current-ancestor' ) && popupOf( item ) ) {
						open( item );
					}
				} );
			}

			if ( offcanvas ) {
				lockScroll();
				locked = true;
				var target = closeBtn || focusables( panel )[ 0 ];
				if ( target ) {
					window.requestAnimationFrame( function () {
						target.focus( { preventScroll: true } );
					} );
				}
			} else {
				placePanel();
			}
		}

		function closePanel( focusToggle ) {
			if ( ! panelOpen ) {
				return;
			}
			panelOpen = false;
			root.classList.remove( 'is-panel-open' );
			if ( toggle ) {
				toggle.setAttribute( 'aria-expanded', 'false' );
			}
			if ( locked ) {
				unlockScroll();
				locked = false;
			}
			if ( focusToggle && toggle && isVisible( toggle ) ) {
				toggle.focus();
			}
		}

		function trapFocus( event ) {
			var list = focusables( panel );
			if ( ! list.length ) {
				event.preventDefault();
				return;
			}
			var first = list[ 0 ];
			var last = list[ list.length - 1 ];
			var active = document.activeElement;
			if ( event.shiftKey && ( active === first || ! panel.contains( active ) ) ) {
				event.preventDefault();
				last.focus();
			} else if ( ! event.shiftKey && ( active === last || ! panel.contains( active ) ) ) {
				event.preventDefault();
				first.focus();
			}
		}

		function setMode( isMobile ) {
			closeAll( true );
			closePanel( false );
			mobile = isMobile;
			root.classList.toggle( 'is-mobile', mobile );

			if ( panel ) {
				if ( mobile && offcanvas ) {
					panel.setAttribute( 'role', 'dialog' );
					panel.setAttribute( 'aria-modal', 'true' );
					panel.setAttribute( 'aria-label', opts.label || 'Menu' );
				} else {
					panel.removeAttribute( 'role' );
					panel.removeAttribute( 'aria-modal' );
					panel.removeAttribute( 'aria-label' );
				}
			}

			// Mega items show their normal sub-menu on mobile when panels are off there.
			items.forEach( function ( item ) {
				var pop = popupOf( item );
				if ( item.arrow && pop && pop.id ) {
					item.arrow.setAttribute( 'aria-controls', pop.id );
				}
			} );
		}

		/* Events */

		function onLinkToThisPage( link ) {
			if ( ! link || ! link.hash || link.pathname !== window.location.pathname || link.host !== window.location.host ) {
				return;
			}
			closeAll( true );
			closePanel( false );
		}

		on( root, 'pointerdown', function ( event ) {
			lastPointer = event.pointerType || 'mouse';
		} );

		on( menu, 'click', function ( event ) {
			var target = event.target;
			if ( ! target || ! target.closest ) {
				return;
			}
			var arrow = target.closest( '.wea-mega-menu__arrow' );
			var control = arrow || target.closest( '.wea-mega-menu__link' );
			var item = control && control.closest( '.wea-mega-menu' ) === root ? byLi.get( control.closest( 'li' ) ) : null;

			if ( ! item ) {
				// A link inside a mega panel or a nested menu.
				var other = target.closest( 'a[href]' );
				if ( other && ! event.defaultPrevented ) {
					onLinkToThisPage( other );
				}
				return;
			}

			var link = item.link;

			if ( arrow ) {
				event.preventDefault();
				item.opener = arrow;
				// A hover just opened it: a quick click should not close it again.
				if ( isOpen( item ) && ! mobile && hover && lastPointer === 'mouse' && Date.now() - item.openedAt < 500 ) {
					return;
				}
				toggleItem( item );
				return;
			}

			var href = link.getAttribute( 'href' );
			var dead = ! href || href === '#';

			if ( ! popupOf( item ) ) {
				onLinkToThisPage( link );
				return;
			}

			item.opener = link;

			if ( mobile ) {
				if ( dead ) {
					event.preventDefault();
					toggleItem( item );
				} else {
					onLinkToThisPage( link );
				}
				return;
			}

			// Click mode, or a touch on a hover menu: the first tap opens, the next follows the link.
			if ( ! hover || lastPointer === 'touch' ) {
				if ( ! isOpen( item ) ) {
					event.preventDefault();
					open( item );
				} else if ( dead ) {
					event.preventDefault();
					close( item );
				}
				return;
			}

			if ( dead ) {
				event.preventDefault();
				toggleItem( item );
			}
		} );

		items.forEach( function ( item ) {
			if ( ! item.arrow ) {
				return;
			}
			on( item.li, 'pointerenter', function ( event ) {
				if ( mobile || ! hover || event.pointerType === 'touch' ) {
					return;
				}
				for ( var p = item; p; p = p.parent ) {
					if ( p.timer && isOpen( p ) ) {
						window.clearTimeout( p.timer );
						p.timer = 0;
					}
				}
				if ( isOpen( item ) ) {
					return;
				}
				window.clearTimeout( item.timer );
				var switching = items.some( function ( other ) {
					return other !== item && other.parent === item.parent && isOpen( other );
				} );
				item.timer = window.setTimeout( function () {
					item.timer = 0;
					item.opener = null;
					open( item );
				}, switching ? 0 : openDelay );
			} );
			on( item.li, 'pointerleave', function ( event ) {
				if ( mobile || ! hover || event.pointerType === 'touch' ) {
					return;
				}
				window.clearTimeout( item.timer );
				item.timer = 0;
				if ( ! isOpen( item ) || item === pinned ) {
					return;
				}
				item.timer = window.setTimeout( function () {
					item.timer = 0;
					close( item );
				}, closeDelay );
			} );
		} );

		function onDesktopKey( event, item, onLink ) {
			var key = event.key;
			var rtl = isRtl();
			var next = rtl ? 'ArrowLeft' : 'ArrowRight';
			var prev = rtl ? 'ArrowRight' : 'ArrowLeft';
			var pop = popupOf( item );
			var row = level( item );
			var index = row.indexOf( item );
			var bar = item.top && ! vertical;

			if ( key === ' ' && onLink && pop ) {
				event.preventDefault();
				item.opener = item.link;
				toggleItem( item );
				return;
			}

			if ( key === 'Home' || key === 'End' ) {
				if ( row.length ) {
					event.preventDefault();
					focusItem( row[ key === 'Home' ? 0 : row.length - 1 ] );
				}
				return;
			}

			if ( bar ) {
				if ( key === next || key === prev ) {
					event.preventDefault();
					focusItem( row[ ( index + ( key === next ? 1 : -1 ) + row.length ) % row.length ] );
				} else if ( ( key === 'ArrowDown' || key === 'ArrowUp' ) && pop ) {
					event.preventDefault();
					item.opener = event.target;
					open( item, key === 'ArrowUp' ? 'last' : 'first' );
				}
				return;
			}

			if ( key === 'ArrowDown' || key === 'ArrowUp' ) {
				event.preventDefault();
				focusItem( row[ ( index + ( key === 'ArrowDown' ? 1 : -1 ) + row.length ) % row.length ] );
			} else if ( key === next && pop ) {
				event.preventDefault();
				item.opener = event.target;
				open( item, 'first' );
			} else if ( key === prev && item.parent ) {
				event.preventDefault();
				close( item.parent, true );
				returnFocus( item.parent );
			}
		}

		function onEscape( event ) {
			if ( mobile ) {
				if ( panelOpen ) {
					event.preventDefault();
					closePanel( true );
				}
				return;
			}
			var active = document.activeElement;
			var owner = null;
			for ( var node = active; node && node !== root; node = node.parentElement ) {
				if ( node.matches && node.matches( 'li.wea-mega-menu__item' ) && byLi.has( node ) && isOpen( byLi.get( node ) ) ) {
					owner = byLi.get( node );
					break;
				}
			}
			if ( owner ) {
				event.preventDefault();
				pinned = pinned === owner ? null : pinned;
				close( owner, true );
				returnFocus( owner );
				return;
			}
			if ( items.some( isOpen ) ) {
				event.preventDefault();
				pinned = null;
				closeAll( true );
			}
		}

		on( root, 'keydown', function ( event ) {
			lastPointer = 'keyboard';
			if ( event.defaultPrevented ) {
				return;
			}

			if ( event.key === 'Escape' || event.key === 'Esc' ) {
				onEscape( event );
				return;
			}
			if ( event.key === 'Tab' ) {
				if ( mobile && offcanvas && panelOpen ) {
					trapFocus( event );
				}
				return;
			}

			var target = event.target;
			var row = target && target.parentElement;
			if ( ! row || ! row.classList.contains( 'wea-mega-menu__row' ) || row.closest( '.wea-mega-menu' ) !== root ) {
				return;
			}
			var item = byLi.get( row.parentElement );
			if ( ! item || event.altKey || event.ctrlKey || event.metaKey ) {
				return;
			}
			var onLink = target === item.link;

			if ( mobile ) {
				if ( event.key === ' ' && onLink && popupOf( item ) ) {
					event.preventDefault();
					toggleItem( item );
				}
				return;
			}
			onDesktopKey( event, item, onLink );
		} );

		// Close dropdowns that keyboard focus has left.
		on( root, 'focusout', function ( event ) {
			var to = event.relatedTarget;
			if ( ! to ) {
				return;
			}
			if ( mobile ) {
				if ( panelOpen && ! offcanvas && ! root.contains( to ) ) {
					closePanel( false );
				}
				return;
			}
			items.forEach( function ( item ) {
				if ( isOpen( item ) && item !== pinned && ! item.li.contains( to ) ) {
					close( item );
				}
			} );
		} );

		on( document, 'pointerdown', function ( event ) {
			if ( root.contains( event.target ) ) {
				return;
			}
			if ( mobile ) {
				if ( panelOpen && ! offcanvas ) {
					closePanel( false );
				}
				return;
			}
			closeAll( false );
		} );

		on( toggle, 'click', function () {
			if ( panelOpen ) {
				closePanel( false );
			} else {
				openPanel();
			}
		} );

		on( closeBtn, 'click', function () {
			closePanel( true );
		} );

		on( overlay, 'click', function () {
			closePanel( true );
		} );

		on( window, 'resize', function () {
			if ( frame ) {
				return;
			}
			frame = window.requestAnimationFrame( function () {
				frame = 0;
				items.forEach( function ( item ) {
					if ( isOpen( item ) ) {
						place( item );
					}
				} );
				if ( panelOpen ) {
					placePanel();
				}
			} );
		} );

		if ( bp > 0 && window.matchMedia ) {
			mq = window.matchMedia( '(max-width: ' + bp + 'px)' );
			var onMedia = function () {
				setMode( mq.matches );
			};
			if ( mq.addEventListener ) {
				mq.addEventListener( 'change', onMedia );
				listeners.push( function () {
					mq.removeEventListener( 'change', onMedia );
				} );
			} else if ( mq.addListener ) {
				mq.addListener( onMedia );
				listeners.push( function () {
					mq.removeListener( onMedia );
				} );
			}
			setMode( mq.matches );
		} else {
			setMode( false );
		}

		root.classList.add( 'is-init' );

		// Editor helper: keep the first mega panel open while it is being styled.
		if ( opts.editorOpen && WiseAddons.isEditMode() && ! mobile ) {
			var first = items.filter( function ( item ) {
				return item.top && item.mega;
			} )[ 0 ];
			if ( first && open( first ) ) {
				pinned = first;
			}
		}

		return function destroy() {
			items.forEach( function ( item ) {
				window.clearTimeout( item.timer );
				window.clearTimeout( item.closing );
			} );
			listeners.forEach( function ( off ) {
				off();
			} );
			if ( locked ) {
				unlockScroll();
				locked = false;
			}
			if ( frame ) {
				window.cancelAnimationFrame( frame );
			}
			root.classList.remove( 'is-init', 'is-mobile', 'is-panel-open' );
		};
	}

	WiseAddons.onWidget( 'wea-mega-menu', function ( el ) {
		if ( typeof el[ CLEANUP ] === 'function' ) {
			el[ CLEANUP ]();
		}
		var root = null;
		// The widget's own root, not one from a template inside a mega panel.
		Array.prototype.some.call( el.querySelectorAll( '.wea-mega-menu' ), function ( node ) {
			if ( node.parentElement && node.parentElement.closest( '.elementor-widget-wea-mega-menu' ) === el ) {
				root = node;
				return true;
			}
			return false;
		} );
		el[ CLEANUP ] = root ? setup( root ) : null;
	} );
}( window, document ) );
