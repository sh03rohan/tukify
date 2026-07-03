/**
 * Tukify settings — tab controller.
 *
 * Loaded ONLY on the Tukify Settings screen. Switches tabs with no page reload,
 * persists the active tab in the URL hash (#tab-<key>) with a localStorage
 * fallback so a refresh — including the redirect after saving — keeps the tab.
 * Follows the ARIA tabs pattern: roving tabindex, arrow/Home/End navigation,
 * Enter/Space (native button) to activate, visible focus.
 */
( function () {
	'use strict';

	var STORAGE_KEY = 'tkfy_active_tab';

	function ready( fn ) {
		if ( 'loading' !== document.readyState ) {
			fn();
		} else {
			document.addEventListener( 'DOMContentLoaded', fn );
		}
	}

	ready( function () {
		var root = document.querySelector( '.tkfy' );
		if ( ! root ) {
			return;
		}

		var tablist = root.querySelector( '.tkfy-tabs' );
		var tabs = Array.prototype.slice.call( root.querySelectorAll( '.tkfy-tab' ) );
		var panels = Array.prototype.slice.call( root.querySelectorAll( '.tkfy-panel' ) );

		if ( ! tabs.length ) {
			return;
		}

		var keys = tabs.map( function ( tab ) {
			return tab.getAttribute( 'data-tab' );
		} );

		function panelFor( key ) {
			return root.querySelector( '.tkfy-panel[data-tab="' + key + '"]' );
		}

		function persist( key ) {
			try {
				window.localStorage.setItem( STORAGE_KEY, key );
			} catch ( e ) {}
			// Update the hash WITHOUT scrolling to any element id.
			if ( window.history && window.history.replaceState ) {
				window.history.replaceState( null, '', '#tab-' + key );
			}
		}

		function activate( key, focusTab ) {
			if ( keys.indexOf( key ) === -1 ) {
				key = keys[0];
			}

			tabs.forEach( function ( tab ) {
				var active = tab.getAttribute( 'data-tab' ) === key;
				tab.classList.toggle( 'is-active', active );
				tab.setAttribute( 'aria-selected', active ? 'true' : 'false' );
				tab.setAttribute( 'tabindex', active ? '0' : '-1' );
				if ( active && focusTab ) {
					tab.focus();
				}
			} );

			panels.forEach( function ( panel ) {
				var active = panel.getAttribute( 'data-tab' ) === key;
				panel.classList.toggle( 'is-active', active );
				if ( active ) {
					panel.removeAttribute( 'hidden' );
				} else {
					panel.setAttribute( 'hidden', '' );
				}
			} );

			persist( key );
		}

		// Click / activation.
		tabs.forEach( function ( tab ) {
			tab.addEventListener( 'click', function () {
				activate( tab.getAttribute( 'data-tab' ), false );
			} );
		} );

		// Keyboard navigation across the tablist.
		if ( tablist ) {
			tablist.addEventListener( 'keydown', function ( event ) {
				var current = keys.indexOf(
					( document.activeElement && document.activeElement.getAttribute )
						? document.activeElement.getAttribute( 'data-tab' )
						: null
				);
				if ( current === -1 ) {
					return;
				}

				var next = null;
				switch ( event.key ) {
					case 'ArrowRight':
					case 'ArrowDown':
						next = ( current + 1 ) % keys.length;
						break;
					case 'ArrowLeft':
					case 'ArrowUp':
						next = ( current - 1 + keys.length ) % keys.length;
						break;
					case 'Home':
						next = 0;
						break;
					case 'End':
						next = keys.length - 1;
						break;
					default:
						return;
				}

				event.preventDefault();
				activate( keys[ next ], true );
			} );
		}

		// Decide the initial tab: URL hash → localStorage → first tab.
		function initialKey() {
			var hash = ( window.location.hash || '' ).replace( /^#/, '' );
			if ( hash.indexOf( 'tab-' ) === 0 ) {
				hash = hash.slice( 4 );
			}
			if ( keys.indexOf( hash ) !== -1 ) {
				return hash;
			}

			try {
				var stored = window.localStorage.getItem( STORAGE_KEY );
				if ( stored && keys.indexOf( stored ) !== -1 ) {
					return stored;
				}
			} catch ( e ) {}

			return keys[0];
		}

		activate( initialKey(), false );
	} );
} )();
