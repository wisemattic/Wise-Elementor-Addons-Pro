/**
 * Wise Live Copy: "Wise Copy" and "Wise Paste" for the Elementor editor.
 *
 * Copy puts the selected elements on the clipboard as JSON:
 *   { wiseaddons_live_copy: 1, version, type: "elementor", siteurl, source, elements }
 * The "type" and "siteurl" keys make the same text work with Elementor's own
 * "Paste from other site" too.
 *
 * Paste reads the clipboard (or a dialog where the user presses Ctrl/Cmd+V),
 * sends the text to wiseaddons-pro/v1/live-copy/import, which imports images and
 * regenerates IDs, and inserts the result with Elementor's paste command. One undo
 * removes it again.
 *
 * @package Wise_Addons_For_Elementor_Pro
 */
/* global elementor, elementorCommon, $e, wp */
( function () {
	'use strict';

	const root = ( window.WiseAddonsPro = window.WiseAddonsPro || {} );
	const config = root.liveCopyConfig || {};
	const { __, _n, sprintf } = wp.i18n;
	const DOMAIN = 'wise-addons-for-elementor-pro';

	if ( ! config.restPath ) {
		return;
	}

	const ELEMENT_TYPES = [ 'widget', 'column', 'section', 'container', 'e-flexbox', 'e-div-block', 'e-grid' ];
	const isMac = /Mac|iPhone|iPad/.test( navigator.platform || navigator.userAgent || '' );
	const pasteKeys = isMac ? '⌘V' : 'Ctrl+V';

	let busy = false;

	/* ------------------------------------------------------------------ *
	 * Helpers
	 * ------------------------------------------------------------------ */

	function escapeHtml( text ) {
		const span = document.createElement( 'span' );
		span.textContent = String( text );
		return span.innerHTML;
	}

	function notify( message ) {
		try {
			// Elementor sets the toast message as HTML.
			elementor.notifications.showToast( { message: escapeHtml( message ) } );
		} catch ( e ) {
			// Toasts are optional.
		}
	}

	function el( tag, attrs, children ) {
		const node = document.createElement( tag );
		Object.entries( attrs || {} ).forEach( ( [ key, value ] ) => {
			if ( null === value || undefined === value || false === value ) {
				return;
			}
			if ( 'class' === key ) {
				node.className = value;
			} else if ( 'text' === key ) {
				node.textContent = value;
			} else if ( 0 === key.indexOf( 'on' ) && 'function' === typeof value ) {
				node.addEventListener( key.slice( 2 ), value );
			} else {
				node.setAttribute( key, true === value ? '' : value );
			}
		} );
		( children || [] ).forEach( ( child ) => {
			if ( child ) {
				node.append( child );
			}
		} );
		return node;
	}

	function currentDocumentId() {
		try {
			return parseInt( elementor.documents.getCurrent().id, 10 ) || 0;
		} catch ( e ) {
			return 0;
		}
	}

	/**
	 * Does a text look like something Wise Paste can use?
	 *
	 * @param {string} text Clipboard text.
	 * @return {boolean} True for a Live Copy or Elementor payload.
	 */
	function isPayload( text ) {
		if ( 'string' !== typeof text || text.length < 10 || text.length > ( config.maxBytes || 3145728 ) ) {
			return false;
		}
		try {
			const data = JSON.parse( text );
			return !! data && 'object' === typeof data && Array.isArray( data.elements ) && data.elements.length > 0 &&
				( 1 === Number( data[ config.marker ] ) || 'elementor' === data.type );
		} catch ( e ) {
			return false;
		}
	}

	/* ------------------------------------------------------------------ *
	 * Clipboard
	 * ------------------------------------------------------------------ */

	function copyWithTextarea( text ) {
		const area = document.createElement( 'textarea' );
		area.value = text;
		area.setAttribute( 'readonly', '' );
		area.style.position = 'fixed';
		area.style.top = '-9999px';
		area.style.opacity = '0';
		document.body.appendChild( area );
		area.select();
		let ok = false;
		try {
			ok = document.execCommand( 'copy' );
		} catch ( e ) {
			ok = false;
		}
		area.remove();
		return ok;
	}

	async function writeClipboard( text ) {
		if ( navigator.clipboard && window.isSecureContext && navigator.clipboard.writeText ) {
			try {
				await navigator.clipboard.writeText( text );
				return true;
			} catch ( e ) {
				// Fall through to the textarea copy.
			}
		}
		return copyWithTextarea( text );
	}

	async function readClipboard() {
		if ( ! navigator.clipboard || ! window.isSecureContext || ! navigator.clipboard.readText ) {
			return null;
		}
		try {
			return await navigator.clipboard.readText();
		} catch ( e ) {
			return null; // Permission denied or not focused.
		}
	}

	/* ------------------------------------------------------------------ *
	 * Dialog (paste box, progress and manual copy)
	 * ------------------------------------------------------------------ */

	const dialog = {
		node: null,
		box: null,
		title: null,
		text: null,
		area: null,
		error: null,
		status: null,
		primary: null,
		cancel: null,
		mode: '',
		opener: null,
		onSubmit: null,
	};

	function buildDialog() {
		if ( dialog.node ) {
			return;
		}
		dialog.title = el( 'h2', { class: 'wea-live-copy-dialog__title', id: 'wea-live-copy-dialog-title' } );
		dialog.text = el( 'p', { class: 'wea-live-copy-dialog__text', id: 'wea-live-copy-dialog-text' } );
		dialog.area = el( 'textarea', {
			class: 'wea-live-copy-dialog__area',
			rows: '6',
			spellcheck: 'false',
			autocomplete: 'off',
			'aria-labelledby': 'wea-live-copy-dialog-text',
			onpaste: onDialogPaste,
		} );
		dialog.error = el( 'p', { class: 'wea-live-copy-dialog__error', role: 'alert', hidden: true } );
		dialog.status = el( 'p', { class: 'wea-live-copy-dialog__status', role: 'status', 'aria-live': 'polite', hidden: true } );
		dialog.cancel = el( 'button', { type: 'button', class: 'wea-live-copy-dialog__btn', onclick: closeDialog } );
		dialog.primary = el( 'button', { type: 'button', class: 'wea-live-copy-dialog__btn wea-live-copy-dialog__btn--primary', onclick: onDialogPrimary } );
		const close = el( 'button', { type: 'button', class: 'wea-live-copy-dialog__close', 'aria-label': __( 'Close', DOMAIN ), text: '×', onclick: closeDialog } );

		dialog.box = el(
			'div',
			{ class: 'wea-live-copy-dialog__box', role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': 'wea-live-copy-dialog-title', 'aria-describedby': 'wea-live-copy-dialog-text' },
			[
				el( 'div', { class: 'wea-live-copy-dialog__header' }, [ el( 'span', { class: 'wea-live-copy-dialog__logo', 'aria-hidden': 'true' } ), dialog.title, close ] ),
				dialog.text,
				dialog.area,
				dialog.status,
				dialog.error,
				el( 'div', { class: 'wea-live-copy-dialog__actions' }, [ dialog.cancel, dialog.primary ] ),
			]
		);
		dialog.node = el( 'div', { class: 'wea-live-copy-dialog', hidden: true, onmousedown: ( event ) => {
			if ( event.target === dialog.node && ! busy ) {
				closeDialog();
			}
		} }, [ dialog.box ] );
		dialog.node.addEventListener( 'keydown', onDialogKeydown );
		document.body.appendChild( dialog.node );
	}

	function setError( message ) {
		dialog.error.textContent = message || '';
		dialog.error.hidden = ! message;
	}

	function setBusy( isBusy, message ) {
		busy = isBusy;
		dialog.box.classList.toggle( 'is-busy', isBusy );
		dialog.status.textContent = isBusy ? message || '' : '';
		dialog.status.hidden = ! isBusy;
		dialog.primary.disabled = isBusy;
		dialog.area.readOnly = isBusy || 'copy' === dialog.mode;
	}

	/**
	 * Open the dialog.
	 *
	 * @param {Object} options { mode: "paste"|"copy"|"progress", value, error, onSubmit }.
	 */
	function openDialog( options ) {
		buildDialog();
		dialog.mode = options.mode;
		dialog.onSubmit = options.onSubmit || null;
		if ( dialog.node.hidden ) {
			dialog.opener = document.activeElement;
		}

		if ( 'copy' === options.mode ) {
			dialog.title.textContent = __( 'Wise Copy', DOMAIN );
			dialog.text.textContent = sprintf(
				/* translators: %s: keyboard shortcut, e.g. Ctrl+C */
				__( 'The browser blocked the clipboard. Press %s to copy the text below, then use Wise Paste on the other site.', DOMAIN ),
				isMac ? '⌘C' : 'Ctrl+C'
			);
			dialog.primary.textContent = __( 'Done', DOMAIN );
			dialog.cancel.hidden = true;
		} else {
			dialog.title.textContent = __( 'Wise Paste', DOMAIN );
			dialog.text.textContent = sprintf(
				/* translators: %s: keyboard shortcut, e.g. Ctrl+V */
				__( 'Press %s to paste the element you copied with Wise Copy (or Elementor’s Copy) on another site.', DOMAIN ),
				pasteKeys
			);
			dialog.primary.textContent = __( 'Paste', DOMAIN );
			dialog.cancel.hidden = false;
		}
		dialog.cancel.textContent = __( 'Cancel', DOMAIN );
		dialog.area.hidden = 'progress' === options.mode;
		dialog.area.value = options.value || '';
		dialog.area.placeholder = 'copy' === options.mode ? '' : __( 'Paste here…', DOMAIN );
		dialog.primary.hidden = 'progress' === options.mode;
		setError( options.error || '' );
		setBusy( false );

		dialog.node.hidden = false;
		window.requestAnimationFrame( () => {
			if ( 'progress' === options.mode ) {
				dialog.box.setAttribute( 'tabindex', '-1' );
				dialog.box.focus();
				return;
			}
			dialog.area.focus();
			if ( 'copy' === options.mode ) {
				dialog.area.select();
			}
		} );
	}

	function closeDialog() {
		if ( ! dialog.node || dialog.node.hidden || busy ) {
			return;
		}
		dialog.node.hidden = true;
		dialog.area.value = '';
		const opener = dialog.opener;
		dialog.opener = null;
		if ( opener && opener.focus && document.contains( opener ) ) {
			try {
				opener.focus();
			} catch ( e ) {
				// Not critical.
			}
		}
	}

	function onDialogPaste( event ) {
		if ( 'paste' !== dialog.mode || busy ) {
			return;
		}
		const text = event.clipboardData ? event.clipboardData.getData( 'text' ) : '';
		if ( text ) {
			event.preventDefault();
			dialog.area.value = text.length > 2000 ? text.slice( 0, 2000 ) + '…' : text;
			submitDialog( text );
		}
	}

	function onDialogPrimary() {
		if ( 'paste' === dialog.mode ) {
			submitDialog( dialog.area.value );
		} else {
			closeDialog();
		}
	}

	function submitDialog( text ) {
		if ( busy || ! dialog.onSubmit ) {
			return;
		}
		if ( ! isPayload( text ) ) {
			setError( __( 'This is not an element copied with Wise Copy or Elementor. Copy the element again and paste it here.', DOMAIN ) );
			return;
		}
		setError( '' );
		dialog.onSubmit( text );
	}

	function onDialogKeydown( event ) {
		if ( 'Escape' === event.key ) {
			event.preventDefault();
			event.stopPropagation();
			closeDialog();
			return;
		}
		if ( 'Tab' !== event.key ) {
			return;
		}
		// Keep focus inside the dialog.
		const focusable = Array.prototype.filter.call(
			dialog.box.querySelectorAll( 'button, textarea' ),
			( node ) => ! node.hidden && ! node.disabled && node.offsetParent !== null
		);
		if ( ! focusable.length ) {
			event.preventDefault();
			return;
		}
		const first = focusable[ 0 ];
		const last = focusable[ focusable.length - 1 ];
		if ( event.shiftKey && document.activeElement === first ) {
			event.preventDefault();
			last.focus();
		} else if ( ! event.shiftKey && document.activeElement === last ) {
			event.preventDefault();
			first.focus();
		}
	}

	/* ------------------------------------------------------------------ *
	 * Copy
	 * ------------------------------------------------------------------ */

	function buildPayload( containers ) {
		return {
			[ config.marker ]: 1,
			version: config.format || 1,
			type: 'elementor',
			siteurl: elementorCommon.config.urls.rest,
			source: config.siteUrl || '',
			elements: containers.map( ( container ) =>
				container.model.toJSON( { remove: [ 'default', 'editSettings', 'defaultEditSettings' ] } )
			),
		};
	}

	async function copy( containers ) {
		if ( ! containers || ! containers.length ) {
			return;
		}
		const text = JSON.stringify( buildPayload( containers ) );
		const ok = await writeClipboard( text );
		if ( ok ) {
			notify(
				containers.length > 1
					? /* translators: %d: number of elements */ sprintf( __( '%d elements copied. Open the Elementor editor on the other site, right-click and choose “Wise Paste”.', DOMAIN ), containers.length )
					: __( 'Copied. Open the Elementor editor on the other site, right-click and choose “Wise Paste”.', DOMAIN )
			);
		} else {
			openDialog( { mode: 'copy', value: text } );
		}
	}

	/* ------------------------------------------------------------------ *
	 * Paste
	 * ------------------------------------------------------------------ */

	/**
	 * Start a paste.
	 *
	 * @param {Object} target { container, options, view }.
	 */
	async function paste( target ) {
		if ( busy ) {
			return;
		}
		const text = await readClipboard();
		const onSubmit = ( value ) => run( value, target );

		if ( isPayload( text ) ) {
			openDialog( { mode: 'progress', onSubmit } );
			run( text, target );
			return;
		}
		openDialog( { mode: 'paste', onSubmit } );
	}

	async function run( text, target ) {
		setBusy( true, __( 'Preparing the content and importing its images…', DOMAIN ) );

		let response;
		try {
			response = await wp.apiFetch( {
				path: '/' + config.restPath + '/import',
				method: 'POST',
				data: { payload: text, post_id: currentDocumentId() },
			} );
		} catch ( error ) {
			setBusy( false );
			dialog.mode = 'paste';
			dialog.area.hidden = false;
			dialog.primary.hidden = false;
			dialog.primary.textContent = __( 'Paste', DOMAIN );
			setError( ( error && error.message ) || __( 'Something went wrong. Please try again.', DOMAIN ) );
			return;
		}

		const report = ( response && response.report ) || {};
		const elements = stripUnavailable( ( response && response.elements ) || [], report );
		let inserted = false;
		try {
			inserted = elements.length ? await insert( elements, target ) : false;
		} catch ( e ) {
			inserted = false;
		}

		setBusy( false );

		if ( ! inserted ) {
			setError( __( 'The copied element cannot be placed here. Try Wise Paste on another element or on the “+” area.', DOMAIN ) );
			return;
		}

		closeDialog();
		notify( summary( report ) );
	}

	/**
	 * Insert elements with Elementor's paste command.
	 *
	 * @param {Array}  elements Prepared elements.
	 * @param {Object} target   { container, options, view }.
	 * @return {Promise<boolean>} Inserted.
	 */
	async function insert( elements, target ) {
		// Same site URL as this editor, so Elementor does not run its own image import again.
		const data = JSON.stringify( { type: 'elementor', siteurl: elementorCommon.config.urls.rest, elements } );
		const previewContainer = elementor.getPreviewContainer();
		let container = target.container || previewContainer;
		let result = await $e.run( 'document/ui/paste', {
			container,
			storageType: 'rawdata',
			data,
			options: Object.assign( {}, target.options || {} ),
		} );

		if ( ! result && container !== previewContainer ) {
			// Not a valid place inside this element: put it after the top-level element instead.
			let node = container;
			while ( node && node.parent && node.model && node.model.collection !== elementor.elements ) {
				node = node.parent;
			}
			const at = node && node.model && node.model.collection === elementor.elements ? elementor.elements.indexOf( node.model ) + 1 : undefined;
			container = previewContainer;
			result = await $e.run( 'document/ui/paste', {
				container,
				storageType: 'rawdata',
				data,
				options: { at, rebuild: true },
			} );
		}

		if ( result && target.view && 'function' === typeof target.view.onAfterPaste && ! target.view.isDestroyed ) {
			target.view.onAfterPaste();
		}
		return !! result;
	}

	/**
	 * Drop widgets this editor session cannot create (the server checks too).
	 *
	 * @param {Array}  elements Elements.
	 * @param {Object} report   Report, `skipped` is increased.
	 * @return {Array} Elements.
	 */
	function stripUnavailable( elements, report ) {
		return ( elements || [] ).reduce( ( list, element ) => {
			if ( ! element || 'object' !== typeof element ) {
				return list;
			}
			if ( 'widget' === element.elType && ! ( elementor.widgetsCache && elementor.widgetsCache[ element.widgetType ] ) ) {
				report.skipped = ( report.skipped || 0 ) + 1;
				report.skipped_types = ( report.skipped_types || [] ).concat( [ element.widgetType ] );
				return list;
			}
			if ( Array.isArray( element.elements ) && element.elements.length ) {
				element = Object.assign( {}, element, { elements: stripUnavailable( element.elements, report ) } );
			}
			list.push( element );
			return list;
		}, [] );
	}

	function summary( report ) {
		const parts = [ __( 'Pasted.', DOMAIN ) ];
		if ( report.imported ) {
			/* translators: %d: number of images */
			parts.push( sprintf( _n( '%d image imported.', '%d images imported.', report.imported, DOMAIN ), report.imported ) );
		}
		if ( report.reused ) {
			/* translators: %d: number of images */
			parts.push( sprintf( _n( '%d image was already in your media library.', '%d images were already in your media library.', report.reused, DOMAIN ), report.reused ) );
		}
		if ( report.failed ) {
			/* translators: %d: number of images */
			parts.push( sprintf( _n( '%d image could not be imported.', '%d images could not be imported.', report.failed, DOMAIN ), report.failed ) );
		}
		if ( false === report.images_imported ) {
			parts.push( __( 'Images still load from the source site, because your account cannot upload files.', DOMAIN ) );
		}
		if ( report.skipped ) {
			const types = Array.from( new Set( report.skipped_types || [] ) ).slice( 0, 5 ).join( ', ' );
			parts.push(
				sprintf(
					/* translators: 1: number of elements, 2: widget names */
					_n( '%1$d element was left out because its widget is not installed here (%2$s).', '%1$d elements were left out because their widgets are not installed here (%2$s).', report.skipped, DOMAIN ),
					report.skipped,
					types
				)
			);
		}
		return parts.join( ' ' );
	}

	/* ------------------------------------------------------------------ *
	 * Editor integration
	 * ------------------------------------------------------------------ */

	function canEdit( view ) {
		try {
			return ! view.getContainer().isLocked();
		} catch ( e ) {
			return true;
		}
	}

	function elementGroups( groups, view ) {
		const model = view && ( view.model || ( view.options && view.options.model ) );
		if ( ! model || ! Array.isArray( groups ) ) {
			return groups;
		}

		const group = {
			name: 'wea-live-copy',
			actions: [
				{
					name: 'wea-live-copy',
					icon: 'eicon-copy',
					title: __( 'Wise Copy', DOMAIN ),
					isEnabled: () => ! elementor.selection || elementor.selection.isSameType(),
					callback: () => copy( elementor.selection ? elementor.selection.getElements( view.getContainer() ) : [ view.getContainer() ] ),
				},
				{
					name: 'wea-live-paste',
					icon: 'eicon-import-export',
					title: __( 'Wise Paste', DOMAIN ),
					isEnabled: () => canEdit( view ),
					callback: () => paste( { container: view.getContainer() } ),
				},
			],
		};

		const clipboardIndex = groups.findIndex( ( item ) => item && 'clipboard' === item.name );
		if ( -1 === clipboardIndex ) {
			groups.push( group );
		} else {
			groups.splice( clipboardIndex + 1, 0, group );
		}
		return groups;
	}

	function addSectionBehaviors( behaviors, view ) {
		if ( ! behaviors || ! behaviors.contextMenu || ! Array.isArray( behaviors.contextMenu.groups ) ) {
			return behaviors;
		}
		const groups = behaviors.contextMenu.groups;
		if ( groups.some( ( item ) => item && 'wea-live-copy' === item.name ) ) {
			return behaviors;
		}
		const group = {
			name: 'wea-live-copy',
			actions: [
				{
					name: 'wea-live-paste',
					icon: 'eicon-import-export',
					title: __( 'Wise Paste', DOMAIN ),
					callback: () => paste( {
						container: elementor.getPreviewContainer(),
						options: { at: view.getOption( 'at' ), rebuild: true },
						view,
					} ),
				},
			],
		};
		const pasteIndex = groups.findIndex( ( item ) => item && 'paste' === item.name );
		groups.splice( -1 === pasteIndex ? groups.length : pasteIndex + 1, 0, group );
		return behaviors;
	}

	let initialized = false;
	function init() {
		if ( initialized || ! window.elementor || ! elementor.hooks ) {
			return;
		}
		initialized = true;
		ELEMENT_TYPES.forEach( ( type ) => elementor.hooks.addFilter( 'elements/' + type + '/contextMenuGroups', elementGroups ) );
		elementor.hooks.addFilter( 'views/add-section/behaviors', addSectionBehaviors );
	}

	root.liveCopy = { copy, paste };

	// "elementor/loaded" fires before the first document renders, so every view gets the filters.
	window.addEventListener( 'elementor/loaded', init );
	window.addEventListener( 'elementor/init', init );
	if ( window.elementor && window.elementor.hooks ) {
		init();
	}
}() );
