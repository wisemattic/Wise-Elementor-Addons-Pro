/**
 * Wise Sticky.
 *
 * Pins elements that carry data-wea-sticky (live site) or have Sticky switched on
 * in their Elementor model (editor preview).
 *
 * Two ways to pin:
 * - "css":   native position: sticky. Used for "Parent element" when no ancestor's
 *            overflow turns into a scroll box that would trap it.
 * - "fixed": position: fixed plus an invisible placeholder of the same size, so the
 *            layout below does not move. Used for "Whole page" and as the fallback.
 *
 * One passive scroll listener and one rAF per frame serve every element. An
 * IntersectionObserver pauses "Parent element" stickies whose parent is far away.
 * Everything is undone on resize, on breakpoint change and when the editor
 * re-renders an element.
 */
( function ( window, document ) {
	'use strict';

	var ATTR = 'data-wea-sticky';
	var STUCK = 'wea-is-stuck';
	var ACTIVE = 'wea-sticky--active';
	var SCROLL_BOX = /(auto|scroll|hidden|overlay)/;

	var instances = [];
	var ticking = false;
	var lastY = 0;
	var direction = 0;
	var resizeTimer = 0;
	var reducedMotion = window.matchMedia ? window.matchMedia( '(prefers-reduced-motion: reduce)' ) : null;
	var canObserve = 'IntersectionObserver' in window;

	/**
	 * Elementor editor preview. The body class arrives late, so ask Elementor first.
	 */
	function isEditor() {
		var fe = window.elementorFrontend;

		if ( fe && 'function' === typeof fe.isEditMode ) {
			try {
				if ( fe.isEditMode() ) {
					return true;
				}
			} catch ( e ) {}
		}

		return !! ( document.body && document.body.classList.contains( 'elementor-editor-active' ) );
	}

	function scrollTop() {
		return window.pageYOffset || document.documentElement.scrollTop || 0;
	}

	function px( value ) {
		var n = parseFloat( value );
		return isFinite( n ) ? n : 0;
	}

	/* ------------------------------------------------------------------
	 * Devices
	 * ------------------------------------------------------------------ */

	function breakpoints() {
		var fe = window.elementorFrontend;
		var config = ( fe && fe.config ) || window.elementorFrontendConfig || {};
		var active = config.responsive && config.responsive.activeBreakpoints;

		return active && 'object' === typeof active ? active : {
			mobile: { value: 767, direction: 'max' },
			tablet: { value: 1024, direction: 'max' },
		};
	}

	/**
	 * Current Elementor device, with the same media queries Elementor's CSS uses.
	 */
	function currentDevice() {
		var list = breakpoints();
		var smaller = [];
		var larger = null;
		var key;

		for ( key in list ) {
			if ( ! Object.prototype.hasOwnProperty.call( list, key ) || ! list[ key ] ) {
				continue;
			}
			if ( 'min' === list[ key ].direction ) {
				larger = { key: key, value: list[ key ].value };
			} else {
				smaller.push( { key: key, value: list[ key ].value } );
			}
		}

		smaller.sort( function ( a, b ) {
			return a.value - b.value;
		} );

		for ( var i = 0; i < smaller.length; i++ ) {
			if ( window.matchMedia( '(max-width: ' + smaller[ i ].value + 'px)' ).matches ) {
				return smaller[ i ].key;
			}
		}

		if ( larger && window.matchMedia( '(min-width: ' + larger.value + 'px)' ).matches ) {
			return larger.key;
		}

		return 'desktop';
	}

	/* ------------------------------------------------------------------
	 * Settings
	 * ------------------------------------------------------------------ */

	function normalize( raw ) {
		var pos = 'bottom' === raw.pos ? 'bottom' : 'top';
		var dev = raw.dev;

		if ( dev && 'object' === typeof dev && ! Array.isArray( dev ) ) {
			dev = Object.keys( dev ).map( function ( k ) {
				return dev[ k ];
			} );
		}

		return {
			pos: pos,
			dev: Array.isArray( dev ) ? dev.map( String ) : null,
			scope: 'parent' === raw.scope ? 'parent' : 'page',
			hide: 'top' === pos && !! raw.hide && '0' !== raw.hide,
		};
	}

	/**
	 * The editor's settings model for an element, or null.
	 */
	function editorModel( el ) {
		var fe = window.elementorFrontend;
		var cid = el.getAttribute( 'data-model-cid' );
		var data = fe && fe.config && fe.config.elements && fe.config.elements.data;

		return cid && data && data[ cid ] && data[ cid ].attributes ? data[ cid ] : null;
	}

	function valueOf( value ) {
		return value && 'function' === typeof value.toJSON ? value.toJSON() : value;
	}

	function readSettings( el ) {
		var raw = el.getAttribute( ATTR );
		var model;
		var a;

		if ( raw ) {
			try {
				return normalize( JSON.parse( raw ) );
			} catch ( e ) {
				return null;
			}
		}

		model = isEditor() ? editorModel( el ) : null;
		if ( ! model ) {
			return null;
		}

		a = model.attributes;
		if ( 'yes' !== a.wea_sticky_enable ) {
			return null;
		}

		return normalize( {
			pos: a.wea_sticky_position,
			dev: valueOf( a.wea_sticky_devices ),
			scope: a.wea_sticky_scope,
			hide: 'yes' === a.wea_sticky_hide,
		} );
	}

	/* ------------------------------------------------------------------
	 * Layout checks
	 * ------------------------------------------------------------------ */

	/**
	 * Would an ancestor's overflow trap position: sticky inside a box that never scrolls?
	 */
	function stickyIsTrapped( el ) {
		var html = document.documentElement;
		var body = document.body;
		var node;
		var cs;

		for ( node = el.parentElement; node && node !== body && node !== html; node = node.parentElement ) {
			cs = window.getComputedStyle( node );
			if ( SCROLL_BOX.test( cs.overflowX ) || SCROLL_BOX.test( cs.overflowY ) ) {
				return true;
			}
		}

		// The body's overflow only traps when the root has its own overflow set.
		if ( body ) {
			var rootCs = window.getComputedStyle( html );
			var bodyCs = window.getComputedStyle( body );
			if ( ! /^(visible|clip)$/.test( rootCs.overflowX + '' ) || ! /^(visible|clip)$/.test( rootCs.overflowY + '' ) ) {
				if ( SCROLL_BOX.test( bodyCs.overflowX ) || SCROLL_BOX.test( bodyCs.overflowY ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * In a flex row or grid the element stretches to the parent's height by default,
	 * which leaves no room to stick. Pinning it to the start fixes that.
	 */
	function needsSelfStart( el ) {
		var parent = el.parentElement;
		if ( ! parent ) {
			return false;
		}
		var cs = window.getComputedStyle( parent );
		var display = cs.display || '';

		if ( display.indexOf( 'grid' ) !== -1 ) {
			return true;
		}
		return display.indexOf( 'flex' ) !== -1 && 0 === ( cs.flexDirection || 'row' ).indexOf( 'row' );
	}

	/**
	 * How far the element's box-shadow reaches below it, so a hidden header leaves no trace.
	 */
	function shadowBelow( el ) {
		var shadow = window.getComputedStyle( el ).boxShadow || '';
		var re = /(-?[\d.]+)px\s+(-?[\d.]+)px(?:\s+(-?[\d.]+)px)?(?:\s+(-?[\d.]+)px)?/g;
		var max = 0;
		var m;

		while ( ( m = re.exec( shadow ) ) ) {
			max = Math.max( max, px( m[ 2 ] ) + px( m[ 3 ] ) + px( m[ 4 ] ) );
		}

		return max;
	}

	function adminBarHeight() {
		var bar = document.getElementById( 'wpadminbar' );
		if ( ! bar ) {
			return 0;
		}
		return 'fixed' === window.getComputedStyle( bar ).position ? bar.offsetHeight : 0;
	}

	/* ------------------------------------------------------------------
	 * One sticky element
	 * ------------------------------------------------------------------ */

	function Sticky( el, settings ) {
		this.el = el;
		this.s = settings;
		this.saved = {};
		this.mode = '';
		this.stuck = false;
		this.hidden = false;
		this.near = true;
		this.placeholder = null;
		this.height = 0;
		this.stuckAt = 0;
		this.offset = 0;
		this.admin = 0;
		this.observer = null;
		this.model = null;
		this.focusIn = this.reveal.bind( this );
		this.modelChange = this.onModelChange.bind( this );

		el.addEventListener( 'focusin', this.focusIn );

		if ( isEditor() ) {
			this.model = editorModel( el );
			if ( this.model && 'function' === typeof this.model.on ) {
				this.model.on( 'change', this.modelChange );
			}
		}

		this.setup();
	}

	/**
	 * Set an inline style, remembering what was there before.
	 */
	Sticky.prototype.css = function ( prop, value ) {
		if ( ! Object.prototype.hasOwnProperty.call( this.saved, prop ) ) {
			this.saved[ prop ] = this.el.style.getPropertyValue( prop );
		}
		this.el.style.setProperty( prop, value );
	};

	Sticky.prototype.restore = function ( props ) {
		var self = this;
		( props || Object.keys( this.saved ) ).forEach( function ( prop ) {
			if ( ! Object.prototype.hasOwnProperty.call( self.saved, prop ) ) {
				return;
			}
			if ( self.saved[ prop ] ) {
				self.el.style.setProperty( prop, self.saved[ prop ] );
			} else {
				self.el.style.removeProperty( prop );
			}
			delete self.saved[ prop ];
		} );
	};

	/**
	 * Offsets from the CSS custom properties (they follow Elementor's breakpoints).
	 */
	Sticky.prototype.readOffsets = function () {
		this.offset = px( window.getComputedStyle( this.el ).getPropertyValue( '--wea-sticky-offset' ) );
		this.admin = 'top' === this.s.pos ? adminBarHeight() : 0;
	};

	Sticky.prototype.setup = function () {
		var el = this.el;
		var s = this.s;

		this.reset();

		if ( ! el.isConnected || ( s.dev && s.dev.indexOf( currentDevice() ) === -1 ) ) {
			return;
		}
		if ( 'none' === window.getComputedStyle( el ).display ) {
			return;
		}

		this.readOffsets();
		this.mode = ( 'parent' === s.scope && ! stickyIsTrapped( el ) ) ? 'css' : 'fixed';

		el.classList.add( ACTIVE, 'wea-sticky--' + s.pos, 'wea-sticky--' + this.mode );
		this.css( '--wea-sticky-admin', this.admin + 'px' );

		if ( 'css' === this.mode ) {
			this.css( 'position', 'sticky' );
			this.css( 'top' === s.pos ? 'top' : 'bottom', 'top' === s.pos ? 'calc(var(--wea-sticky-offset, 0px) + ' + this.admin + 'px)' : 'var(--wea-sticky-offset, 0px)' );
			this.css( 'z-index', 'var(--wea-sticky-z, 99)' );
		}

		// "Parent element" needs room to move, which a stretched flex or grid item lacks.
		if ( 'parent' === s.scope && needsSelfStart( el ) ) {
			this.css( 'align-self', 'flex-start' );
		}

		if ( s.hide && reducedMotion && ! reducedMotion.matches ) {
			var current = window.getComputedStyle( el ).transition;
			var base = current && 'none' !== current && 'all 0s ease 0s' !== current ? current + ', ' : '';
			this.css( 'transition', base + 'translate 0.3s ease' );
		}

		// Pause while the parent is far off screen.
		if ( 'parent' === s.scope && el.parentElement && canObserve ) {
			this.observer = new IntersectionObserver( this.onIntersect.bind( this ), { rootMargin: '200px 0px' } );
			this.observer.observe( el.parentElement );
		}

		this.update( scrollTop() );
	};

	/**
	 * Undo everything this instance did to the element.
	 */
	Sticky.prototype.reset = function () {
		var el = this.el;

		this.unstick();
		this.setHidden( false );

		if ( this.observer ) {
			this.observer.disconnect();
			this.observer = null;
		}
		this.near = true;

		this.restore();
		el.classList.remove( ACTIVE, STUCK, 'wea-sticky--top', 'wea-sticky--bottom', 'wea-sticky--css', 'wea-sticky--fixed' );
		this.mode = '';
	};

	Sticky.prototype.destroy = function () {
		this.reset();
		this.el.removeEventListener( 'focusin', this.focusIn );
		if ( this.model && 'function' === typeof this.model.off ) {
			this.model.off( 'change', this.modelChange );
		}
		this.model = null;
	};

	/**
	 * Editor: a sticky setting changed without a re-render.
	 */
	Sticky.prototype.onModelChange = function ( model ) {
		var changed = model && model.changed ? Object.keys( model.changed ) : [];
		var relevant = changed.some( function ( key ) {
			return 0 === key.indexOf( 'wea_sticky_' );
		} );

		if ( relevant ) {
			attach( this.el );
		}
	};

	Sticky.prototype.update = function ( y ) {
		if ( ! this.mode || ! this.near ) {
			return;
		}

		if ( isEditor() ) {
			this.readOffsets();
		}

		if ( 'css' === this.mode ) {
			this.updateCss();
		} else {
			this.updateFixed();
		}

		this.updateHide( y );
	};

	/**
	 * The parent's box came near the viewport or left it.
	 */
	Sticky.prototype.onIntersect = function ( entries ) {
		var entry = entries[ entries.length - 1 ];

		this.near = true;
		this.update( scrollTop() );

		// Far away: put the element back in its place, so a jump to another part of
		// the page never leaves it pinned off screen.
		if ( ! entry || ! entry.isIntersecting ) {
			this.setHidden( false );
			this.unstick();
			this.near = false;
		}
	};

	/**
	 * Native sticky: only work out whether it is stuck right now.
	 */
	Sticky.prototype.updateCss = function () {
		var r;
		var p;
		var stuck;

		// While slid out of view the box is translated, so measuring would lie.
		// It is shown again on the next scroll up, and measured then.
		if ( this.hidden ) {
			return;
		}

		r = this.el.getBoundingClientRect();
		p = this.el.parentElement ? this.el.parentElement.getBoundingClientRect() : null;

		if ( ! p ) {
			return;
		}

		if ( 'top' === this.s.pos ) {
			var line = this.offset + this.admin;
			stuck = Math.abs( r.top - line ) < 1 && p.top < line - 0.5;
		} else {
			var bottomLine = window.innerHeight - this.offset;
			stuck = Math.abs( r.bottom - bottomLine ) < 1 && p.bottom > bottomLine + 0.5;
		}

		this.setStuck( stuck );
		this.height = r.height;
	};

	/**
	 * Fixed: place the element against the viewport, kept inside the parent when asked.
	 */
	Sticky.prototype.updateFixed = function () {
		var el = this.el;
		var ref = this.placeholder || el;
		var r = ref.getBoundingClientRect();
		var h = this.placeholder ? this.height : r.height;
		var parent = this.placeholder ? this.placeholder.parentElement : el.parentElement;
		var y;
		var stick;

		if ( 'top' === this.s.pos ) {
			y = this.offset + this.admin;
			stick = r.top < y;
			if ( stick && 'parent' === this.s.scope && parent ) {
				var pr = parent.getBoundingClientRect();
				var pcs = window.getComputedStyle( parent );
				y = Math.min( y, pr.bottom - px( pcs.paddingBottom ) - px( pcs.borderBottomWidth ) - h );
			}
		} else {
			y = window.innerHeight - this.offset - h;
			stick = r.top > y;
			if ( stick && 'parent' === this.s.scope && parent ) {
				var pb = parent.getBoundingClientRect();
				var pbs = window.getComputedStyle( parent );
				y = Math.max( y, pb.top + px( pbs.paddingTop ) + px( pbs.borderTopWidth ) );
			}
		}

		if ( ! stick ) {
			this.unstick();
			return;
		}

		if ( ! this.placeholder ) {
			this.stickFixed( r );
			r = this.placeholder.getBoundingClientRect();
		}

		this.css( 'top', y + 'px' );
		this.css( 'left', r.left + 'px' );
	};

	Sticky.prototype.stickFixed = function ( r ) {
		var el = this.el;
		var cs = window.getComputedStyle( el );
		var ph = document.createElement( 'div' );

		ph.className = 'wea-sticky-placeholder';
		ph.setAttribute( 'aria-hidden', 'true' );
		ph.style.cssText = [
			'display:' + ( 'inline-block' === cs.display ? 'inline-block' : 'block' ),
			'visibility:hidden',
			'pointer-events:none',
			'box-sizing:border-box',
			'padding:0',
			'border:0',
			'flex:0 0 auto',
			'width:' + r.width + 'px',
			'height:' + r.height + 'px',
			'margin:' + cs.marginTop + ' ' + cs.marginRight + ' ' + cs.marginBottom + ' ' + cs.marginLeft,
			'align-self:' + cs.alignSelf,
			'order:' + cs.order,
			'grid-column:' + cs.gridColumnStart + ' / ' + cs.gridColumnEnd,
			'grid-row:' + cs.gridRowStart + ' / ' + cs.gridRowEnd,
		].join( ';' );

		el.parentNode.insertBefore( ph, el );

		this.placeholder = ph;
		this.height = r.height;

		this.css( 'position', 'fixed' );
		this.css( 'width', r.width + 'px' );
		this.css( 'box-sizing', 'border-box' );
		this.css( 'margin', '0' );
		this.css( 'z-index', 'var(--wea-sticky-z, 99)' );

		this.setStuck( true );
	};

	Sticky.prototype.unstick = function () {
		if ( this.placeholder ) {
			if ( this.placeholder.parentNode ) {
				this.placeholder.parentNode.removeChild( this.placeholder );
			}
			this.placeholder = null;
			this.restore( [ 'position', 'top', 'left', 'width', 'box-sizing', 'margin', 'z-index' ] );
		}
		this.setStuck( false );
	};

	Sticky.prototype.setStuck = function ( stuck ) {
		if ( stuck === this.stuck ) {
			return;
		}
		this.stuck = stuck;
		this.stuckAt = scrollTop();
		this.el.classList.toggle( STUCK, stuck );
	};

	/**
	 * Hide while scrolling down, show when scrolling up (top only).
	 */
	Sticky.prototype.updateHide = function ( y ) {
		if ( ! this.s.hide ) {
			return;
		}

		var hide = this.hidden;

		if ( ! this.stuck || direction < 0 ) {
			hide = false;
		} else if ( direction > 0 && y > this.stuckAt + this.height && ! this.el.contains( document.activeElement ) ) {
			hide = true;
		}

		this.setHidden( hide );
	};

	Sticky.prototype.setHidden = function ( hide ) {
		if ( hide === this.hidden ) {
			return;
		}
		this.hidden = hide;
		this.el.classList.toggle( 'wea-sticky--hidden', hide );

		if ( hide ) {
			this.css( 'translate', '0 calc(-100% - ' + ( this.offset + this.admin + shadowBelow( this.el ) + 2 ) + 'px)' );
		} else {
			this.restore( [ 'translate' ] );
		}
	};

	/**
	 * Keyboard users tabbing into a hidden header bring it back.
	 */
	Sticky.prototype.reveal = function () {
		this.setHidden( false );
	};

	/* ------------------------------------------------------------------
	 * Registry and global listeners
	 * ------------------------------------------------------------------ */

	function find( el ) {
		for ( var i = 0; i < instances.length; i++ ) {
			if ( instances[ i ].el === el ) {
				return i;
			}
		}
		return -1;
	}

	function detach( el ) {
		var i = find( el );
		if ( i !== -1 ) {
			instances[ i ].destroy();
			instances.splice( i, 1 );
		}
	}

	/**
	 * Drop instances whose element left the page (editor re-render, AJAX).
	 */
	function sweep() {
		for ( var i = instances.length - 1; i >= 0; i-- ) {
			if ( ! instances[ i ].el.isConnected ) {
				instances[ i ].destroy();
				instances.splice( i, 1 );
			}
		}
	}

	function attach( el ) {
		var settings;

		detach( el );
		sweep();

		settings = readSettings( el );
		if ( settings ) {
			instances.push( new Sticky( el, settings ) );
			listen();
		}
	}

	function tick() {
		var y = scrollTop();

		ticking = false;

		if ( Math.abs( y - lastY ) >= 3 ) {
			direction = y > lastY ? 1 : -1;
			lastY = y;
		} else {
			direction = 0;
		}

		for ( var i = instances.length - 1; i >= 0; i-- ) {
			if ( ! instances[ i ].el.isConnected ) {
				instances[ i ].destroy();
				instances.splice( i, 1 );
				continue;
			}
			instances[ i ].update( y );
		}
	}

	function onScroll() {
		if ( ! ticking ) {
			ticking = true;
			window.requestAnimationFrame( tick );
		}
	}

	function refreshAll() {
		sweep();
		instances.forEach( function ( instance ) {
			instance.setup();
		} );
	}

	function onResize() {
		window.clearTimeout( resizeTimer );
		resizeTimer = window.setTimeout( refreshAll, 120 );
	}

	var listening = false;

	function listen() {
		if ( listening ) {
			return;
		}
		listening = true;
		lastY = scrollTop();

		window.addEventListener( 'scroll', onScroll, { passive: true } );
		window.addEventListener( 'resize', onResize, { passive: true } );
		window.addEventListener( 'orientationchange', onResize, { passive: true } );
		window.addEventListener( 'load', refreshAll );

		if ( reducedMotion && 'function' === typeof reducedMotion.addEventListener ) {
			reducedMotion.addEventListener( 'change', refreshAll );
		}
	}

	function scan() {
		var nodes = document.querySelectorAll( '[' + ATTR + ']' );
		for ( var i = 0; i < nodes.length; i++ ) {
			if ( find( nodes[ i ] ) === -1 ) {
				attach( nodes[ i ] );
			}
		}
	}

	/**
	 * Editor preview: Elementor tells us each time an element (re)renders.
	 */
	var hooked = false;

	function hookEditor() {
		var fe = window.elementorFrontend;

		if ( hooked || ! fe || ! fe.hooks || ! isEditor() ) {
			return;
		}
		hooked = true;

		fe.hooks.addAction( 'frontend/element_ready/global', function ( scope ) {
			var el = scope && scope[ 0 ] ? scope[ 0 ] : scope;
			if ( el && 1 === el.nodeType ) {
				attach( el );
			}
		} );
	}

	function start() {
		scan();
		hookEditor();
	}

	window.addEventListener( 'elementor/frontend/init', hookEditor );

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
}( window, document ) );
