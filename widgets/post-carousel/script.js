/**
 * Wise Post Carousel (Pro): Swiper through elementorFrontend.utils.swiper.
 */
( function () {
	'use strict';

	var NAME = 'wea-post-carousel';

	function pick( map, device, fallback ) {
		return map && map[ device ] !== undefined && map[ device ] !== null ? map[ device ] : fallback;
	}

	function settingsFor( cfg, device ) {
		var view = Math.max( 1, pick( cfg.perView, device, cfg.perView.desktop || 1 ) );
		return {
			slidesPerView: view,
			slidesPerGroup: Math.min( view, Math.max( 1, pick( cfg.perGroup, device, 1 ) ) ),
			spaceBetween: Math.max( 0, pick( cfg.gap, device, 0 ) )
		};
	}

	/**
	 * Per-device values to Swiper's min-width breakpoints, from Elementor's active breakpoints.
	 */
	function breakpointsFor( cfg ) {
		var active = ( window.elementorFrontend && elementorFrontend.config.responsive && elementorFrontend.config.responsive.activeBreakpoints ) || {};
		var list = Object.keys( active ).map( function ( name ) {
			return { name: name, value: active[ name ].value, direction: active[ name ].direction };
		} ).sort( function ( a, b ) {
			return a.value - b.value;
		} );
		var out = {};
		var min = 0;

		list.forEach( function ( bp ) {
			if ( 'min' === bp.direction ) {
				out[ bp.value ] = settingsFor( cfg, bp.name );
				return;
			}
			out[ min ] = settingsFor( cfg, bp.name );
			min = bp.value + 1;
		} );
		out[ min ] = settingsFor( cfg, 'desktop' );
		return out;
	}

	function largest( map ) {
		return Object.keys( map || {} ).reduce( function ( max, key ) {
			return Math.max( max, parseInt( map[ key ], 10 ) || 1 );
		}, 1 );
	}

	function escapeAttr( text ) {
		return String( text ).replace( /&/g, '&amp;' ).replace( /"/g, '&quot;' ).replace( /</g, '&lt;' );
	}

	function setup( root ) {
		var container = root.querySelector( '.wea-post-carousel__swiper' );
		var slides = container ? container.querySelectorAll( '.swiper-slide' ) : [];
		var state = { swiper: null, destroyed: false, events: [] };

		if ( ! container || slides.length < 2 || ! window.elementorFrontend || ! elementorFrontend.utils || ! elementorFrontend.utils.swiper ) {
			return state;
		}

		var cfg = WiseAddons.data( root, NAME, {} );
		cfg.perView = cfg.perView || { desktop: 3 };
		var i18n = ( elementorFrontend.config && elementorFrontend.config.i18n ) || {};
		var prevEl = root.querySelector( '.wea-post-carousel__arrow--prev' );
		var nextEl = root.querySelector( '.wea-post-carousel__arrow--next' );
		var pagEl = root.querySelector( '.wea-post-carousel__pagination' );
		var desktop = settingsFor( cfg, 'desktop' );
		var biggest = largest( cfg.perView );
		var autoplay = cfg.autoplay && ! WiseAddons.reducedMotion();

		var options = {
			slidesPerView: desktop.slidesPerView,
			slidesPerGroup: desktop.slidesPerGroup,
			spaceBetween: desktop.spaceBetween,
			breakpoints: breakpointsFor( cfg ),
			loop: !! cfg.loop && slides.length > biggest,
			speed: cfg.speed || 500,
			centeredSlides: !! cfg.centered,
			watchOverflow: true,
			grabCursor: true,
			a11y: {
				enabled: true,
				prevSlideMessage: i18n.a11yCarouselPrevSlideMessage,
				nextSlideMessage: i18n.a11yCarouselNextSlideMessage,
				firstSlideMessage: i18n.a11yCarouselFirstSlideMessage,
				lastSlideMessage: i18n.a11yCarouselLastSlideMessage
			}
		};

		if ( autoplay ) {
			options.autoplay = {
				delay: cfg.autoplay,
				disableOnInteraction: !! cfg.pauseOnInteraction,
				pauseOnMouseEnter: !! cfg.pauseOnHover
			};
		}

		if ( prevEl && nextEl ) {
			options.navigation = { prevEl: prevEl, nextEl: nextEl };
		}

		if ( pagEl && cfg.pagination && 'none' !== cfg.pagination ) {
			options.pagination = { el: pagEl, type: cfg.pagination, clickable: 'bullets' === cfg.pagination };
			if ( 'bullets' === cfg.pagination ) {
				options.pagination.renderBullet = function ( index, className ) {
					var label = ( i18n.a11yCarouselPaginationBulletMessage || 'Go to slide' ) + ' ' + ( index + 1 );
					return '<button type="button" class="' + className + '" aria-label="' + escapeAttr( label ) + '"></button>';
				};
			}
		}

		function on( target, type, fn ) {
			target.addEventListener( type, fn );
			state.events.push( [ target, type, fn ] );
		}

		// Keyboard focus inside the carousel stops autoplay, so cards do not move under the reader.
		on( root, 'focusin', function () {
			if ( state.swiper && state.swiper.autoplay && state.swiper.autoplay.running ) {
				state.swiper.autoplay.stop();
			}
		} );

		on( root, 'keydown', function ( event ) {
			if ( ! state.swiper || event.target.closest( '.wea-post-carousel__pagination' ) ) {
				return;
			}
			var rtl = state.swiper.rtlTranslate;
			if ( event.key === ( rtl ? 'ArrowRight' : 'ArrowLeft' ) ) {
				event.preventDefault();
				state.swiper.slidePrev();
			} else if ( event.key === ( rtl ? 'ArrowLeft' : 'ArrowRight' ) ) {
				event.preventDefault();
				state.swiper.slideNext();
			}
		} );

		new elementorFrontend.utils.swiper( container, options ).then( function ( swiper ) {
			if ( state.destroyed ) {
				swiper.destroy( true, true );
				return;
			}
			state.swiper = swiper;
			root.classList.add( 'is-ready' );
		} );

		return state;
	}

	function destroy( state ) {
		if ( ! state ) {
			return;
		}
		state.destroyed = true;
		state.events.forEach( function ( e ) {
			e[ 0 ].removeEventListener( e[ 1 ], e[ 2 ] );
		} );
		if ( state.swiper && ! state.swiper.destroyed ) {
			state.swiper.destroy( true, true );
		}
	}

	WiseAddons.onWidget( NAME, function ( el ) {
		destroy( el.__weaPostCarousel );
		el.__weaPostCarousel = null;
		var root = el.querySelector( '.wea-post-carousel' );
		if ( root ) {
			el.__weaPostCarousel = setup( root );
		}
	} );
}() );
