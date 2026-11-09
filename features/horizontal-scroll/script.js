/**
 * Wise Horizontal Scroll.
 *
 * For every container or section with `wea-hs-yes`:
 * - reads the Layout for the current device from the `--wea-hs-mode` custom
 *   property (written by a responsive control, so Elementor's breakpoints apply),
 * - "scroll": adds `wea-hs-on`, gives the row the height the slide needs, and on
 *   each animation frame after a scroll sets every panel's `translate` from how
 *   far the pinned row has been scrolled,
 * - "swipe": adds `wea-hs-swipe` (CSS does the rest),
 * - "stack" or reduced motion: leaves the element alone.
 *
 * Panels are sticky in place, so no markup moves. Focus inside a panel scrolls
 * the page to that panel, so keyboard users reach everything. Sizes are
 * re-measured on resize, image loads, panel changes and breakpoint changes.
 *
 * Standalone vanilla JS: no jQuery, no dependencies.
 */
( function ( window, document ) {
	'use strict';

	if ( window.WEAHorizontalScrollReady ) {
		return;
	}
	window.WEAHorizontalScrollReady = true;

	var HOST_CLASS = 'wea-hs-yes';
	var ON_CLASS = 'wea-hs-on';
	var SWIPE_CLASS = 'wea-hs-swipe';
	var MODES = [ 'scroll', 'swipe', 'stack' ];

	var instances = new Map();
	var pending = new Map();
	var reducedQuery = window.matchMedia ? window.matchMedia( '(prefers-reduced-motion: reduce)' ) : null;
	var frameQueued = false;
	var hasScrollEnd = 'onscrollend' in window;
	var scrollEndTimer = 0;

	function clamp( v, a, b ) {
		if ( v < a ) {
			return a;
		}
		return v > b ? b : v;
	}

	function reduced() {
		return !! ( reducedQuery && reducedQuery.matches );
	}

	function px( value ) {
		var v = parseFloat( value );
		return isFinite( v ) ? v : 0;
	}

	function scrollY() {
		return window.pageYOffset || document.documentElement.scrollTop || 0;
	}

	// Height of the WordPress toolbar when it is fixed to the top of the window.
	function adminBar() {
		var bar = document.getElementById( 'wpadminbar' );
		if ( ! bar || 'fixed' !== window.getComputedStyle( bar ).position ) {
			return 0;
		}
		return bar.offsetHeight;
	}

	// The row: .e-con-inner of a boxed container, .elementor-container of a section, or the container itself.
	function findTrack( host ) {
		var kids = host.children;
		for ( var i = 0; i < kids.length; i++ ) {
			if ( kids[ i ].classList.contains( 'e-con-inner' ) || kids[ i ].classList.contains( 'elementor-container' ) ) {
				return kids[ i ];
			}
		}
		return host;
	}

	/* One horizontal row ----------------------------------------------------- */

	function Rail( host ) {
		var self = this;

		this.host = host;
		this.unwatch = null;
		this.track = findTrack( host );
		this.panels = [];
		this.points = [];
		this.mode = '';
		this.opts = {};
		this.x = 0;
		this.max = 0;
		this.dist = 0;
		this.top = 0;
		this.ph = 0;
		this.edge = 0;
		this.rtl = false;
		this.bar = null;
		this.measuring = false;

		this.onFocus = function ( event ) {
			self.focus( event );
		};
		this.onLoad = function () {
			self.schedule();
		};

		host.addEventListener( 'focusin', this.onFocus );
		host.addEventListener( 'load', this.onLoad, true );

		this.ro = 'ResizeObserver' in window ?
			new window.ResizeObserver( function () {
				self.schedule();
			} ) :
			null;
		this.mo = 'MutationObserver' in window ?
			new window.MutationObserver( function () {
				self.schedule();
			} ) :
			null;

		if ( this.ro ) {
			this.ro.observe( this.track );
		}
		if ( this.mo ) {
			this.mo.observe( this.track, { childList: true } );
		}

		this.refresh();
	}

	/**
	 * Read the settings for the current device from the element's custom properties.
	 */
	Rail.prototype.read = function () {
		var cs = window.getComputedStyle( this.host );

		function raw( name ) {
			return cs.getPropertyValue( '--wea-hs-' + name ).trim().replace( /^["']|["']$/g, '' );
		}

		var mode = raw( 'mode' );
		var speed = parseFloat( raw( 'speed' ) );

		if ( -1 === MODES.indexOf( mode ) ) {
			mode = window.innerWidth > 767 ? 'scroll' : 'stack';
		}
		if ( 'scroll' === mode && reduced() ) {
			mode = 'stack';
		}

		return {
			mode: mode,
			speed: isFinite( speed ) && speed > 0 ? clamp( speed, 0.1, 10 ) : 1,
			offset: Math.max( 0, px( raw( 'offset' ) ) ),
			snap: '' !== raw( 'snap' ),
			bar: 'on' === raw( 'bar' ),
			barColor: raw( 'bar-color' ) || '#5b4cf5',
			barBg: raw( 'bar-bg' ) || 'rgba(0, 0, 0, 0.08)',
			barHeight: clamp( px( raw( 'bar-h' ) ) || 4, 1, 40 ),
		};
	};

	/**
	 * Apply the layout for the current device, then measure.
	 */
	Rail.prototype.refresh = function () {
		var opts = this.read();
		var host = this.host;

		this.opts = opts;

		if ( opts.mode !== this.mode ) {
			this.mode = opts.mode;
			host.classList.toggle( ON_CLASS, 'scroll' === opts.mode );
			host.classList.toggle( SWIPE_CLASS, 'swipe' === opts.mode );

			if ( 'scroll' !== opts.mode ) {
				this.reset();
			}
		}

		this.observePanels();

		if ( 'scroll' === this.mode ) {
			this.measure();
			this.render();
		}
	};

	/**
	 * Collect the panels. Only new or removed panels change what the
	 * ResizeObserver watches, so measuring never re-triggers itself.
	 */
	Rail.prototype.observePanels = function () {
		var panels = [];
		var kids = this.track.children;
		var x = this.x;

		for ( var i = 0; i < kids.length; i++ ) {
			if ( kids[ i ].classList.contains( 'elementor-element' ) ) {
				panels.push( kids[ i ] );
			}
		}

		// Panels that left the row get their position back.
		this.panels.forEach( function ( panel ) {
			if ( -1 === panels.indexOf( panel ) ) {
				panel.style.translate = '';
				if ( this.ro ) {
					this.ro.unobserve( panel );
				}
			}
		}, this );

		// New panels join at the current offset, so measuring stays correct mid-scroll.
		panels.forEach( function ( panel ) {
			if ( -1 === this.panels.indexOf( panel ) ) {
				panel.style.translate = x ? x + 'px 0' : '';
				if ( this.ro ) {
					this.ro.observe( panel );
				}
			}
		}, this );

		this.panels = panels;
	};

	/**
	 * Work out the row length, the pinned distance and the snap points.
	 * Measured with the current translate taken out, so it works mid-scroll.
	 */
	Rail.prototype.measure = function () {
		var host = this.host;
		var track = this.track;
		var opts = this.opts;
		var top = opts.offset + adminBar();
		var ph = Math.max( 160, window.innerHeight - top );
		var shift = this.x;
		var cs;
		var rect;
		var left;
		var right;
		var minLeft = Infinity;
		var maxRight = -Infinity;
		var starts = [];
		var height;

		this.measuring = true;

		host.style.setProperty( '--wea-hs-top', top + 'px' );
		host.style.setProperty( '--wea-hs-ph', ph + 'px' );

		cs = window.getComputedStyle( track );
		rect = track.getBoundingClientRect();
		left = rect.left + track.clientLeft + px( cs.paddingLeft );
		right = rect.left + track.clientLeft + track.clientWidth - px( cs.paddingRight );

		this.rtl = 'rtl' === cs.direction;

		this.panels.forEach( function ( panel ) {
			var r = panel.getBoundingClientRect();
			var l = r.left - shift;
			var rr = r.right - shift;
			minLeft = Math.min( minLeft, l );
			maxRight = Math.max( maxRight, rr );
			starts.push( this.rtl ? rr : l );
		}, this );

		if ( this.panels.length ) {
			this.max = Math.max( 0, Math.round( this.rtl ? left - minLeft : maxRight - right ) );
		} else {
			this.max = 0;
		}

		this.dist = this.max / opts.speed;
		this.top = top;
		this.ph = ph;
		this.edge = track.clientTop + px( cs.paddingTop );

		// Snap points: where each panel starts along the row, measured from the first one.
		this.points = starts.map( function ( s ) {
			return clamp( Math.round( this.rtl ? starts[ 0 ] - s : s - starts[ 0 ] ), 0, this.max );
		}, this );
		if ( -1 === this.points.indexOf( this.max ) ) {
			this.points.push( this.max );
		}

		height = ph + this.dist;
		if ( 'border-box' === cs.boxSizing ) {
			height += px( cs.paddingTop ) + px( cs.paddingBottom ) + px( cs.borderTopWidth ) + px( cs.borderBottomWidth );
		}
		host.style.setProperty( '--wea-hs-th', Math.ceil( height ) + 'px' );

		this.placeBar();
		this.measuring = false;
	};

	/**
	 * How far the pinned part has been scrolled: below 0 before, above 1 after.
	 */
	Rail.prototype.progress = function () {
		var start = this.track.getBoundingClientRect().top + this.edge;
		return this.dist > 0 ? ( this.top - start ) / this.dist : 0;
	};

	/**
	 * Slide the panels for the current scroll position. Runs once per frame at most.
	 */
	Rail.prototype.render = function () {
		var raw;
		var p;
		var x;

		if ( 'scroll' !== this.mode ) {
			return;
		}

		raw = this.progress();
		p = clamp( raw, 0, 1 );
		x = Math.round( ( this.rtl ? 1 : -1 ) * p * this.max * 10 ) / 10;

		if ( x !== this.x ) {
			this.x = x;
			for ( var i = 0; i < this.panels.length; i++ ) {
				this.panels[ i ].style.translate = x ? x + 'px 0' : '';
			}
		}

		if ( this.bar ) {
			this.bar.firstChild.style.transform = 'scaleX(' + Math.round( p * 1000 ) / 1000 + ')';
			this.bar.classList.toggle( 'is-active', this.max > 0 && raw > 0 && raw < 1 );
		}
	};

	/**
	 * The progress bar sits in <body> (a parent with a transform cannot trap it)
	 * at the bottom of the pinned area, as wide as the element.
	 */
	Rail.prototype.placeBar = function () {
		var opts = this.opts;
		var rect;
		var bar = this.bar;

		if ( ! opts.bar || 'scroll' !== this.mode ) {
			this.removeBar();
			return;
		}

		if ( ! bar ) {
			bar = this.bar = document.createElement( 'div' );
			bar.className = 'wea-hs-progress';
			bar.setAttribute( 'aria-hidden', 'true' );
			bar.appendChild( document.createElement( 'span' ) ).className = 'wea-hs-progress__fill';
			document.body.appendChild( bar );
		}

		rect = this.host.getBoundingClientRect();
		bar.classList.toggle( 'is-rtl', this.rtl );
		bar.style.left = Math.max( 0, rect.left ) + 'px';
		bar.style.width = Math.min( rect.width, document.documentElement.clientWidth ) + 'px';
		bar.style.height = opts.barHeight + 'px';
		bar.style.top = this.top + this.ph - opts.barHeight + 'px';
		bar.style.background = opts.barBg;
		bar.firstChild.style.background = opts.barColor;
	};

	Rail.prototype.removeBar = function () {
		if ( this.bar && this.bar.parentNode ) {
			this.bar.parentNode.removeChild( this.bar );
		}
		this.bar = null;
	};

	/**
	 * Scroll the page so that the given row position is on screen.
	 */
	Rail.prototype.scrollToX = function ( x ) {
		var start = this.track.getBoundingClientRect().top + this.edge;
		var y = scrollY() + ( start - this.top ) + ( clamp( x, 0, this.max ) / this.max ) * this.dist;

		window.scrollTo( { top: Math.round( y ), behavior: reduced() ? 'auto' : 'smooth' } );
	};

	/**
	 * Keyboard focus moved into a panel that may be off to the side: bring it in.
	 */
	Rail.prototype.focus = function ( event ) {
		var self = this;
		var index = -1;

		if ( 'scroll' !== this.mode || ! this.max ) {
			return;
		}

		for ( var i = 0; i < this.panels.length; i++ ) {
			if ( this.panels[ i ].contains( event.target ) ) {
				index = i;
				break;
			}
		}
		if ( -1 === index ) {
			return;
		}

		// Let the browser finish its own focus scrolling first.
		window.requestAnimationFrame( function () {
			var r = event.target.getBoundingClientRect();
			var view = document.documentElement.clientWidth;
			var raw = self.progress();

			if ( raw >= 0 && raw <= 1 && r.left >= 0 && r.right <= view ) {
				return;
			}
			self.scrollToX( self.points[ index ] );
		} );
	};

	/**
	 * After scrolling stops inside the pinned part, settle on the nearest panel.
	 */
	Rail.prototype.snap = function () {
		var raw;
		var x;
		var best;

		if ( 'scroll' !== this.mode || ! this.opts.snap || ! this.max ) {
			return;
		}

		raw = this.progress();
		if ( raw <= 0 || raw >= 1 ) {
			return;
		}

		x = raw * this.max;
		best = this.points.reduce( function ( a, b ) {
			return Math.abs( b - x ) < Math.abs( a - x ) ? b : a;
		}, this.points[ 0 ] );

		if ( Math.abs( ( ( best - x ) / this.max ) * this.dist ) >= 2 ) {
			this.scrollToX( best );
		}
	};

	/**
	 * Re-measure on the next frame (several triggers in one frame run once).
	 */
	Rail.prototype.schedule = function () {
		var self = this;

		if ( this.measuring || this.queued ) {
			return;
		}
		this.queued = true;

		window.requestAnimationFrame( function () {
			self.queued = false;
			if ( instances.get( self.host ) !== self ) {
				return;
			}
			if ( ! self.host.isConnected ) {
				release( self.host );
				return;
			}
			self.refresh();
		} );
	};

	/**
	 * Back to the normal layout: no inline sizes, no panel offsets, no bar.
	 */
	Rail.prototype.reset = function () {
		[ '--wea-hs-top', '--wea-hs-ph', '--wea-hs-th' ].forEach( function ( name ) {
			this.host.style.removeProperty( name );
		}, this );
		this.panels.forEach( function ( panel ) {
			panel.style.translate = '';
		} );
		this.x = 0;
		this.max = 0;
		this.dist = 0;
		this.removeBar();
	};

	Rail.prototype.destroy = function () {
		if ( this.unwatch ) {
			this.unwatch();
			this.unwatch = null;
		}
		this.host.classList.remove( ON_CLASS, SWIPE_CLASS );
		this.reset();
		this.host.removeEventListener( 'focusin', this.onFocus );
		this.host.removeEventListener( 'load', this.onLoad, true );
		if ( this.ro ) {
			this.ro.disconnect();
		}
		if ( this.mo ) {
			this.mo.disconnect();
		}
		this.panels = [];
		this.mode = '';
	};

	/* Registry --------------------------------------------------------------- */

	function release( host ) {
		var inst = instances.get( host );
		if ( inst ) {
			inst.destroy();
			instances.delete( host );
		}
	}

	function sweep() {
		instances.forEach( function ( inst, host ) {
			if ( ! host.isConnected ) {
				release( host );
			}
		} );
	}

	/**
	 * Create, refresh or remove the row of one element. Safe to call any number of times.
	 */
	function sync( host, force ) {
		var inst = instances.get( host );
		var wanted = host.isConnected && host.classList.contains( HOST_CLASS );

		// A re-render in the editor replaces .e-con-inner: start over on the new one.
		if ( inst && ( ! wanted || inst.track !== findTrack( host ) ) ) {
			release( host );
			inst = null;
		}
		if ( ! wanted ) {
			return;
		}
		if ( ! inst ) {
			inst = new Rail( host );
			instances.set( host, inst );
		} else if ( force ) {
			inst.refresh();
		}
		if ( ! inst.unwatch && isEditMode() ) {
			inst.unwatch = watch( host );
		}
	}

	function scan() {
		var hosts = document.querySelectorAll( '.' + HOST_CLASS );
		for ( var i = 0; i < hosts.length; i++ ) {
			sync( hosts[ i ], false );
		}
		sweep();
	}

	function refreshAll() {
		instances.forEach( function ( inst ) {
			inst.refresh();
		} );
	}

	function renderAll() {
		frameQueued = false;
		instances.forEach( function ( inst ) {
			inst.render();
		} );
	}

	function queueRender() {
		if ( ! frameQueued ) {
			frameQueued = true;
			window.requestAnimationFrame( renderAll );
		}
	}

	function snapAll() {
		instances.forEach( function ( inst ) {
			inst.snap();
		} );
	}

	/* Elementor hooks (frontend and editor) --------------------------------- */

	var hooked = false;

	function onReady( $scope ) {
		var el = $scope && ( $scope[ 0 ] || $scope );
		if ( el && 1 === el.nodeType ) {
			sweep();
			sync( el, true );
		}
	}

	// Settings changed in the editor: Elementor re-renders the element's CSS first, so read a moment later.
	function later( el ) {
		if ( pending.has( el ) ) {
			window.clearTimeout( pending.get( el ) );
		}
		pending.set(
			el,
			window.setTimeout( function () {
				pending.delete( el );
				sync( el, true );
			}, 60 )
		);
	}

	function isEditMode() {
		var fe = window.elementorFrontend;
		return !! ( fe && fe.isEditMode && fe.isEditMode() );
	}

	/**
	 * Editor only: follow the element's settings model, so panel edits, undo/redo
	 * and pasted styles all refresh it. Returns a function that stops watching.
	 */
	function watch( host ) {
		var fe = window.elementorFrontend;
		var cid = host.getAttribute( 'data-model-cid' );
		var data = fe && fe.config && fe.config.elements && fe.config.elements.data;
		var model = cid && data ? data[ cid ] : null;
		var handler;

		if ( ! model || 'function' !== typeof model.on ) {
			return null;
		}

		handler = function () {
			later( host );
		};
		model.on( 'change', handler );

		return function () {
			try {
				model.off( 'change', handler );
			} catch ( e ) {
				// The editor is already gone.
			}
		};
	}

	function hookElementor() {
		var fe = window.elementorFrontend;

		if ( hooked || ! fe || ! fe.hooks ) {
			return;
		}
		hooked = true;

		fe.hooks.addAction( 'frontend/element_ready/container', onReady );
		fe.hooks.addAction( 'frontend/element_ready/section', onReady );

		if ( ! isEditMode() ) {
			return;
		}

		// Switching the effect on or off only toggles a class (no re-render): catch that from any source.
		if ( 'MutationObserver' in window ) {
			new window.MutationObserver( function ( records ) {
				records.forEach( function ( record ) {
					var el = record.target;
					if ( el.classList && el.classList.contains( HOST_CLASS ) !== instances.has( el ) ) {
						later( el );
					}
				} );
			} ).observe( document.documentElement, { subtree: true, attributes: true, attributeFilter: [ 'class' ] } );
		}

		// The editor keeps its models when the preview reloads: let go of them with this frame.
		window.addEventListener( 'pagehide', function () {
			instances.forEach( function ( inst, host ) {
				release( host );
			} );
		} );
	}

	/* Global listeners ------------------------------------------------------- */

	var resizeTimer = 0;

	window.addEventListener(
		'scroll',
		function () {
			queueRender();
			if ( ! hasScrollEnd ) {
				window.clearTimeout( scrollEndTimer );
				scrollEndTimer = window.setTimeout( snapAll, 160 );
			}
		},
		{ passive: true }
	);

	if ( hasScrollEnd ) {
		window.addEventListener( 'scrollend', snapAll );
	}

	// Breakpoint changes switch the layout; sizes follow the window.
	window.addEventListener( 'resize', function () {
		window.clearTimeout( resizeTimer );
		resizeTimer = window.setTimeout( refreshAll, 100 );
	} );

	window.addEventListener( 'load', function () {
		scan();
		refreshAll();
	} );

	if ( reducedQuery ) {
		if ( reducedQuery.addEventListener ) {
			reducedQuery.addEventListener( 'change', refreshAll );
		} else if ( reducedQuery.addListener ) {
			reducedQuery.addListener( refreshAll );
		}
	}

	window.addEventListener( 'elementor/frontend/init', hookElementor );
	hookElementor();

	// The script prints in the footer, so the markup is already there: lay out before first paint.
	scan();
	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', scan );
	}
}( window, document ) );
