/**
 * Tukify admin app — theme toggle, section switching and Reset.
 *
 * Loaded on the three Tukify screens, which all print the same app: the
 * Dashboard, every settings tab and Back in stock are panels in one DOM.
 *
 * - Theme: the server prints data-theme on .tuki-admin (no flash); the toggle
 *   flips it and saves the pick to user meta (tuki_admin_theme AJAX action).
 * - Sections: tuki-admin-boot.js picks the first panel before paint; from then
 *   on any plain click on a Tukify link — sidebar, the WordPress menu's Tukify
 *   items, links inside panels — switches panels in place, with no reload. The
 *   address bar follows (canonical page + #key, legacy #tab-<key> accepted),
 *   and the form's referer carries it so saving lands on the same section.
 * - Reset: discards unsaved settings edits by reloading.
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
		initSections( root );
		initReset( root );
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
	 * Sections (all ten panels are in the DOM on every Tukify page)
	 */

	// Which panel each Tukify page opens when the URL has no #hash.
	var PAGE_DEFAULT = {
		'tukify': 'dashboard',
		'tukify-settings': 'general',
		'tukify-stock-notify': 'stock'
	};

	// One-shot query args that must not survive a tab switch (a reload would
	// otherwise repeat the action or the notice).
	var DROP_ARGS = [ 'tuki_sn_action', 'id', '_wpnonce', 'settings-updated' ];

	function initSections( root ) {
		var links = Array.prototype.slice.call( root.querySelectorAll( '.tk-tab[data-tab]' ) );
		var scroller = root.querySelector( '.tk-scroll' );
		var navs = root.querySelector( '.tk-navs' );
		var title = document.getElementById( 'tk-ptitle' );
		var desc = document.getElementById( 'tk-pdesc' );
		var form = document.getElementById( 'tk-settings-form' );
		var wpMenu = document.getElementById( 'toplevel_page_tukify' );
		var current = root.getAttribute( 'data-tk-tab' );

		if ( ! links.length || ! window.URL ) {
			return;
		}

		var keys = links.map( function ( link ) {
			return link.getAttribute( 'data-tab' );
		} );

		function linkFor( key ) {
			return links[ keys.indexOf( key ) ];
		}

		function keyFromHash( hash ) {
			hash = ( hash || '' ).replace( /^#/, '' );
			if ( 0 === hash.indexOf( 'tab-' ) ) {
				hash = hash.slice( 4 );
			}
			return -1 !== keys.indexOf( hash ) ? hash : '';
		}

		// Section a link opens in place, or '' when it should navigate normally
		// (another screen, a filter such as kpi_range, a removal link, …).
		function keyForUrl( href ) {
			var url;
			try {
				url = new window.URL( href, window.location.href );
			} catch ( e ) {
				return '';
			}

			var page = url.searchParams.get( 'page' );
			if ( url.origin !== window.location.origin || ! /\/admin\.php$/.test( url.pathname ) || ! PAGE_DEFAULT[ page ] ) {
				return '';
			}

			var extra = false;
			url.searchParams.forEach( function ( value, name ) {
				if ( 'page' !== name ) {
					extra = true;
				}
			} );
			if ( extra ) {
				return '';
			}

			return keyFromHash( url.hash ) || PAGE_DEFAULT[ page ];
		}

		// Address bar = the canonical link of the section (+ any filters), so a
		// reload, a bookmark or the post-save redirect reopens the same panel.
		function syncUrl( key ) {
			if ( ! window.history || ! window.history.replaceState ) {
				return;
			}

			var next = new window.URL( linkFor( key ).href, window.location.href );
			var now = new window.URL( window.location.href );

			now.searchParams.forEach( function ( value, name ) {
				if ( 'page' !== name && -1 === DROP_ARGS.indexOf( name ) ) {
					next.searchParams.set( name, value );
				}
			} );
			next.hash = key;

			if ( next.href !== window.location.href ) {
				window.history.replaceState( null, '', next.href );
			}
		}

		// Keep the WordPress admin menu's Tukify submenu highlight in step.
		function syncWpMenu( key ) {
			if ( ! wpMenu ) {
				return;
			}

			var page = 'dashboard' === key ? 'tukify' : ( 'stock' === key ? 'tukify-stock-notify' : 'tukify-settings' );
			var items = wpMenu.querySelectorAll( '.wp-submenu a' );

			Array.prototype.forEach.call( items, function ( a ) {
				var url = new window.URL( a.href, window.location.href );
				var on = url.searchParams.get( 'page' ) === page;
				a.classList.toggle( 'current', on );
				if ( a.parentNode ) {
					a.parentNode.classList.toggle( 'current', on );
				}
				if ( on ) {
					a.setAttribute( 'aria-current', 'page' );
				} else {
					a.removeAttribute( 'aria-current' );
				}
			} );
		}

		function activate( key ) {
			if ( -1 === keys.indexOf( key ) ) {
				return;
			}

			var changed = key !== current;

			root.setAttribute( 'data-tk-tab', key );

			links.forEach( function ( link ) {
				if ( link.getAttribute( 'data-tab' ) === key ) {
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

			if ( changed && scroller ) {
				scroller.scrollTop = 0;
			}
			current = key;

			syncUrl( key );
			syncWpMenu( key );
		}

		// Plain clicks on Tukify links — the sidebar, the WordPress menu's Tukify
		// items and links inside the panels — switch in place instead of loading.
		document.addEventListener( 'click', function ( event ) {
			if ( event.defaultPrevented || 0 !== event.button || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey ) {
				return;
			}

			var a = event.target.closest ? event.target.closest( 'a[href]' ) : null;
			if ( ! a || ( a.target && '_self' !== a.target ) || ! ( root.contains( a ) || ( wpMenu && wpMenu.contains( a ) ) ) ) {
				return;
			}

			var key = keyForUrl( a.getAttribute( 'href' ) );
			if ( key ) {
				event.preventDefault();
				activate( key );
			}
		} );

		// In-page links to another section (e.g. "#kb") switch too.
		window.addEventListener( 'hashchange', function () {
			var key = keyFromHash( window.location.hash );
			if ( key ) {
				activate( key );
			}
		} );

		// Saving redirects back to the referer; make that the current section.
		if ( form ) {
			form.addEventListener( 'submit', function () {
				var referer = form.querySelector( 'input[name="_wp_http_referer"]' );
				if ( referer ) {
					referer.value = window.location.pathname + window.location.search + '#' + current;
				}
			} );
		}

		// The boot script already picked the panel; sync the URL and WP menu.
		activate( current );
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
