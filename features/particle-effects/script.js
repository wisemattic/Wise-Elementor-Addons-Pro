/**
 * Wise Particle Effects.
 *
 * A small canvas particle engine for containers and sections that carry the
 * `wea-pe-yes` class. The settings are CSS custom properties written by the
 * controls (see feature.php) and read here with getComputedStyle(), so
 * responsive values follow Elementor's breakpoints and global colors arrive
 * resolved. Anything left empty falls back to the preset.
 *
 * - One requestAnimationFrame loop per element, running only while the element
 *   is near the viewport (IntersectionObserver) and the tab is visible.
 * - Canvas resolution follows devicePixelRatio, capped at 2; particles capped.
 * - prefers-reduced-motion: one still frame, no loop, no pointer effects.
 * - The canvas never takes pointer events; hover and click are read from the
 *   element itself, so links and buttons in the content keep working.
 * - Editor: re-syncs when an element re-renders (element_ready) and when one of
 *   its controls changes. Re-running on the same element never adds a second
 *   canvas, and removed elements release their observers and listeners.
 *
 * Standalone vanilla JS: no jQuery, no dependencies.
 */
( function ( window, document ) {
	'use strict';

	if ( window.WEAParticlesReady ) {
		return;
	}
	window.WEAParticlesReady = true;

	var HOST_CLASS = 'wea-pe-yes';
	var CANVAS_CLASS = 'wea-pe-canvas';
	var MAX_PARTICLES = 400;
	var MAX_PUSHED = 80;
	var REF_AREA = 1280 * 720;
	var TAU = Math.PI * 2;
	var LINK_BUCKETS = 5;

	// Background layers that stay under the particles (the canvas goes after them).
	var BACKGROUNDS = [
		'elementor-background-overlay',
		'elementor-background-video-container',
		'elementor-background-slideshow',
		'elementor-motion-effects-container',
		'elementor-shape',
	];

	/*
	 * Presets. size: diameter range in px. speed: px per frame at 60 fps.
	 * edge: what happens at the border (bounce back or wrap to the other side).
	 */
	var PRESETS = {
		network: {
			count: 80,
			colors: [ '#6d63f5' ],
			size: [ 2, 5 ],
			opacity: [ 0.55, 1 ],
			speed: 0.6,
			dir: 'none',
			shape: 'circle',
			edge: 'bounce',
			links: true,
		},
		bubbles: {
			count: 26,
			colors: [ '#60a5fa', '#a78bfa', '#f472b6', '#34d399' ],
			size: [ 10, 46 ],
			opacity: [ 0.18, 0.55 ],
			speed: 0.55,
			dir: 'top',
			shape: 'circle',
			edge: 'wrap',
			sway: 0.5,
		},
		snow: {
			count: 150,
			colors: [ '#ffffff' ],
			size: [ 2, 7 ],
			opacity: [ 0.45, 1 ],
			speed: 0.9,
			dir: 'bottom',
			shape: 'circle',
			edge: 'wrap',
			sway: 0.7,
			depth: true,
		},
		stars: {
			count: 160,
			colors: [ '#ffffff', '#fff3c4', '#d4e2ff' ],
			size: [ 1, 3.4 ],
			opacity: [ 0.35, 1 ],
			speed: 0.08,
			dir: 'none',
			shape: 'circle',
			edge: 'wrap',
			twinkle: true,
		},
		confetti: {
			count: 70,
			colors: [ '#f43f5e', '#f59e0b', '#10b981', '#3b82f6', '#a855f7', '#facc15' ],
			size: [ 7, 13 ],
			opacity: [ 0.85, 1 ],
			speed: 1.4,
			dir: 'bottom',
			shape: 'confetti',
			edge: 'wrap',
			sway: 1.1,
			spin: true,
		},
	};

	var D = 0.7071;
	var DIRS = {
		top: [ 0, -1 ],
		'top-right': [ D, -D ],
		right: [ 1, 0 ],
		'bottom-right': [ D, D ],
		bottom: [ 0, 1 ],
		'bottom-left': [ -D, D ],
		left: [ -1, 0 ],
		'top-left': [ -D, -D ],
	};
	var SHAPES = [ 'circle', 'square', 'triangle', 'star', 'confetti', 'image' ];
	var HOVERS = [ 'grab', 'repulse', 'bubble' ];

	// Unit shapes (radius 1, centred on 0,0), drawn through a per-particle transform.
	var PATHS = null;

	function paths() {
		if ( PATHS || typeof window.Path2D !== 'function' ) {
			return PATHS;
		}

		var star = new window.Path2D();
		for ( var i = 0; i < 10; i++ ) {
			var r = i % 2 ? 0.45 : 1;
			var a = -Math.PI / 2 + ( i * Math.PI ) / 5;
			star[ i ? 'lineTo' : 'moveTo' ]( Math.cos( a ) * r, Math.sin( a ) * r );
		}
		star.closePath();

		var triangle = new window.Path2D();
		triangle.moveTo( 0, -1 );
		triangle.lineTo( 0.866, 0.5 );
		triangle.lineTo( -0.866, 0.5 );
		triangle.closePath();

		var circle = new window.Path2D();
		circle.arc( 0, 0, 1, 0, TAU );

		var square = new window.Path2D();
		square.rect( -0.85, -0.85, 1.7, 1.7 );

		var strip = new window.Path2D();
		strip.rect( -1, -0.42, 2, 0.84 );

		PATHS = { star: star, triangle: triangle, circle: circle, square: square, strip: strip };
		return PATHS;
	}

	var instances = new Map();
	var pending = new Map();
	var reducedQuery = window.matchMedia ? window.matchMedia( '(prefers-reduced-motion: reduce)' ) : null;

	function rand( a, b ) {
		return a + Math.random() * ( b - a );
	}

	function pick( list ) {
		return list[ Math.floor( Math.random() * list.length ) ];
	}

	function clamp( v, a, b ) {
		if ( v < a ) {
			return a;
		}
		return v > b ? b : v;
	}

	function or( value, fallback ) {
		return null === value ? fallback : value;
	}

	function reduced() {
		return !! ( reducedQuery && reducedQuery.matches );
	}

	/**
	 * Is the page shown at Elementor's mobile size (including mobile extra)?
	 */
	function isMobile() {
		var fe = window.elementorFrontend;
		var max = 767;
		var conf;
		var bps;

		if ( fe && typeof fe.getCurrentDeviceMode === 'function' ) {
			try {
				var mode = fe.getCurrentDeviceMode();
				if ( mode ) {
					return 'mobile' === mode || 'mobile_extra' === mode;
				}
			} catch ( e ) {
				// Not initialised yet: fall back to the configured breakpoints.
			}
		}

		conf = window.elementorFrontendConfig || ( fe && fe.config ) || {};
		bps = conf.responsive && ( conf.responsive.activeBreakpoints || conf.responsive.breakpoints );
		if ( bps && bps.mobile_extra && false !== bps.mobile_extra.is_enabled ) {
			max = bps.mobile_extra.value;
		} else if ( bps && bps.mobile ) {
			max = bps.mobile.value;
		}

		return window.innerWidth <= max;
	}

	/**
	 * Settings from the element's custom properties, merged with its preset.
	 */
	function readOptions( host ) {
		var cs = window.getComputedStyle( host );

		function raw( name ) {
			return cs.getPropertyValue( '--wea-pe-' + name ).trim();
		}

		function num( name ) {
			var v = parseFloat( raw( name ) );
			return isFinite( v ) ? v : null;
		}

		function word( name, allowed ) {
			var v = raw( name ).replace( /^["']|["']$/g, '' );
			return -1 !== allowed.indexOf( v ) ? v : '';
		}

		var name = word( 'preset', Object.keys( PRESETS ) ) || 'network';
		var preset = PRESETS[ name ];
		var colors = [ raw( 'c1' ), raw( 'c2' ), raw( 'c3' ) ].filter( Boolean );
		var lo = or( num( 'size-min' ), preset.size[ 0 ] );
		var hi = or( num( 'size-max' ), preset.size[ 1 ] );
		var top = clamp( or( num( 'opacity' ), preset.opacity[ 1 ] ), 0, 1 );
		var mult = Math.max( 0, or( num( 'speed' ), 1 ) );
		var count = num( 'count' );
		var image = /url\(\s*(['"]?)(.*?)\1\s*\)/.exec( raw( 'img' ) );
		var shape = word( 'shape', SHAPES ) || preset.shape;

		if ( hi < lo ) {
			hi = lo;
		}
		if ( 'image' === shape && ! image ) {
			shape = preset.shape;
		}

		return {
			preset: name,
			count: null === count ? null : clamp( Math.round( count ), 0, MAX_PARTICLES ),
			baseCount: preset.count,
			colors: colors.length ? colors : preset.colors.slice(),
			size: [ Math.max( 0.5, lo ), Math.max( 0.5, hi ) ],
			opacity: [ ( preset.opacity[ 0 ] / preset.opacity[ 1 ] ) * top, top ],
			mult: mult,
			speed: preset.speed * mult,
			dir: word( 'dir', [ 'none' ].concat( Object.keys( DIRS ) ) ) || preset.dir,
			shape: shape,
			image: image ? image[ 2 ] : '',
			edge: preset.edge,
			sway: ( preset.sway || 0 ) * mult,
			spin: !! preset.spin,
			twinkle: !! preset.twinkle,
			depth: !! preset.depth,
			links: !! preset.links,
			linkDist: Math.max( 0, or( num( 'link-dist' ), 140 ) ),
			linkColor: raw( 'link-color' ),
			linkOpacity: clamp( or( num( 'link-opacity' ), 0.45 ), 0, 1 ),
			linkWidth: Math.max( 0.1, or( num( 'link-width' ), 1 ) ),
			hover: word( 'hover', HOVERS ),
			radius: Math.max( 10, or( num( 'radius' ), 120 ) ),
			push: 'push' === raw( 'click' ),
			mobileOff: 'off' === raw( 'mobile' ),
		};
	}

	/**
	 * Put the canvas after the element's own background layers, so the overlay,
	 * video and slideshow stay underneath and the content stays on top.
	 */
	function insertCanvas( host, canvas ) {
		var ref = null;
		var kids = host.children;

		for ( var i = 0; i < kids.length; i++ ) {
			if ( kids[ i ].classList.contains( CANVAS_CLASS ) ) {
				continue;
			}
			for ( var j = 0; j < BACKGROUNDS.length; j++ ) {
				if ( kids[ i ].classList.contains( BACKGROUNDS[ j ] ) ) {
					ref = kids[ i ];
					break;
				}
			}
		}

		host.insertBefore( canvas, ref ? ref.nextSibling : host.firstChild );
	}

	/* Shared observers ------------------------------------------------------ */

	var io = 'IntersectionObserver' in window ?
		new window.IntersectionObserver(
			function ( entries ) {
				entries.forEach( function ( entry ) {
					var inst = instances.get( entry.target );
					if ( inst ) {
						inst.inView = entry.isIntersecting;
						inst.update();
					}
				} );
			},
			{ rootMargin: '100px 0px' }
		) :
		null;

	var ro = 'ResizeObserver' in window ?
		new window.ResizeObserver( function ( entries ) {
			entries.forEach( function ( entry ) {
				var inst = instances.get( entry.target );
				if ( inst ) {
					inst.resize();
				}
			} );
		} ) :
		null;

	/* One particle field ----------------------------------------------------- */

	function Field( host ) {
		var self = this;
		var stale = host.querySelectorAll( ':scope > .' + CANVAS_CLASS );

		// A canvas without an owner (copied markup, cached editor HTML) is removed first.
		for ( var i = 0; i < stale.length; i++ ) {
			stale[ i ].parentNode.removeChild( stale[ i ] );
		}

		this.host = host;
		this.unwatch = null;
		this.canvas = document.createElement( 'canvas' );
		this.canvas.className = CANVAS_CLASS;
		this.canvas.setAttribute( 'aria-hidden', 'true' );
		this.ctx = this.canvas.getContext( '2d' );
		this.list = [];
		this.base = 0;
		this.w = 0;
		this.h = 0;
		this.dpr = 1;
		this.opts = null;
		this.sig = '';
		this.off = false;
		this.inView = ! io;
		this.running = false;
		this.raf = 0;
		this.last = 0;
		this.image = null;
		this.pointer = { active: false, cx: 0, cy: 0, x: 0, y: 0 };
		this.links = [];
		for ( var b = 0; b < LINK_BUCKETS; b++ ) {
			this.links.push( [] );
		}

		this.tick = function ( now ) {
			self.frame( now );
		};
		this.onMove = function ( event ) {
			self.pointer.active = true;
			self.pointer.cx = event.clientX;
			self.pointer.cy = event.clientY;
		};
		this.onLeave = function () {
			self.pointer.active = false;
		};
		this.onClick = function ( event ) {
			self.click( event );
		};

		insertCanvas( host, this.canvas );
		host.addEventListener( 'pointermove', this.onMove, { passive: true } );
		host.addEventListener( 'pointerleave', this.onLeave, { passive: true } );
		host.addEventListener( 'click', this.onClick );

		this.refresh( true );

		if ( io ) {
			io.observe( host );
		}
		if ( ro ) {
			ro.observe( host );
		}
	}

	/**
	 * Re-read the settings; rebuild the particles only when something changed.
	 */
	Field.prototype.refresh = function ( force ) {
		var opts = readOptions( this.host );
		var off = 0 === opts.count || ( opts.mobileOff && isMobile() );
		var sig = JSON.stringify( opts ) + off;

		this.canvas.hidden = off;

		if ( ! force && sig === this.sig ) {
			this.update();
			return;
		}

		this.sig = sig;
		this.opts = opts;
		this.off = off;
		this.loadImage();
		this.measure();
		this.list = [];
		this.base = 0;
		this.fit();
		this.update();
		this.still();
	};

	Field.prototype.loadImage = function () {
		var self = this;
		var url = 'image' === this.opts.shape ? this.opts.image : '';

		if ( ! url ) {
			this.image = null;
			return;
		}
		if ( this.image && this.image.getAttribute( 'src' ) === url ) {
			return;
		}

		this.image = new window.Image();
		this.image.decoding = 'async';
		this.image.onload = function () {
			self.still();
		};
		this.image.src = url;
	};

	/**
	 * Match the canvas to the element. Returns true when the size changed.
	 */
	Field.prototype.measure = function () {
		var w = this.host.clientWidth;
		var h = this.host.clientHeight;
		var dpr = Math.min( window.devicePixelRatio || 1, 2 );
		var sx;
		var sy;

		if ( w === this.w && h === this.h && dpr === this.dpr ) {
			return false;
		}

		sx = this.w ? w / this.w : 1;
		sy = this.h ? h / this.h : 1;
		for ( var i = 0; i < this.list.length; i++ ) {
			this.list[ i ].x *= sx;
			this.list[ i ].y *= sy;
		}

		this.w = w;
		this.h = h;
		this.dpr = dpr;
		this.canvas.width = Math.max( 1, Math.round( w * dpr ) );
		this.canvas.height = Math.max( 1, Math.round( h * dpr ) );
		return true;
	};

	/**
	 * How many particles this element should have right now.
	 */
	Field.prototype.target = function () {
		var o = this.opts;

		if ( this.off || ! this.w || ! this.h ) {
			return 0;
		}
		if ( null !== o.count ) {
			return o.count;
		}

		// Auto: the preset count, scaled to the element's area (phones get fewer).
		return clamp( Math.round( o.baseCount * clamp( ( this.w * this.h ) / REF_AREA, 0.15, 1.6 ) ), 1, MAX_PARTICLES );
	};

	/**
	 * Add or remove regular particles to reach the target; pushed ones stay.
	 */
	Field.prototype.fit = function () {
		var n = this.target();
		var extra = [];

		if ( n > this.base ) {
			for ( var i = this.base; i < n; i++ ) {
				extra.push( this.make() );
			}
			Array.prototype.splice.apply( this.list, [ this.base, 0 ].concat( extra ) );
		} else if ( n < this.base ) {
			this.list.splice( n, this.base - n );
		}

		this.base = n;
	};

	Field.prototype.make = function ( x, y ) {
		var o = this.opts;
		var d = rand( o.size[ 0 ], o.size[ 1 ] );
		var depth = o.size[ 1 ] > o.size[ 0 ] ? ( d - o.size[ 0 ] ) / ( o.size[ 1 ] - o.size[ 0 ] ) : 0.5;
		var speed = o.speed * rand( 0.5, 1 ) * ( o.depth ? 0.55 + depth * 0.9 : 1 );
		var shape = o.shape;
		var opacity = rand( o.opacity[ 0 ], o.opacity[ 1 ] );
		var angle;

		if ( undefined !== x ) {
			// Pushed by a click: burst out in any direction.
			angle = Math.random() * TAU;
			speed = Math.max( speed, 0.6 );
		} else if ( 'none' === o.dir ) {
			angle = Math.random() * TAU;
		} else {
			angle = Math.atan2( DIRS[ o.dir ][ 1 ], DIRS[ o.dir ][ 0 ] ) + rand( -0.25, 0.25 );
		}

		if ( 'confetti' === shape ) {
			shape = pick( [ 'strip', 'strip', 'square', 'circle', 'triangle' ] );
		}

		return {
			x: undefined === x ? Math.random() * this.w : x,
			y: undefined === y ? Math.random() * this.h : y,
			vx: Math.cos( angle ) * speed,
			vy: Math.sin( angle ) * speed,
			r: d / 2,
			br: d / 2,
			o: opacity,
			bo: opacity,
			c: pick( o.colors ),
			s: shape,
			a: Math.random() * TAU,
			va: o.spin ? rand( -0.06, 0.06 ) : rand( -0.008, 0.008 ),
			ph: Math.random() * TAU,
			vph: rand( 0.01, 0.035 ),
			fl: Math.random() * TAU,
			vfl: o.spin ? rand( 0.04, 0.1 ) : 0,
			tw: Math.random() * TAU,
			vtw: rand( 0.015, 0.05 ),
		};
	};

	Field.prototype.resize = function () {
		var empty = ! this.w || ! this.h;

		if ( ! this.opts || ! this.measure() ) {
			return;
		}

		// Particles made while the element had no size all sit at 0,0: start over.
		if ( empty ) {
			this.list = [];
			this.base = 0;
		}

		this.fit();
		this.update();
		this.still();
	};

	Field.prototype.click = function ( event ) {
		var o = this.opts;
		var rect;

		if ( ! o || ! o.push || ! this.running ) {
			return;
		}

		rect = this.host.getBoundingClientRect();
		for ( var i = 0; i < 4; i++ ) {
			this.list.push(
				this.make( event.clientX - rect.left - this.host.clientLeft, event.clientY - rect.top - this.host.clientTop )
			);
		}

		if ( this.list.length - this.base > MAX_PUSHED ) {
			this.list.splice( this.base, this.list.length - this.base - MAX_PUSHED );
		}
	};

	/**
	 * Start or stop the loop to match visibility and settings.
	 */
	Field.prototype.update = function () {
		var o = this.opts;
		var moving = !! o && ( o.speed > 0 || o.sway > 0 || o.twinkle || !! o.hover || o.push );
		var run = !! o && moving && ! this.off && this.inView && ! document.hidden && ! reduced() && this.list.length > 0;

		this.running = run;

		if ( run && ! this.raf ) {
			this.last = 0;
			this.raf = window.requestAnimationFrame( this.tick );
		} else if ( ! run && this.raf ) {
			window.cancelAnimationFrame( this.raf );
			this.raf = 0;
		}
	};

	/**
	 * Draw one frame when the loop is not running (reduced motion, paused, static).
	 */
	Field.prototype.still = function () {
		if ( ! this.running && this.opts ) {
			this.draw();
		}
	};

	Field.prototype.frame = function ( now ) {
		var dt;

		this.raf = 0;

		if ( ! this.running ) {
			return;
		}
		if ( ! this.canvas.isConnected ) {
			release( this.host );
			return;
		}

		dt = this.last ? clamp( ( now - this.last ) / 16.667, 0, 3 ) : 1;
		this.last = now;

		if ( this.pointer.active ) {
			var rect = this.host.getBoundingClientRect();
			this.pointer.x = this.pointer.cx - rect.left - this.host.clientLeft;
			this.pointer.y = this.pointer.cy - rect.top - this.host.clientTop;
		}

		this.step( dt );
		this.draw();
		this.raf = window.requestAnimationFrame( this.tick );
	};

	Field.prototype.step = function ( dt ) {
		var o = this.opts;
		var list = this.list;
		var hover = this.pointer.active ? o.hover : '';
		var px = this.pointer.x;
		var py = this.pointer.y;
		var R = o.radius;
		var R2 = R * R;
		var ease = Math.min( 1, 0.18 * dt );
		var spin = o.mult > 0 ? Math.min( o.mult, 2 ) : 0;

		for ( var i = 0; i < list.length; i++ ) {
			var p = list[ i ];
			var sway = o.sway ? Math.sin( p.ph ) * o.sway * 0.6 : 0;

			p.ph += p.vph * dt;
			p.x += ( p.vx + sway ) * dt;
			p.y += p.vy * dt;
			p.a += p.va * spin * dt;
			p.fl += p.vfl * spin * dt;
			p.tw += p.vtw * dt;

			if ( hover ) {
				var dx = p.x - px;
				var dy = p.y - py;
				var d2 = dx * dx + dy * dy;

				if ( 'repulse' === hover && d2 < R2 && d2 > 0.01 ) {
					var dist = Math.sqrt( d2 );
					var force = 1 - dist / R;
					p.x += ( dx / dist ) * force * force * 10 * dt;
					p.y += ( dy / dist ) * force * force * 10 * dt;
				} else if ( 'bubble' === hover ) {
					var k = d2 < R2 ? 1 - Math.sqrt( d2 ) / R : 0;
					p.r += ( p.br * ( 1 + k * 1.4 ) - p.r ) * ease;
					p.o += ( Math.min( 1, p.bo + k * 0.5 ) - p.o ) * ease;
				}
			} else if ( p.r !== p.br || p.o !== p.bo ) {
				p.r += ( p.br - p.r ) * ease;
				p.o += ( p.bo - p.o ) * ease;
			}

			this.edge( p );
		}
	};

	Field.prototype.edge = function ( p ) {
		var w = this.w;
		var h = this.h;
		var m = p.r * 2 + 2;
		var across = Math.abs( p.vx ) > Math.abs( p.vy );

		if ( 'bounce' === this.opts.edge ) {
			if ( p.x < p.r ) {
				p.x = p.r;
				p.vx = Math.abs( p.vx );
			} else if ( p.x > w - p.r ) {
				p.x = w - p.r;
				p.vx = -Math.abs( p.vx );
			}
			if ( p.y < p.r ) {
				p.y = p.r;
				p.vy = Math.abs( p.vy );
			} else if ( p.y > h - p.r ) {
				p.y = h - p.r;
				p.vy = -Math.abs( p.vy );
			}
			return;
		}

		// Wrap: come back on the other side, somewhere new along that edge.
		if ( p.x < -m ) {
			p.x = w + m;
			if ( across ) {
				p.y = Math.random() * h;
			}
		} else if ( p.x > w + m ) {
			p.x = -m;
			if ( across ) {
				p.y = Math.random() * h;
			}
		}
		if ( p.y < -m ) {
			p.y = h + m;
			if ( ! across ) {
				p.x = Math.random() * w;
			}
		} else if ( p.y > h + m ) {
			p.y = -m;
			if ( ! across ) {
				p.x = Math.random() * w;
			}
		}
	};

	Field.prototype.draw = function () {
		var ctx = this.ctx;
		var o = this.opts;
		var list = this.list;
		var dpr = this.dpr;
		var shapes = paths();
		var img = this.image && this.image.complete && this.image.naturalWidth ? this.image : null;
		var ratio = img ? img.naturalHeight / img.naturalWidth : 1;

		ctx.setTransform( dpr, 0, 0, dpr, 0, 0 );
		ctx.clearRect( 0, 0, this.w, this.h );

		if ( ! list.length ) {
			return;
		}

		if ( o.links && o.linkDist > 0 && o.linkOpacity > 0 ) {
			this.drawLinks( ctx );
		}
		if ( this.pointer.active && 'grab' === o.hover && this.running ) {
			this.drawGrab( ctx );
		}

		for ( var i = 0; i < list.length; i++ ) {
			var p = list[ i ];
			var alpha = p.o;

			if ( o.twinkle ) {
				alpha *= 0.3 + 0.7 * ( 0.5 + 0.5 * Math.sin( p.tw ) );
			}
			if ( alpha <= 0.01 || p.r <= 0 ) {
				continue;
			}

			ctx.globalAlpha = alpha > 1 ? 1 : alpha;

			// Plain circles are the common case: no transform needed.
			if ( 'circle' === p.s ) {
				ctx.setTransform( dpr, 0, 0, dpr, 0, 0 );
				ctx.fillStyle = p.c;
				ctx.beginPath();
				ctx.arc( p.x, p.y, p.r, 0, TAU );
				ctx.fill();
				continue;
			}

			var cos = Math.cos( p.a ) * p.r * dpr;
			var sin = Math.sin( p.a ) * p.r * dpr;
			var flip = p.vfl ? Math.cos( p.fl ) : 1;

			ctx.setTransform( cos, sin, -sin * flip, cos * flip, p.x * dpr, p.y * dpr );

			if ( img && 'image' === o.shape ) {
				ctx.drawImage( img, -1, -ratio, 2, 2 * ratio );
			} else if ( shapes ) {
				// An image that has not loaded yet shows as a dot.
				ctx.fillStyle = p.c;
				ctx.fill( shapes[ p.s ] || shapes.circle );
			} else {
				ctx.fillStyle = p.c;
				ctx.fillRect( -0.85, -0.85, 1.7, 1.7 );
			}
		}

		ctx.setTransform( dpr, 0, 0, dpr, 0, 0 );
		ctx.globalAlpha = 1;
	};

	/**
	 * Network lines. Pairs are grouped into a few opacity steps so each step is
	 * one path and one stroke() call.
	 */
	Field.prototype.drawLinks = function ( ctx ) {
		var o = this.opts;
		var list = this.list;
		var n = list.length;
		var max = o.linkDist;
		var max2 = max * max;
		var buckets = this.links;
		var b;

		for ( b = 0; b < LINK_BUCKETS; b++ ) {
			buckets[ b ].length = 0;
		}

		for ( var i = 0; i < n; i++ ) {
			var a = list[ i ];
			for ( var j = i + 1; j < n; j++ ) {
				var c = list[ j ];
				var dx = a.x - c.x;
				if ( dx > max || dx < -max ) {
					continue;
				}
				var dy = a.y - c.y;
				var d2 = dx * dx + dy * dy;
				if ( d2 < max2 ) {
					b = Math.min( LINK_BUCKETS - 1, Math.floor( ( 1 - Math.sqrt( d2 ) / max ) * LINK_BUCKETS ) );
					buckets[ b ].push( a.x, a.y, c.x, c.y );
				}
			}
		}

		ctx.lineWidth = o.linkWidth;
		ctx.strokeStyle = o.linkColor || o.colors[ 0 ];

		for ( b = 0; b < LINK_BUCKETS; b++ ) {
			var seg = buckets[ b ];
			if ( ! seg.length ) {
				continue;
			}
			ctx.globalAlpha = ( o.linkOpacity * ( b + 1 ) ) / LINK_BUCKETS;
			ctx.beginPath();
			for ( var s = 0; s < seg.length; s += 4 ) {
				ctx.moveTo( seg[ s ], seg[ s + 1 ] );
				ctx.lineTo( seg[ s + 2 ], seg[ s + 3 ] );
			}
			ctx.stroke();
		}
	};

	Field.prototype.drawGrab = function ( ctx ) {
		var o = this.opts;
		var R = o.radius;
		var px = this.pointer.x;
		var py = this.pointer.y;

		ctx.lineWidth = o.linkWidth;
		ctx.strokeStyle = o.linkColor || o.colors[ 0 ];

		for ( var i = 0; i < this.list.length; i++ ) {
			var p = this.list[ i ];
			var dx = p.x - px;
			var dy = p.y - py;
			var d2 = dx * dx + dy * dy;
			if ( d2 < R * R ) {
				ctx.globalAlpha = ( 1 - Math.sqrt( d2 ) / R ) * Math.max( 0.35, o.linkOpacity + 0.2 );
				ctx.beginPath();
				ctx.moveTo( px, py );
				ctx.lineTo( p.x, p.y );
				ctx.stroke();
			}
		}
	};

	Field.prototype.destroy = function () {
		if ( this.unwatch ) {
			this.unwatch();
			this.unwatch = null;
		}
		if ( this.raf ) {
			window.cancelAnimationFrame( this.raf );
		}
		this.raf = 0;
		this.running = false;

		if ( io ) {
			io.unobserve( this.host );
		}
		if ( ro ) {
			ro.unobserve( this.host );
		}

		this.host.removeEventListener( 'pointermove', this.onMove );
		this.host.removeEventListener( 'pointerleave', this.onLeave );
		this.host.removeEventListener( 'click', this.onClick );

		if ( this.canvas.parentNode ) {
			this.canvas.parentNode.removeChild( this.canvas );
		}
		if ( this.image ) {
			this.image.onload = null;
		}

		this.list = [];
		this.image = null;
	};

	/* Registry --------------------------------------------------------------- */

	function release( host ) {
		var inst = instances.get( host );
		if ( inst ) {
			inst.destroy();
			instances.delete( host );
		}
	}

	/**
	 * Create, refresh or remove the field of one element. Safe to call any number of times.
	 */
	function sync( host, force ) {
		var inst = instances.get( host );
		var wanted = host.isConnected && host.classList.contains( HOST_CLASS );

		if ( inst && ( ! wanted || inst.canvas.parentNode !== host ) ) {
			release( host );
			inst = null;
		}
		if ( ! wanted ) {
			return;
		}
		if ( ! inst ) {
			inst = new Field( host );
			instances.set( host, inst );
		} else if ( force ) {
			inst.refresh( false );
		}
		if ( ! inst.unwatch && isEditMode() ) {
			inst.unwatch = watch( host );
		}
	}

	// Elements removed from the page (editor delete, AJAX swaps) let go of everything.
	function sweep() {
		instances.forEach( function ( inst, host ) {
			if ( ! host.isConnected ) {
				release( host );
			}
		} );
	}

	function scan() {
		var hosts = document.querySelectorAll( '.' + HOST_CLASS );
		for ( var i = 0; i < hosts.length; i++ ) {
			sync( hosts[ i ], false );
		}
		sweep();
	}

	function refreshAll( force ) {
		instances.forEach( function ( inst ) {
			inst.refresh( force );
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

	document.addEventListener( 'visibilitychange', function () {
		instances.forEach( function ( inst ) {
			inst.update();
		} );
	} );

	// Breakpoint changes can change the count or the mobile switch.
	window.addEventListener( 'resize', function () {
		window.clearTimeout( resizeTimer );
		resizeTimer = window.setTimeout( function () {
			refreshAll( false );
		}, 150 );
	} );

	if ( reducedQuery ) {
		var onReducedChange = function () {
			refreshAll( true );
		};
		if ( reducedQuery.addEventListener ) {
			reducedQuery.addEventListener( 'change', onReducedChange );
		} else if ( reducedQuery.addListener ) {
			reducedQuery.addListener( onReducedChange );
		}
	}

	window.addEventListener( 'elementor/frontend/init', hookElementor );
	hookElementor();

	// The script prints in the footer, so the markup is already there.
	scan();
	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', scan );
	}
	// Late stylesheets (templates printed in the footer) can change the settings.
	window.addEventListener( 'load', function () {
		scan();
		refreshAll( false );
	} );
}( window, document ) );
