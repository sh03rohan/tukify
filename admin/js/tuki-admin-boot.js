/**
 * Tukify admin — first-paint boot.
 *
 * Printed inline right after the app header (see admin/app-page.php), so it
 * runs while the page is still parsing — before anything is painted:
 *
 * 1. Picks the panel to show from the URL #hash (legacy #tab-<key> too),
 *    falling back to the page's default, and syncs the sidebar + page title.
 *    Panels are shown by CSS from data-tk-tab, so the right one is painted
 *    first instead of flashing the default.
 * 2. Moves WordPress admin notices — printed above the app — to just below
 *    the header, so they appear there directly instead of jumping in later.
 *
 * tuki-admin-ui.js takes over from here once the page has loaded.
 */
( function () {
	'use strict';

	var root = document.querySelector( '.tuki-admin' );
	if ( ! root ) {
		return;
	}

	// 1. Initial panel.
	var links = root.querySelectorAll( '.tk-tab[data-tab]' );
	var keys = [];
	var i;

	for ( i = 0; i < links.length; i++ ) {
		keys.push( links[ i ].getAttribute( 'data-tab' ) );
	}

	var hash = ( window.location.hash || '' ).replace( /^#/, '' );
	if ( 0 === hash.indexOf( 'tab-' ) ) {
		hash = hash.slice( 4 );
	}

	var key = -1 !== keys.indexOf( hash ) ? hash : root.getAttribute( 'data-tk-tab' );
	root.setAttribute( 'data-tk-tab', key );

	var title = document.getElementById( 'tk-ptitle' );
	var desc = document.getElementById( 'tk-pdesc' );

	for ( i = 0; i < links.length; i++ ) {
		if ( links[ i ].getAttribute( 'data-tab' ) === key ) {
			links[ i ].setAttribute( 'aria-current', 'page' );
			if ( title ) {
				title.textContent = links[ i ].getAttribute( 'data-title' );
			}
			if ( desc ) {
				desc.textContent = links[ i ].getAttribute( 'data-desc' );
			}
		} else {
			links[ i ].removeAttribute( 'aria-current' );
		}
	}

	// 2. Notices printed above the app (admin_notices runs before the page).
	var end = root.querySelector( '.wp-header-end' );
	var wrap = document.getElementById( 'wpbody-content' );
	var selector = 'div.notice, div.updated, div.error, div.update-nag';

	if ( ! end || ! wrap ) {
		return;
	}

	var notices = wrap.querySelectorAll( selector );
	var after = end;

	for ( i = 0; i < notices.length; i++ ) {
		var notice = notices[ i ];
		var parent = notice.parentElement;

		// Skip ours, and notices nested inside another notice (they move with it).
		if ( root.contains( notice ) || ( parent && parent !== wrap && parent.closest( selector ) ) ) {
			continue;
		}

		after.parentNode.insertBefore( notice, after.nextSibling );
		after = notice;
	}
} )();
