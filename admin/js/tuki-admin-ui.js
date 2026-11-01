/**
 * Tukify admin shell — theme toggle, settings tab switching and Reset.
 *
 * Loaded on the Tukify Dashboard, Settings and Back-in-stock screens.
 *
 * - Theme: the server prints data-theme on .tuki-admin (no flash); the toggle
 *   flips it and saves the pick to user meta (tuki_admin_theme AJAX action).
 * - Settings tabs: every panel is in the DOM inside the one settings form; the
 *   sidebar links (#general, #products, …) only toggle which panel is visible.
 *   The active tab lives in the URL hash, legacy #tab-<key> hashes still work,
 *   and the form's referer field carries the hash so saving lands on the same tab.
 * - Reset: discards unsaved edits by reloading the settings screen.
 */
( function () {
	'use strict';

	var config = window.tukiAdminUi || {};

	function ready( fn ) {
		if ( 'loading' !== document.readyState ) {
			fn();
		} else {
			document.addEventListener( 'DOMContentLoaded', fn );
		}
	}

	ready( function () {
		var root = document.querySelector( '.tuki-admin' );
		if ( ! root ) {
			return;
		}

		initTheme( root );

		if ( 'settings' === root.getAttribute( 'data-tk-screen' ) ) {
			initTabs( root );
			initReset( root );
		}
	} );

	/*
	 * Theme toggle
	 */

	function initTheme( root ) {
		var toggle = document.getElementById( 'tk-theme-switch' );
		if ( ! toggle ) {
			return;
		}

		toggle.addEventListener( 'click', function () {
			var theme = 'light' === root.getAttribute( 'data-theme' ) ? 'dark' : 'light';

			root.setAttribute( 'data-theme', theme );
			toggle.setAttribute( 'aria-checked', 'light' === theme ? 'true' : 'false' );
			document.body.classList.remove( 'tukify-theme-dark', 'tukify-theme-light' );
			document.body.classList.add( 'tukify-theme-' + theme );

			saveTheme( theme );
		} );
	}

	function saveTheme( theme ) {
		if ( ! config.ajaxUrl || ! window.fetch ) {
			return;
		}

		var body = new window.URLSearchParams();
		body.append( 'action', 'tuki_admin_theme' );
		body.append( 'nonce', config.nonce || '' );
		body.append( 'theme', theme );

		// Best effort: the toggle already applied the theme for this page view.
		window.fetch( config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		} ).catch( function () {} );
	}

	/*
	 * Settings tabs
	 */

	function initTabs( root ) {
		var links = Array.prototype.slice.call( root.querySelectorAll( '.tk-tab[data-tab]' ) );
		var panels = Array.prototype.slice.call( root.querySelectorAll( '.tkfy-panel[data-tab]' ) );
		var navs = root.querySelector( '.tk-navs' );
		var scroller = root.querySelector( '.tk-scroll' );
		var title = document.getElementById( 'tk-ptitle' );
		var desc = document.getElementById( 'tk-pdesc' );
		var form = document.getElementById( 'tk-settings-form' );
		var current = '';

		if ( ! links.length || ! panels.length ) {
			return;
		}

		var keys = links.map( function ( link ) {
			return link.getAttribute( 'data-tab' );
		} );

		function keyFromHash() {
			var hash = ( window.location.hash || '' ).replace( /^#/, '' );
			if ( 0 === hash.indexOf( 'tab-' ) ) {
				hash = hash.slice( 4 );
			}
			return -1 !== keys.indexOf( hash ) ? hash : keys[0];
		}

		function activate( key ) {
			if ( -1 === keys.indexOf( key ) ) {
				key = keys[0];
			}

			links.forEach( function ( link ) {
				var active = link.getAttribute( 'data-tab' ) === key;
				if ( active ) {
					link.setAttribute( 'aria-current', 'page' );
					if ( title ) {
						title.textContent = link.getAttribute( 'data-title' );
					}
					if ( desc ) {
						desc.textContent = link.getAttribute( 'data-desc' );
					}
					// Narrow screens: keep the active tab visible in the strip.
					if ( navs && navs.scrollWidth > navs.clientWidth ) {
						navs.scrollLeft = link.offsetLeft - ( navs.clientWidth - link.offsetWidth ) / 2;
					}
				} else {
					link.removeAttribute( 'aria-current' );
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

			if ( current && current !== key && scroller ) {
				scroller.scrollTop = 0;
			}
			current = key;

			// Update the hash WITHOUT jumping to any element.
			if ( window.history && window.history.replaceState && window.location.hash !== '#' + key ) {
				window.history.replaceState( null, '', '#' + key );
			}
		}

		links.forEach( function ( link ) {
			link.addEventListener( 'click', function ( event ) {
				// Let modified clicks open the tab in a new window as a normal link.
				if ( event.defaultPrevented || 0 !== event.button || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey ) {
					return;
				}
				event.preventDefault();
				activate( link.getAttribute( 'data-tab' ) );
			} );
		} );

		// In-page links to another tab (e.g. "#kb") switch tabs too.
		window.addEventListener( 'hashchange', function () {
			activate( keyFromHash() );
		} );

		// Saving redirects back to the referer; carry the active tab with it.
		if ( form ) {
			form.addEventListener( 'submit', function () {
				var referer = form.querySelector( 'input[name="_wp_http_referer"]' );
				if ( referer && current ) {
					referer.value = referer.value.split( '#' )[0] + '#' + current;
				}
			} );
		}

		activate( keyFromHash() );
	}

	/*
	 * Reset: discard unsaved edits
	 */

	function initReset( root ) {
		var form = document.getElementById( 'tk-settings-form' );
		var button = root.querySelector( '[data-tk-reset]' );
		var dirty = false;

		if ( ! form || ! button ) {
			return;
		}

		function markDirty() {
			dirty = true;
		}

		form.addEventListener( 'input', markDirty );
		form.addEventListener( 'change', markDirty );

		// Adding or removing a Q&A / size-band row changes the form without an input event.
		form.addEventListener( 'click', function ( event ) {
			if ( event.target.closest && event.target.closest( '#tuki-kb-add, #tuki-size-add, .tuki-kb-remove, .tuki-size-remove' ) ) {
				markDirty();
			}
		} );

		button.addEventListener( 'click', function () {
			if ( dirty && ! window.confirm( config.resetConfirm || 'Discard your unsaved changes?' ) ) {
				return;
			}
			// Reset first so browsers that restore form state on reload restore the saved values.
			form.reset();
			window.location.reload();
		} );
	}
} )();
