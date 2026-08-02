/**
 * Tukify frontend widget.
 *
 * Vanilla JS, no framework. Renders the global floating chat plus any inline
 * Elementor widgets (chat / search / recommendations), each inside its own
 * Shadow DOM so the store theme's CSS can't leak in. Talks to the tukify/v1 API.
 */
( function () {
	'use strict';

	var cfg = window.tukifyConfig;

	if ( ! cfg ) {
		return;
	}

	var S = cfg.strings || {};

	var ICON = {
		close: '<svg viewBox="0 0 24 24" fill="none"><path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>',
		send: '<svg viewBox="0 0 24 24" fill="none"><path d="M4 12l16-8-6 16-3-7-7-1z" fill="currentColor"/></svg>',
		image: '<svg viewBox="0 0 24 24" fill="none"><rect x="3" y="5" width="18" height="14" rx="2" stroke="currentColor" stroke-width="1.6"/><circle cx="8.5" cy="10" r="1.5" fill="currentColor"/><path d="M5 17l4.5-4.5L13 16l3-3 3 3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>',
		search: '<svg viewBox="0 0 24 24" fill="none"><circle cx="11" cy="11" r="7" stroke="currentColor" stroke-width="1.8"/><path d="M20 20l-3.5-3.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>'
	};

	// Brand mark: the shipped Tukify logo (transparent SVG). Rendered as an <img>
	// so its transparent background lets the round bubble/avatar colour show.
	function logoImg() {
		return cfg.logo ? '<img class="tuki-logo" src="' + cfg.logo + '" alt="" draggable="false" />' : '';
	}

	/* ---------------------------------------------------------------------
	 * Shared helpers
	 * ------------------------------------------------------------------- */

	function el( tag, cls ) {
		var node = document.createElement( tag );
		if ( cls ) {
			node.className = cls;
		}
		return node;
	}

	function api( path, body ) {
		return fetch( cfg.restUrl + path, {
			method: 'POST',
			credentials: 'include',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': cfg.nonce
			},
			body: JSON.stringify( body )
		} ).then( function ( response ) {
			return response.json().then( function ( json ) {
				if ( ! response.ok ) {
					var error = new Error( 'request_failed' );
					error.data = json;
					throw error;
				}
				return json;
			} );
		} );
	}

	function formatPrice( p ) {
		try {
			if ( p.currency && 'number' === typeof p.price_raw ) {
				return new Intl.NumberFormat( undefined, {
					style: 'currency',
					currency: p.currency
				} ).format( p.price_raw );
			}
		} catch ( e ) {}
		return p.price || '';
	}

	function logClick( id ) {
		api( 'event', { type: 'product_click', product_id: id } ).catch( function () {} );
	}

	// -----------------------------------------------------------------------
	// In-chat product detail popup. Mounts an overlay inside the Shadow DOM of
	// the widget the clicked card belongs to (so multiple widgets on a page each
	// get their own), fetches fuller details, and traps focus while open.
	// -----------------------------------------------------------------------
	function openProduct( p, anchorEl ) {
		logClick( p.id );

		// Find the panel/shadow root that owns the card that was clicked.
		var panel = anchorEl && anchorEl.closest ? anchorEl.closest( '.tuki-panel' ) : null;

		if ( ! panel ) {
			// No panel context (shouldn't happen) — fall back to the product page.
			if ( p.url ) {
				window.open( p.url, '_blank', 'noopener' );
			}
			return;
		}

		var previousFocus = ( anchorEl && anchorEl.getRootNode ) ? anchorEl.getRootNode().activeElement : null;

		var overlay = el( 'div', 'tuki-modal' );
		overlay.setAttribute( 'role', 'dialog' );
		overlay.setAttribute( 'aria-modal', 'true' );
		overlay.setAttribute( 'aria-label', p.title || ( S.productDetails || 'Product details' ) );

		var backdrop = el( 'div', 'tuki-modal-backdrop' );
		var sheet = el( 'div', 'tuki-modal-sheet' );

		var closeBtn = el( 'button', 'tuki-modal-close' );
		closeBtn.type = 'button';
		closeBtn.setAttribute( 'aria-label', S.close || 'Close' );
		closeBtn.innerHTML = ICON.close;

		var content = el( 'div', 'tuki-modal-content' );
		content.appendChild( buildModalLoading() );

		sheet.appendChild( closeBtn );
		sheet.appendChild( content );
		overlay.appendChild( backdrop );
		overlay.appendChild( sheet );
		panel.appendChild( overlay );

		// Keep the chat header (Tukify logo + status) visible: start the popup just
		// below it rather than covering the whole panel.
		var head = panel.querySelector( '.tuki-head' );
		if ( head ) {
			overlay.style.top = head.offsetHeight + 'px';
		}

		// Animate in (slide-up + fade) on the next frame so the transition runs.
		requestAnimationFrame( function () {
			overlay.classList.add( 'is-open' );
		} );

		var closed = false;

		function close() {
			if ( closed ) {
				return;
			}
			closed = true;
			document.removeEventListener( 'keydown', onKey, true );
			// Slide/fade out, then remove after the transition.
			overlay.classList.remove( 'is-open' );
			setTimeout( function () {
				if ( overlay.parentNode ) {
					overlay.parentNode.removeChild( overlay );
				}
			}, 200 );
			// Return focus to the card that opened the popup (i.e. back to the chat).
			if ( previousFocus && previousFocus.focus ) {
				try {
					previousFocus.focus();
				} catch ( e ) {}
			}
		}

		function onKey( e ) {
			if ( 'Escape' === e.key || 27 === e.keyCode ) {
				e.preventDefault();
				close();
				return;
			}
			if ( 'Tab' === e.key || 9 === e.keyCode ) {
				trapTab( sheet, e );
			}
		}

		closeBtn.addEventListener( 'click', close );
		backdrop.addEventListener( 'click', close );
		document.addEventListener( 'keydown', onKey, true );

		// Move focus into the dialog.
		closeBtn.focus();

		// Fetch the fuller details and render, or show a graceful message.
		api( 'product', { product_id: p.id } )
			.then( function ( data ) {
				if ( closed ) {
					return;
				}
				content.textContent = '';
				content.appendChild( buildModalBody( data ) );
			} )
			.catch( function () {
				if ( closed ) {
					return;
				}
				content.textContent = '';
				var msg = el( 'div', 'tuki-modal-msg' );
				msg.textContent = S.productUnavailable || 'Sorry, this product is unavailable right now.';
				content.appendChild( msg );
				if ( p.url ) {
					content.appendChild( buildModalLink( p.url ) );
				}
			} );
	}

	function buildModalLoading() {
		var wrap = el( 'div', 'tuki-modal-loading' );
		wrap.setAttribute( 'role', 'status' );
		wrap.appendChild( el( 'span', 'tuki-modal-spinner' ) );
		var t = el( 'span', 'tuki-modal-loading-text' );
		t.textContent = S.loading || 'Loading…';
		wrap.appendChild( t );
		return wrap;
	}

	function buildModalLink( url ) {
		var link = el( 'a', 'tuki-modal-full' );
		link.href = url || '#';
		link.target = '_blank';
		link.rel = 'noopener';
		link.textContent = S.viewFullProduct || 'View full product page';
		return link;
	}

	// Keeps Tab focus inside the dialog.
	function trapTab( container, e ) {
		var focusables = container.querySelectorAll(
			'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])'
		);
		if ( ! focusables.length ) {
			return;
		}
		var first = focusables[0];
		var last = focusables[ focusables.length - 1 ];
		var active = container.getRootNode().activeElement;

		if ( e.shiftKey && active === first ) {
			e.preventDefault();
			last.focus();
		} else if ( ! e.shiftKey && active === last ) {
			e.preventDefault();
			first.focus();
		}
	}

	function buildModalBody( d ) {
		var frag = document.createDocumentFragment();

		// Middle: the only scrollable region (image → thumbs → title → variations →
		// description → attributes). The header and bottom bar stay fixed.
		var mainWrap = el( 'div', 'tuki-modal-main' );
		var scroll = el( 'div', 'tuki-modal-scroll' );

		// -------- Gallery (main image + thumbnail chips) --------
		var gallery = ( d.gallery && d.gallery.length ) ? d.gallery : ( d.image ? [ { full: d.image, thumb: d.image } ] : [] );
		var mainImg = document.createElement( 'img' );
		mainImg.className = 'tuki-modal-image';
		mainImg.alt = d.title || '';
		mainImg.src = gallery.length ? gallery[0].full : '';
		scroll.appendChild( mainImg );

		if ( gallery.length > 1 ) {
			var thumbs = el( 'div', 'tuki-modal-thumbs' );
			gallery.forEach( function ( g, i ) {
				var t = document.createElement( 'img' );
				t.className = 'tuki-modal-thumb' + ( 0 === i ? ' is-active' : '' );
				t.src = g.thumb || g.full;
				t.alt = '';
				t.addEventListener( 'click', function () {
					mainImg.src = g.full;
					thumbs.querySelectorAll( '.tuki-modal-thumb' ).forEach( function ( x ) {
						x.classList.remove( 'is-active' );
					} );
					t.classList.add( 'is-active' );
				} );
				thumbs.appendChild( t );
			} );
			scroll.appendChild( thumbs );
		}

		// -------- Title + price/stock --------
		var title = el( 'h3', 'tuki-modal-title' );
		title.textContent = d.title || '';
		scroll.appendChild( title );

		var meta = el( 'div', 'tuki-modal-meta' );
		var price = el( 'span', 'tuki-modal-price' );
		price.textContent = formatPrice( d );
		var badge = el( 'span', 'tuki-badge' + ( d.stock ? '' : ' out' ) );
		badge.appendChild( el( 'span', 'tuki-badge-dot' ) );
		badge.appendChild( document.createTextNode( d.stock ? ( S.inStock || '' ) : ( S.outOfStock || '' ) ) );
		meta.appendChild( price );
		meta.appendChild( badge );
		scroll.appendChild( meta );

		// -------- Shared purchase state (chips in the scroll drive the fixed bar) --------
		var isVariable = 'variable' === d.type && d.variation_attributes && d.variation_attributes.length;
		var buyable = { id: d.id, variation_id: 0, add_to_cart: !! d.add_to_cart, max_qty: d.max_qty };
		var selected = {};
		var stepper = null;

		// -------- Variation chips --------
		if ( isVariable ) {
			var opts = el( 'div', 'tuki-modal-options' );
			d.variation_attributes.forEach( function ( attr ) {
				var group = el( 'div', 'tuki-modal-optgroup' );
				var name = el( 'span', 'tuki-modal-option-label' );
				name.textContent = attr.label || '';
				group.appendChild( name );
				var chips = el( 'div', 'tuki-modal-chips' );
				attr.options.forEach( function ( o ) {
					var chip = el( 'button', 'tuki-modal-chip' );
					chip.type = 'button';
					chip.textContent = o.label;
					chip.addEventListener( 'click', function () {
						if ( selected[ attr.key ] === o.value ) {
							delete selected[ attr.key ];
							chip.classList.remove( 'is-active' );
						} else {
							selected[ attr.key ] = o.value;
							chips.querySelectorAll( '.tuki-modal-chip' ).forEach( function ( x ) {
								x.classList.remove( 'is-active' );
							} );
							chip.classList.add( 'is-active' );
						}
						refreshAddState();
					} );
					chips.appendChild( chip );
				} );
				group.appendChild( chips );
				opts.appendChild( group );
			} );
			scroll.appendChild( opts );
		}

		// -------- Description --------
		if ( d.description ) {
			var section = el( 'div', 'tuki-modal-section' );
			var sHead = el( 'h4', 'tuki-modal-section-title' );
			sHead.textContent = S.descriptionLabel || 'Description';
			var desc = el( 'p', 'tuki-modal-desc' );
			desc.textContent = d.description;
			section.appendChild( sHead );
			section.appendChild( desc );
			scroll.appendChild( section );
		}

		// -------- Attributes (label/value rows, hairline dividers) --------
		if ( d.attributes && d.attributes.length ) {
			var attrs = el( 'div', 'tuki-modal-attrs' );
			d.attributes.forEach( function ( a ) {
				var row = el( 'div', 'tuki-modal-attr' );
				var lab = el( 'span', 'tuki-modal-attr-label' );
				lab.textContent = a.label || '';
				var val = el( 'span', 'tuki-modal-attr-value' );
				val.textContent = a.value || '';
				row.appendChild( lab );
				row.appendChild( val );
				attrs.appendChild( row );
			} );
			scroll.appendChild( attrs );
		}

		mainWrap.appendChild( scroll );
		mainWrap.appendChild( el( 'div', 'tuki-modal-fade' ) );

		// -------- Fixed bottom bar: quantity stepper + full-width Add to cart --------
		var bar = el( 'div', 'tuki-modal-bar' );
		var errEl = el( 'div', 'tuki-card-error' );
		var barRow = el( 'div', 'tuki-modal-bar-row' );
		var addBtn = el( 'button', 'tuki-add tuki-modal-add' );
		addBtn.type = 'button';
		addBtn.textContent = S.addToCart || '';
		var qtyInput = null;

		function refreshAddState() {
			if ( isVariable ) {
				var match = resolveVariation( d, selected );
				if ( match ) {
					buyable.variation_id = match.variation_id;
					buyable.add_to_cart = !! match.add_to_cart;
					buyable.max_qty = match.max_qty;
					if ( match.price ) {
						price.textContent = match.price;
					}
					if ( match.image ) {
						mainImg.src = match.image;
					}
					setBadge( badge, match.stock );
					if ( stepper ) {
						stepper.setMax( match.max_qty || 0 );
					}
					addBtn.disabled = ! match.add_to_cart;
					addBtn.textContent = match.add_to_cart ? ( S.addToCart || '' ) : ( S.outOfStock || '' );
				} else {
					buyable.variation_id = 0;
					var complete = d.variation_attributes.every( function ( a ) {
						return selected[ a.key ];
					} );
					addBtn.disabled = true;
					addBtn.textContent = complete ? ( S.variationUnavailable || 'Unavailable' ) : ( S.selectOptions || 'Select options' );
				}
			} else {
				addBtn.disabled = ! d.add_to_cart;
				addBtn.textContent = d.add_to_cart ? ( S.addToCart || '' ) : ( S.outOfStock || '' );
			}
		}

		if ( d.add_to_cart || isVariable ) {
			stepper = buildQtyStepper( d.max_qty || 0 );
			qtyInput = stepper.input;
			addBtn.addEventListener( 'click', function () {
				showCardError( bar, '' );
				if ( isVariable && ! buyable.variation_id ) {
					return;
				}
				stepper.setMax( buyable.max_qty || 0 );
				addToCart( buyable, addBtn, qtyInput, bar );
			} );
			barRow.appendChild( stepper.el );
			barRow.appendChild( addBtn );
		} else {
			var oos = el( 'span', 'tuki-oos' );
			oos.textContent = S.outOfStock || '';
			barRow.appendChild( oos );
		}

		bar.appendChild( errEl );
		bar.appendChild( barRow );

		refreshAddState();

		frag.appendChild( mainWrap );
		frag.appendChild( bar );
		return frag;
	}

	// Finds the variation whose attribute map matches the current selection.
	// A variation attribute value of '' means "any", so it matches anything.
	function resolveVariation( d, selected ) {
		if ( ! d.variations || ! d.variations.length ) {
			return null;
		}
		// Every selector must have a choice before we can resolve.
		var complete = d.variation_attributes.every( function ( a ) {
			return selected[ a.key ];
		} );
		if ( ! complete ) {
			return null;
		}
		for ( var i = 0; i < d.variations.length; i++ ) {
			var v = d.variations[ i ];
			var ok = true;
			for ( var k = 0; k < d.variation_attributes.length; k++ ) {
				var key = d.variation_attributes[ k ].key;
				var want = ( v.attributes[ key ] || '' ).toLowerCase();
				var got = ( selected[ key ] || '' ).toLowerCase();
				if ( want && want !== got ) {
					ok = false;
					break;
				}
			}
			if ( ok ) {
				return v;
			}
		}
		return null;
	}

	function setBadge( badgeEl, inStock ) {
		badgeEl.className = 'tuki-badge' + ( inStock ? '' : ' out' );
		badgeEl.textContent = '';
		badgeEl.appendChild( el( 'span', 'tuki-badge-dot' ) );
		badgeEl.appendChild( document.createTextNode( inStock ? ( S.inStock || '' ) : ( S.outOfStock || '' ) ) );
	}

	// A reusable quantity stepper (mirrors the card stepper), with a settable cap.
	function buildQtyStepper( initialMax ) {
		var max = ( 'number' === typeof initialMax && initialMax > 0 ) ? initialMax : 0;
		var stepper = el( 'div', 'tuki-qty' );
		var minus = el( 'button', 'tuki-qty-btn' );
		minus.type = 'button';
		minus.setAttribute( 'aria-label', S.decrease || 'Decrease quantity' );
		minus.textContent = '−';
		var qty = el( 'input', 'tuki-qty-input' );
		qty.type = 'number';
		qty.min = '1';
		qty.step = '1';
		qty.value = '1';
		qty.inputMode = 'numeric';
		qty.setAttribute( 'aria-label', S.quantity || 'Quantity' );
		var plus = el( 'button', 'tuki-qty-btn' );
		plus.type = 'button';
		plus.setAttribute( 'aria-label', S.increase || 'Increase quantity' );
		plus.textContent = '+';

		function clamp() {
			var v = parseInt( qty.value, 10 );
			if ( isNaN( v ) || v < 1 ) {
				v = 1;
			}
			if ( max && v > max ) {
				v = max;
			}
			qty.value = String( v );
			minus.disabled = v <= 1;
			plus.disabled = !! max && v >= max;
		}

		minus.addEventListener( 'click', function () {
			qty.value = String( ( parseInt( qty.value, 10 ) || 1 ) - 1 );
			clamp();
		} );
		plus.addEventListener( 'click', function () {
			qty.value = String( ( parseInt( qty.value, 10 ) || 1 ) + 1 );
			clamp();
		} );
		qty.addEventListener( 'input', clamp );
		qty.addEventListener( 'change', clamp );
		if ( max ) {
			qty.max = String( max );
		}
		clamp();

		stepper.appendChild( minus );
		stepper.appendChild( qty );
		stepper.appendChild( plus );

		return {
			el: stepper,
			input: qty,
			setMax: function ( m ) {
				max = ( 'number' === typeof m && m > 0 ) ? m : 0;
				if ( max ) {
					qty.max = String( max );
				} else {
					qty.removeAttribute( 'max' );
				}
				clamp();
			}
		};
	}

	function refreshCart() {
		try {
			if ( window.jQuery ) {
				window.jQuery( document.body ).trigger( 'wc_fragment_refresh' );
			}
		} catch ( e ) {}
	}

	function showCardError( target, msg ) {
		if ( ! target ) {
			return;
		}
		var err = target.querySelector( '.tuki-card-error' );
		if ( ! err ) {
			err = el( 'div', 'tuki-card-error' );
			target.appendChild( err );
		}
		err.textContent = msg;
	}

	function addToCart( p, btn, qtyInput, errTarget ) {
		if ( btn.disabled ) {
			return;
		}
		var qty = 1;
		if ( qtyInput ) {
			qty = parseInt( qtyInput.value, 10 );
			if ( isNaN( qty ) || qty < 1 ) {
				qty = 1;
			}
		}
		if ( errTarget ) {
			showCardError( errTarget, '' );
		}
		btn.disabled = true;
		btn.textContent = S.adding || '';
		var atcPayload = { product_id: p.id, quantity: qty };
		if ( p.variation_id ) {
			atcPayload.variation_id = p.variation_id;
		}
		api( 'add-to-cart', atcPayload )
			.then( function ( data ) {
				btn.textContent = S.added || '';
				btn.classList.add( 'added' );
				refreshCart();
				try {
					document.dispatchEvent( new CustomEvent( 'tuki:added', {
						detail: {
							id: p.id,
							quantity: qty,
							count: data && data.cart_count,
							total: data && data.cart_total
						}
					} ) );
				} catch ( e ) {}
			} )
			.catch( function ( err ) {
				btn.disabled = false;
				btn.textContent = S.addToCart || '';
				var msg = ( err && err.data && err.data.message ) ? err.data.message : ( S.error || '' );
				showCardError( errTarget, msg );
			} );
	}

	function buildCard( p ) {
		var card = el( 'div', 'tuki-pcard' );
		var notify = null;

		var img = document.createElement( 'img' );
		img.className = 'tuki-pcard-img';
		img.src = p.image || '';
		img.alt = p.title || '';
		img.loading = 'lazy';
		img.setAttribute( 'role', 'button' );
		img.setAttribute( 'tabindex', '0' );
		img.setAttribute( 'aria-label', ( S.viewDetails || 'View details' ) + ( p.title ? ( ': ' + p.title ) : '' ) );
		img.addEventListener( 'click', function () {
			openProduct( p, img );
		} );
		img.addEventListener( 'keydown', function ( e ) {
			if ( 'Enter' === e.key || ' ' === e.key || 13 === e.keyCode || 32 === e.keyCode ) {
				e.preventDefault();
				openProduct( p, img );
			}
		} );

		var body = el( 'div', 'tuki-pcard-body' );

		// Title stays a real link (so it keeps its href for cmd/middle-click to open
		// the product page in a new tab), but a normal click opens the in-chat
		// detail popup instead of navigating.
		var title = document.createElement( 'a' );
		title.className = 'tuki-pcard-title';
		title.href = p.url || '#';
		title.target = '_blank';
		title.rel = 'noopener';
		title.textContent = p.title || '';
		title.addEventListener( 'click', function ( e ) {
			if ( e.metaKey || e.ctrlKey || e.shiftKey || 1 === e.button ) {
				logClick( p.id );
				return;
			}
			e.preventDefault();
			openProduct( p, title );
		} );

		var meta = el( 'div', 'tuki-pcard-meta' );
		var price = el( 'span', 'tuki-pcard-price' );
		price.textContent = formatPrice( p );
		var badge = el( 'span', 'tuki-badge' + ( p.stock ? '' : ' out' ) );
		badge.appendChild( el( 'span', 'tuki-badge-dot' ) );
		badge.appendChild( document.createTextNode( p.stock ? ( S.inStock || '' ) : ( S.outOfStock || '' ) ) );
		meta.appendChild( price );
		meta.appendChild( badge );

		var actions = el( 'div', 'tuki-pcard-actions' );
		if ( p.add_to_cart ) {
			// Cap the stepper at available stock when the product manages it
			// (server sends max_qty); 0/absent means no client-side cap.
			var max = ( 'number' === typeof p.max_qty && p.max_qty > 0 ) ? p.max_qty : 0;

			var stepper = el( 'div', 'tuki-qty' );
			var minus = el( 'button', 'tuki-qty-btn' );
			minus.type = 'button';
			minus.setAttribute( 'aria-label', S.decrease || 'Decrease quantity' );
			minus.textContent = '−';
			var qty = el( 'input', 'tuki-qty-input' );
			qty.type = 'number';
			qty.min = '1';
			qty.step = '1';
			qty.value = '1';
			qty.inputMode = 'numeric';
			qty.setAttribute( 'aria-label', S.quantity || 'Quantity' );
			if ( max ) {
				qty.max = String( max );
			}
			var plus = el( 'button', 'tuki-qty-btn' );
			plus.type = 'button';
			plus.setAttribute( 'aria-label', S.increase || 'Increase quantity' );
			plus.textContent = '+';

			var clampQty = function () {
				var v = parseInt( qty.value, 10 );
				if ( isNaN( v ) || v < 1 ) {
					v = 1;
				}
				if ( max && v > max ) {
					v = max;
				}
				qty.value = String( v );
				minus.disabled = v <= 1;
				plus.disabled = !! max && v >= max;
			};

			minus.addEventListener( 'click', function () {
				qty.value = String( ( parseInt( qty.value, 10 ) || 1 ) - 1 );
				clampQty();
			} );
			plus.addEventListener( 'click', function () {
				qty.value = String( ( parseInt( qty.value, 10 ) || 1 ) + 1 );
				clampQty();
			} );
			qty.addEventListener( 'input', clampQty );
			qty.addEventListener( 'change', clampQty );
			clampQty();

			stepper.appendChild( minus );
			stepper.appendChild( qty );
			stepper.appendChild( plus );

			var add = el( 'button', 'tuki-add' );
			add.type = 'button';
			add.textContent = S.addToCart || '';
			add.addEventListener( 'click', function () {
				addToCart( p, add, qty, body );
			} );
			actions.appendChild( stepper );
			actions.appendChild( add );
		} else if ( p.needs_options ) {
			// Variable / grouped / external product: can't be added by id, so send
			// the shopper to the product page to choose options.
			var opts = el( 'a', 'tuki-add tuki-add--link' );
			opts.href = p.url || '#';
			opts.target = '_blank';
			opts.rel = 'noopener';
			opts.textContent = S.viewProduct || 'View product';
			opts.addEventListener( 'click', function () {
				logClick( p.id );
			} );
			actions.appendChild( opts );
		} else {
			var oos = el( 'span', 'tuki-oos' );
			oos.textContent = S.outOfStock || '';
			actions.appendChild( oos );
			// Offer a back-in-stock alert for genuinely out-of-stock items.
			if ( cfg.stockNotify && cfg.stockNotify.enabled && p.id && ! p.stock ) {
				notify = buildStockNotify( p );
			}
		}

		body.appendChild( title );
		body.appendChild( meta );
		body.appendChild( actions );
		if ( notify ) {
			body.appendChild( notify );
		}
		card.appendChild( img );
		card.appendChild( body );
		return card;
	}

	// Back-in-stock: a compact "Notify me" button that expands into a consented
	// email capture. Only the email is collected, and only with an explicit tick.
	function buildStockNotify( p ) {
		var wrap = el( 'div', 'tuki-notify' );
		var btn = el( 'button', 'tuki-notify-btn' );
		btn.type = 'button';
		btn.textContent = S.notifyStart || 'Notify me when it\'s back';
		btn.addEventListener( 'click', function () {
			showNotifyForm( p, wrap );
		} );
		wrap.appendChild( btn );
		return wrap;
	}

	function showNotifyForm( p, wrap ) {
		wrap.textContent = '';

		var email = el( 'input', 'tuki-notify-input' );
		email.type = 'email';
		email.placeholder = S.notifyEmailPh || '';

		var consentRow = el( 'label', 'tuki-notify-consent' );
		var consent = document.createElement( 'input' );
		consent.type = 'checkbox';
		var consentText = el( 'span', 'tuki-notify-consent-text' );
		consentText.textContent = S.notifyConsent || '';
		consentRow.appendChild( consent );
		consentRow.appendChild( consentText );

		var submit = el( 'button', 'tuki-notify-submit' );
		submit.type = 'button';
		submit.textContent = S.notifySubmit || 'Notify me';

		var err = el( 'div', 'tuki-notify-err' );

		function run() {
			if ( submit.disabled ) {
				return;
			}
			err.textContent = '';
			var em = ( email.value || '' ).trim();
			if ( ! em ) {
				err.textContent = S.notifyNeedEmail || '';
				return;
			}
			// Consent is required before anything is sent or stored.
			if ( ! consent.checked ) {
				err.textContent = S.notifyNeedConsent || '';
				return;
			}
			submit.disabled = true;
			submit.textContent = S.notifySending || '…';
			api( 'stock-notify', { product_id: p.id, email: em, consent: true } )
				.then( function ( data ) {
					wrap.textContent = '';
					var ok = el( 'div', 'tuki-notify-done' );
					ok.textContent = ( data && data.message ) ? data.message : ( S.notifyDone || '' );
					wrap.appendChild( ok );
				} )
				.catch( function ( e ) {
					submit.disabled = false;
					submit.textContent = S.notifySubmit || 'Notify me';
					err.textContent = ( e && e.data && e.data.message ) ? e.data.message : ( S.error || '' );
				} );
		}

		submit.addEventListener( 'click', run );
		email.addEventListener( 'keydown', function ( e ) {
			if ( 13 === e.keyCode ) {
				e.preventDefault();
				run();
			}
		} );

		wrap.appendChild( email );
		wrap.appendChild( consentRow );
		wrap.appendChild( submit );
		wrap.appendChild( err );
	}

	function renderGrid( target, products ) {
		var grid = el( 'div', 'tuki-grid' );
		products.forEach( function ( p ) {
			grid.appendChild( buildCard( p ) );
		} );
		target.appendChild( grid );
		return grid;
	}

	function applyTheme( host, opts ) {
		if ( 'light' === opts.scheme ) {
			host.setAttribute( 'data-scheme', 'light' );
		}
		if ( opts.accent ) {
			host.style.setProperty( '--tuki-accent', opts.accent );
		}
		if ( opts.bubbleBg ) {
			host.style.setProperty( '--tuki-bubble-bg', opts.bubbleBg );
		}
		if ( opts.bg ) {
			host.style.setProperty( '--tuki-bg', opts.bg );
		}
		if ( opts.text ) {
			host.style.setProperty( '--tuki-text', opts.text );
		}
		if ( opts.radius ) {
			host.style.setProperty( '--tuki-radius-lg', opts.radius + 'px' );
		}
		if ( opts.height ) {
			host.style.setProperty( '--tuki-panel-h', opts.height + 'px' );
		}
		if ( opts.columns ) {
			host.style.setProperty( '--tuki-cols', opts.columns );
		}
	}

	function makeShadow( host ) {
		var root = host.attachShadow( { mode: 'open' } );
		var style = document.createElement( 'style' );
		style.textContent = cfg.css || '';
		root.appendChild( style );
		return root;
	}

	/* ---------------------------------------------------------------------
	 * Chat (floating, inline, or launcher)
	 * ------------------------------------------------------------------- */

	function createChat( host, opts, kind ) {
		var root = makeShadow( host );
		applyTheme( host, opts );
		host.setAttribute( 'floating' === kind ? 'data-floating' : 'data-inline', '' );

		var history = [];
		var busy = false;
		var greeted = false;
		var clarifyCount = 0;
		var shownIds = [];
		var upsellShown = false;
		var engaged = false;
		var exitFired = false;
		var reengageFired = false;
		var msgsEl;
		var inputEl;
		var sendBtn;

		var pop = 'inline' !== kind;

		var panel = el( 'div', 'tuki-panel ' + ( 'inline' === kind ? 'tuki-panel--flow' : ( 'launcher' === kind ? 'tuki-panel--overlay' : '' ) ) );

		// Header.
		var head = el( 'div', 'tuki-head' );
		var avatar = el( 'div', 'tuki-head-avatar' );
		avatar.innerHTML = logoImg();
		var meta = el( 'div', 'tuki-head-meta' );
		var title = el( 'div', 'tuki-head-title' );
		title.textContent = opts.heading || S.title || 'Tukify';
		var status = el( 'div', 'tuki-head-status' );
		status.appendChild( el( 'span', 'tuki-head-dot' ) );
		status.appendChild( document.createTextNode( S.online || '' ) );
		meta.appendChild( title );
		meta.appendChild( status );
		head.appendChild( avatar );
		head.appendChild( meta );
		if ( pop ) {
			var close = el( 'button', 'tuki-close' );
			close.type = 'button';
			close.setAttribute( 'aria-label', S.close || 'Close' );
			close.innerHTML = ICON.close;
			close.addEventListener( 'click', function () {
				panel.classList.remove( 'is-open' );
			} );
			head.appendChild( close );
		}

		msgsEl = el( 'div', 'tuki-msgs' );

		var row = el( 'div', 'tuki-input-row' );
		var attach = el( 'button', 'tuki-attach' );
		attach.type = 'button';
		attach.setAttribute( 'aria-label', S.image || 'Upload an image' );
		attach.innerHTML = ICON.image;
		var fileInput = document.createElement( 'input' );
		fileInput.type = 'file';
		fileInput.accept = 'image/png,image/jpeg,image/webp';
		fileInput.style.display = 'none';
		attach.addEventListener( 'click', function () {
			fileInput.click();
		} );
		fileInput.addEventListener( 'change', function () {
			onImagePick( fileInput );
		} );
		inputEl = el( 'textarea', 'tuki-input' );
		inputEl.rows = 1;
		inputEl.placeholder = opts.placeholder || S.placeholder || '';
		inputEl.addEventListener( 'input', autosize );
		inputEl.addEventListener( 'keydown', function ( e ) {
			if ( 13 === e.keyCode && ! e.shiftKey ) {
				e.preventDefault();
				sendMessage();
			}
		} );
		sendBtn = el( 'button', 'tuki-send' );
		sendBtn.type = 'button';
		sendBtn.setAttribute( 'aria-label', S.send || 'Send' );
		sendBtn.innerHTML = ICON.send;
		sendBtn.addEventListener( 'click', function () {
			sendMessage();
		} );
		row.appendChild( attach );
		row.appendChild( fileInput );
		row.appendChild( inputEl );
		row.appendChild( sendBtn );

		// Persistent checkout bar: a footer pinned just above the input, shown ONLY
		// when the live cart has items. It is never a chat message, so its position
		// and visibility never depend on history or scroll.
		var checkoutBar = el( 'button', 'tuki-checkout-bar' );
		checkoutBar.type = 'button';
		checkoutBar.hidden = true;
		var coBarCount = el( 'span', 'tuki-checkout-bar-count' );
		var coBarLabel = el( 'span', 'tuki-checkout-bar-label' );
		coBarLabel.textContent = S.checkoutStart || 'Check out';
		var coBarTotal = el( 'span', 'tuki-checkout-bar-total' );
		checkoutBar.appendChild( coBarCount );
		checkoutBar.appendChild( coBarLabel );
		checkoutBar.appendChild( coBarTotal );
		checkoutBar.addEventListener( 'click', function () {
			startCheckout();
		} );

		panel.appendChild( head );
		panel.appendChild( msgsEl );
		panel.appendChild( checkoutBar );
		panel.appendChild( row );
		root.appendChild( panel );

		// Drive the bar purely from live cart state (count + total).
		function updateCheckoutBar( count, total ) {
			if ( ! cfg.checkout || ! cfg.checkout.enabled ) {
				checkoutBar.hidden = true;
				return;
			}
			count = parseInt( count, 10 ) || 0;
			if ( count < 1 ) {
				checkoutBar.hidden = true;
				return;
			}
			coBarCount.textContent = String( count );
			coBarTotal.textContent = total || '';
			checkoutBar.hidden = false;
		}

		// Ask the server for the authoritative cart state, then refresh the bar.
		function refreshCheckoutBar() {
			if ( ! cfg.checkout || ! cfg.checkout.enabled ) {
				return;
			}
			api( 'cart', {} )
				.then( function ( data ) {
					updateCheckoutBar( data && data.count, data && data.total );
				} )
				.catch( function () {} );
		}

		// First paint from the server-rendered snapshot — no round trip, no history.
		updateCheckoutBar( cfg.checkout && cfg.checkout.count, cfg.checkout && cfg.checkout.total );

		if ( pop ) {
			var launcher = el( 'button', 'tuki-launcher' );
			launcher.type = 'button';
			launcher.setAttribute( 'aria-label', S.open || 'Open' );
			launcher.innerHTML = logoImg();
			launcher.addEventListener( 'click', function () {
				var open = panel.classList.toggle( 'is-open' );
				if ( open ) {
					engaged = true;
					greet();
					// Re-check the live cart every time the chat opens.
					refreshCheckoutBar();
					setTimeout( function () {
						inputEl.focus();
					}, 50 );
				}
			} );
			root.appendChild( launcher );
		} else {
			greet();
			// Inline widgets are always open: confirm cart state on load.
			refreshCheckoutBar();
		}

		if ( 'floating' === kind && cfg.exitIntent && cfg.exitIntent.enabled ) {
			setupExitIntent();
		}

		if ( 'floating' === kind && cfg.reengage && cfg.reengage.enabled ) {
			setupReengage();
		}

		function greet() {
			if ( ! greeted ) {
				greeted = true;
				addBubble( 'bot', S.greeting || '' );
				setTimeout( maybeUpsell, 700 );
			}
		}

		function maybeUpsell() {
			if ( upsellShown || ! cfg.upsellProactive || busy ) {
				return;
			}
			api( 'upsell', {} )
				.then( function ( data ) {
					if ( data && data.products && data.products.length ) {
						upsellShown = true;
						if ( data.message ) {
							addBubble( 'bot', data.message );
						}
						addChatCards( data.products );
					}
				} )
				.catch( function () {} );
		}

		// Cart changed from a chat add-to-cart: update the bar from the fresh
		// count/total in the event, then consider an upsell.
		document.addEventListener( 'tuki:added', function ( e ) {
			if ( e && e.detail ) {
				updateCheckoutBar( e.detail.count, e.detail.total );
			}
			setTimeout( maybeUpsell, 800 );
		} );

		function readCartCount() {
			var match = document.cookie.match( /woocommerce_items_in_cart=(\d+)/ );
			return match ? parseInt( match[1], 10 ) : 0;
		}

		function exitCooldownOk() {
			try {
				var ts = parseInt( window.localStorage.getItem( 'tuki_exit_ts' ) || '0', 10 );
				var cooldown = ( cfg.exitIntent.cooldownHours || 0 ) * 3600000;
				return ( Date.now() - ts ) > cooldown;
			} catch ( e ) {
				return true;
			}
		}

		function fireExit() {
			if ( exitFired || engaged || panel.classList.contains( 'is-open' ) || ! exitCooldownOk() ) {
				return;
			}
			exitFired = true;
			greeted = true; // suppress the standard greeting on this open

			try {
				window.localStorage.setItem( 'tuki_exit_ts', String( Date.now() ) );
			} catch ( e ) {}

			panel.classList.add( 'is-open' );
			var hasCart = readCartCount() > 0;
			addBubble( 'bot', hasCart ? cfg.exitIntent.msgCart : cfg.exitIntent.msgBrowsing );
			setTimeout( function () {
				inputEl.focus();
			}, 50 );

			api( 'event', { type: 'exit_intent_shown' } ).catch( function () {} );
			try {
				document.dispatchEvent( new CustomEvent( 'tuki:exit_intent', { detail: { cart: hasCart } } ) );
			} catch ( e ) {}
		}

		function setupExitIntent() {
			if ( ! exitCooldownOk() ) {
				return;
			}

			var mobile = ( 'ontouchstart' in window ) ||
				( window.matchMedia && window.matchMedia( '(max-width: 768px)' ).matches );

			if ( mobile ) {
				if ( ! cfg.exitIntent.mobile ) {
					return;
				}
				var lastY = window.pageYOffset || 0;
				var lastT = Date.now();
				window.addEventListener( 'scroll', function () {
					var y = window.pageYOffset || 0;
					var t = Date.now();
					if ( ( y - lastY ) < -50 && ( t - lastT ) < 350 && y < 200 ) {
						fireExit();
					}
					lastY = y;
					lastT = t;
				}, { passive: true } );
			} else {
				document.addEventListener( 'mouseout', function ( e ) {
					if ( ! e.relatedTarget && e.clientY <= 0 ) {
						fireExit();
					}
				} );
			}
		}

		/* -----------------------------------------------------------------
		 * Proactive re-engagement — nudge a stalling shopper.
		 *
		 * Triggers on inactivity ("idle") while the shopper is on a page the
		 * owner selected: lingering on checkout/cart, or idle anywhere with
		 * items already in the cart. Fires at most once per session (sessionStorage),
		 * never while the chat is already open or has been used, and is fully
		 * dismissible (the standard close button). It only opens the panel and
		 * drops a friendly message plus an optional coupon — nothing blocking.
		 * ----------------------------------------------------------------- */

		// Whether re-engagement is allowed to fire on THIS page right now.
		function reengageEligible() {
			var re = cfg.reengage;
			if ( ! re || ! re.enabled ) {
				return false;
			}
			var pages = re.pages || [];
			// "any" means every context qualifies; otherwise the current page's
			// server-resolved context must be in the owner's selected list.
			if ( pages.indexOf( 'any' ) === -1 && pages.indexOf( re.context ) === -1 ) {
				return false;
			}
			// On checkout/cart, simply being there (lingering) is the signal. Anywhere
			// else, require items already in the cart so we only nudge real intent.
			if ( 'checkout' === re.context || 'cart' === re.context ) {
				return true;
			}
			return readCartCount() > 0;
		}

		// Frequency cap: once per browsing session, so it never nags.
		function reengageSeen() {
			try {
				return '1' === window.sessionStorage.getItem( 'tuki_reengage_seen' );
			} catch ( e ) {
				return false;
			}
		}

		function markReengageSeen() {
			try {
				window.sessionStorage.setItem( 'tuki_reengage_seen', '1' );
			} catch ( e ) {}
		}

		function fireReengage() {
			// Never nag: bail if already shown this session, if the shopper has
			// engaged the chat, if it is already open, or if we're no longer eligible
			// (e.g. the cart was emptied while idle).
			if ( reengageFired || reengageSeen() || engaged || exitFired || panel.classList.contains( 'is-open' ) ) {
				return;
			}
			if ( ! reengageEligible() ) {
				return;
			}

			reengageFired = true;
			markReengageSeen();
			greeted = true; // suppress the standard greeting on this open

			panel.classList.add( 'is-open' );
			addBubble( 'bot', cfg.reengage.message );
			if ( cfg.reengage.coupon ) {
				addCoupon( cfg.reengage.coupon );
			}
			setTimeout( function () {
				inputEl.focus();
			}, 50 );

			api( 'event', { type: 'reengage_shown' } ).catch( function () {} );
			try {
				document.dispatchEvent( new CustomEvent( 'tuki:reengage', { detail: { context: cfg.reengage.context } } ) );
			} catch ( e ) {}
		}

		function setupReengage() {
			// Nothing to arm if it already fired this session or can't fire here.
			if ( reengageSeen() || ! reengageEligible() ) {
				return;
			}

			var idleMs = Math.max( 5, cfg.reengage.idleSeconds || 45 ) * 1000;
			var timer = null;
			var events = [ 'mousemove', 'mousedown', 'keydown', 'scroll', 'touchstart', 'wheel' ];

			function detach() {
				if ( timer ) {
					clearTimeout( timer );
					timer = null;
				}
				events.forEach( function ( ev ) {
					window.removeEventListener( ev, onActivity, true );
				} );
			}

			function arm() {
				if ( timer ) {
					clearTimeout( timer );
				}
				timer = setTimeout( fireReengage, idleMs );
			}

			function onActivity() {
				// Once it has fired (or the shopper opened the chat), stop watching.
				if ( reengageFired || reengageSeen() || engaged ) {
					detach();
					return;
				}
				arm(); // reset the idle countdown on every interaction
			}

			events.forEach( function ( ev ) {
				window.addEventListener( ev, onActivity, { passive: true, capture: true } );
			} );

			arm(); // start the idle countdown immediately on load
		}

		// A small, dismissible coupon pill with a one-tap copy. We never auto-apply
		// a coupon — the shopper stays in control.
		function addCoupon( code ) {
			var wrap = el( 'div', 'tuki-coupon' );

			var label = el( 'div', 'tuki-coupon-label' );
			label.textContent = S.couponLabel || 'Here\'s a code for you';
			wrap.appendChild( label );

			var row = el( 'div', 'tuki-coupon-row' );
			var codeEl = el( 'span', 'tuki-coupon-code' );
			codeEl.textContent = code;

			var copy = el( 'button', 'tuki-coupon-copy' );
			copy.type = 'button';
			copy.textContent = S.couponCopy || 'Copy code';
			copy.addEventListener( 'click', function () {
				var done = function () {
					copy.textContent = S.couponCopied || 'Copied';
					copy.classList.add( 'is-copied' );
				};
				try {
					if ( navigator.clipboard && navigator.clipboard.writeText ) {
						navigator.clipboard.writeText( code ).then( done, function () {} );
					} else {
						var tmp = document.createElement( 'textarea' );
						tmp.value = code;
						tmp.style.position = 'fixed';
						tmp.style.opacity = '0';
						document.body.appendChild( tmp );
						tmp.focus();
						tmp.select();
						document.execCommand( 'copy' );
						document.body.removeChild( tmp );
						done();
					}
				} catch ( e ) {}
			} );

			row.appendChild( codeEl );
			row.appendChild( copy );
			wrap.appendChild( row );
			msgsEl.appendChild( wrap );
			scrollDown();
		}

		/* -----------------------------------------------------------------
		 * In-chat checkout (feature-flagged; server does all the real work).
		 *
		 * The widget only collects input and renders WooCommerce's own numbers.
		 * Every total, shipping rate, tax line, and the order itself come from the
		 * server, which drives WooCommerce's cart/checkout/order APIs. Payment is
		 * never handled here: offline gateways finish in chat, redirect gateways
		 * send the browser to the gateway, and card gateways hand off to
		 * WooCommerce's secure order-pay page. Anything unsupported falls back to
		 * the normal checkout page.
		 * ----------------------------------------------------------------- */

		function startCheckout() {
			if ( busy ) {
				return;
			}
			engaged = true;
			setBusy( true );
			var typing = addTyping();
			api( 'checkout-state', {} )
				.then( function ( state ) {
					typing.remove();
					setBusy( false );
					if ( ! state || ! state.ok ) {
						addBubble( 'bot', S.checkoutFallback || '' );
						addCheckoutFallback( state && state.fallback_url );
						return;
					}
					if ( state.must_login ) {
						addBubble( 'bot', S.checkoutMustLogin || '' );
						addCheckoutFallback( state.fallback_url );
						return;
					}
					buildCheckout( state );
				} )
				.catch( function ( err ) {
					typing.remove();
					setBusy( false );
					addBubble( 'bot', ( err && err.data && err.data.message ) ? err.data.message : ( S.error || '' ) );
				} );
		}

		function buildReview( review ) {
			var wrap = el( 'div', 'tuki-co-review' );
			var t = el( 'div', 'tuki-co-section-title' );
			t.textContent = S.checkoutReview || 'Your cart';
			wrap.appendChild( t );

			( review.items || [] ).forEach( function ( it ) {
				var row = el( 'div', 'tuki-co-item' );
				if ( it.image ) {
					var img = document.createElement( 'img' );
					img.className = 'tuki-co-thumb';
					img.src = it.image;
					img.alt = it.name || '';
					row.appendChild( img );
				}
				var name = el( 'span', 'tuki-co-item-name' );
				name.textContent = ( it.name || '' ) + ' × ' + it.qty;
				var price = el( 'span', 'tuki-co-item-price' );
				price.textContent = it.total || '';
				row.appendChild( name );
				row.appendChild( price );
				wrap.appendChild( row );
			} );

			( review.totals || [] ).forEach( function ( tt ) {
				var row = el( 'div', 'tuki-co-total' );
				var k = el( 'span', 'tuki-co-total-k' );
				k.textContent = tt.label || '';
				var v = el( 'span', 'tuki-co-total-v' );
				v.textContent = tt.value || '';
				row.appendChild( k );
				row.appendChild( v );
				wrap.appendChild( row );
			} );

			var grand = el( 'div', 'tuki-co-total tuki-co-total--grand' );
			var gk = el( 'span', 'tuki-co-total-k' );
			gk.textContent = S.checkoutTotal || 'Total';
			var gv = el( 'span', 'tuki-co-total-v' );
			gv.textContent = review.total || '';
			grand.appendChild( gk );
			grand.appendChild( gv );
			wrap.appendChild( grand );

			return wrap;
		}

		function buildCheckout( state ) {
			var co = { state: state, fields: {}, selectedShipping: '', selectedPayment: '', busy: false };
			var box = el( 'div', 'tuki-co' );
			co.box = box;

			var head = el( 'div', 'tuki-co-head' );
			head.textContent = S.checkoutTitle || 'Checkout';
			box.appendChild( head );

			co.reviewEl = buildReview( state.review || {} );
			box.appendChild( co.reviewEl );

			var form = el( 'div', 'tuki-co-form' );
			co.form = form;
			var sect = el( 'div', 'tuki-co-section-title' );
			sect.textContent = S.checkoutDetails || 'Shipping details';
			form.appendChild( sect );

			var addr = state.address || {};

			function field( key, label, type, opts ) {
				opts = opts || {};
				var f = el( 'label', 'tuki-co-field' + ( opts.wide ? ' tuki-co-field--wide' : '' ) );
				var l = el( 'span', 'tuki-co-flabel' );
				l.textContent = label || '';
				var input;
				if ( opts.select ) {
					input = el( 'select', 'tuki-co-input' );
					( opts.options || [] ).forEach( function ( o ) {
						var op = document.createElement( 'option' );
						op.value = o.value;
						op.textContent = o.label;
						if ( o.value === ( addr[ key ] || '' ) ) {
							op.selected = true;
						}
						input.appendChild( op );
					} );
				} else {
					input = el( 'input', 'tuki-co-input' );
					input.type = type || 'text';
					input.value = addr[ key ] || '';
				}
				var err = el( 'span', 'tuki-co-err' );
				f.appendChild( l );
				f.appendChild( input );
				f.appendChild( err );
				co.fields[ key ] = { input: input, err: err };
				form.appendChild( f );
			}

			field( 'first_name', S.checkoutFirstName, 'text' );
			field( 'last_name', S.checkoutLastName, 'text' );
			field( 'email', S.checkoutEmail, 'email', { wide: true } );
			field( 'phone', S.checkoutPhone, 'tel', { wide: true } );
			field( 'address_1', S.checkoutAddress1, 'text', { wide: true } );
			field( 'city', S.checkoutCity, 'text' );
			field( 'postcode', S.checkoutPostcode, 'text' );
			field( 'state', S.checkoutState, 'text' );
			field( 'country', S.checkoutCountry, 'text', {
				select: true,
				options: ( state.countries || [] ).map( function ( c ) {
					return { value: c.code, label: c.name };
				} )
			} );

			box.appendChild( form );

			co.optsEl = el( 'div', 'tuki-co-options' );
			box.appendChild( co.optsEl );

			co.generalErr = el( 'div', 'tuki-co-generr' );
			box.appendChild( co.generalErr );

			co.actions = el( 'div', 'tuki-co-actions' );
			co.continueBtn = el( 'button', 'tuki-co-btn' );
			co.continueBtn.type = 'button';
			co.continueBtn.textContent = S.checkoutContinue || 'Continue';
			co.continueBtn.addEventListener( 'click', function () {
				refreshCheckout( co );
			} );
			co.actions.appendChild( co.continueBtn );
			box.appendChild( co.actions );

			msgsEl.appendChild( box );
			scrollDown();
		}

		function collectCheckout( co ) {
			var d = {};
			Object.keys( co.fields ).forEach( function ( k ) {
				d[ k ] = co.fields[ k ].input.value;
			} );
			d.shipping_method = co.selectedShipping || '';
			d.payment_method = co.selectedPayment || '';
			return d;
		}

		function clearCheckoutErrors( co ) {
			co.generalErr.textContent = '';
			Object.keys( co.fields ).forEach( function ( k ) {
				co.fields[ k ].err.textContent = '';
				co.fields[ k ].input.classList.remove( 'is-err' );
			} );
			if ( co.shipErr ) {
				co.shipErr.textContent = '';
			}
			if ( co.payErr ) {
				co.payErr.textContent = '';
			}
		}

		// Sends the current address + shipping choice to the server, which recalcs
		// through WooCommerce and returns fresh totals + options.
		function refreshCheckout( co ) {
			if ( co.busy ) {
				return;
			}
			co.busy = true;
			clearCheckoutErrors( co );
			co.continueBtn.disabled = true;
			var prev = co.continueBtn.textContent;
			co.continueBtn.textContent = S.loading || '…';

			api( 'checkout-update', collectCheckout( co ) )
				.then( function ( state ) {
					co.busy = false;
					co.continueBtn.disabled = false;
					co.continueBtn.textContent = prev;
					if ( ! state || ! state.ok ) {
						addBubble( 'bot', S.checkoutFallback || '' );
						addCheckoutFallback( state && state.fallback_url );
						return;
					}
					co.state = state;
					renderCheckoutOptions( co, state );
				} )
				.catch( function ( err ) {
					co.busy = false;
					co.continueBtn.disabled = false;
					co.continueBtn.textContent = prev;
					co.generalErr.textContent = ( err && err.data && err.data.message ) ? err.data.message : ( S.error || '' );
				} );
		}

		function renderCheckoutOptions( co, state ) {
			co.optsEl.textContent = '';

			// Swap in the freshly-calculated review (totals may have changed).
			if ( co.reviewEl && state.review ) {
				var fresh = buildReview( state.review );
				co.reviewEl.replaceWith( fresh );
				co.reviewEl = fresh;
			}

			if ( state.needs_shipping && state.shipping && state.shipping.rates && state.shipping.rates.length ) {
				if ( ! co.selectedShipping ) {
					co.selectedShipping = state.shipping.chosen || state.shipping.rates[0].id;
				}
				var shipTitle = el( 'div', 'tuki-co-section-title' );
				shipTitle.textContent = S.checkoutShipping || 'Shipping method';
				co.optsEl.appendChild( shipTitle );
				var shipGroup = el( 'div', 'tuki-co-radios' );
				state.shipping.rates.forEach( function ( r ) {
					var row = el( 'label', 'tuki-co-radio' );
					var input = document.createElement( 'input' );
					input.type = 'radio';
					input.name = 'tuki-ship';
					input.value = r.id;
					input.checked = ( r.id === co.selectedShipping );
					input.addEventListener( 'change', function () {
						co.selectedShipping = r.id;
						refreshCheckout( co );
					} );
					var txt = el( 'span', 'tuki-co-radio-text' );
					txt.textContent = r.label + ' — ' + r.cost;
					row.appendChild( input );
					row.appendChild( txt );
					shipGroup.appendChild( row );
				} );
				co.optsEl.appendChild( shipGroup );
				co.shipErr = el( 'div', 'tuki-co-err' );
				co.optsEl.appendChild( co.shipErr );
			}

			if ( state.needs_payment && state.payment && state.payment.length ) {
				var payTitle = el( 'div', 'tuki-co-section-title' );
				payTitle.textContent = S.checkoutPayment || 'Payment method';
				co.optsEl.appendChild( payTitle );
				var payGroup = el( 'div', 'tuki-co-radios' );
				state.payment.forEach( function ( p, idx ) {
					if ( ! co.selectedPayment && 0 === idx ) {
						co.selectedPayment = p.id;
					}
					var row = el( 'label', 'tuki-co-radio' );
					var input = document.createElement( 'input' );
					input.type = 'radio';
					input.name = 'tuki-pay';
					input.value = p.id;
					input.checked = ( p.id === co.selectedPayment );
					input.addEventListener( 'change', function () {
						co.selectedPayment = p.id;
					} );
					var txt = el( 'span', 'tuki-co-radio-text' );
					txt.textContent = p.title;
					row.appendChild( input );
					row.appendChild( txt );
					// Gateways that need their own secure UI finish on the next screen.
					if ( ! p.in_chat ) {
						var note = el( 'span', 'tuki-co-paynote' );
						note.textContent = S.checkoutPayNote || '';
						row.appendChild( note );
					}
					payGroup.appendChild( row );
				} );
				co.optsEl.appendChild( payGroup );
				co.payErr = el( 'div', 'tuki-co-err' );
				co.optsEl.appendChild( co.payErr );
			}

			if ( ! co.placeBtn ) {
				co.placeBtn = el( 'button', 'tuki-co-btn tuki-co-btn--primary' );
				co.placeBtn.type = 'button';
				co.placeBtn.textContent = S.checkoutPlace || 'Place order';
				co.placeBtn.addEventListener( 'click', function () {
					placeOrder( co );
				} );
				co.actions.appendChild( co.placeBtn );
			}
			scrollDown();
		}

		function placeOrder( co ) {
			if ( co.busy ) {
				return;
			}
			co.busy = true;
			clearCheckoutErrors( co );
			co.placeBtn.disabled = true;
			co.placeBtn.textContent = S.checkoutPlacing || '…';

			var payload = collectCheckout( co );
			payload.checkout_nonce = ( cfg.checkout && cfg.checkout.nonce ) || '';

			api( 'checkout-place', payload )
				.then( function ( res ) {
					co.busy = false;
					handlePlaceResult( co, res );
				} )
				.catch( function ( err ) {
					co.busy = false;
					co.placeBtn.disabled = false;
					co.placeBtn.textContent = S.checkoutPlace || 'Place order';
					co.generalErr.textContent = ( err && err.data && err.data.message ) ? err.data.message : ( S.error || '' );
				} );
		}

		function handlePlaceResult( co, res ) {
			var r = res && res.result;

			if ( 'error' === r ) {
				showCheckoutFieldErrors( co, res.errors || {} );
				co.placeBtn.disabled = false;
				co.placeBtn.textContent = S.checkoutPlace || 'Place order';
				co.generalErr.textContent = S.checkoutError || '';
				scrollDown();
				return;
			}

			if ( 'placed' === r ) {
				finalizeCheckout( co );
				addOrderPlaced( res.order, res.view_url );
				return;
			}

			if ( 'redirect' === r || 'handoff' === r ) {
				finalizeCheckout( co );
				addBubble( 'bot', 'redirect' === r ? ( S.checkoutRedirecting || '' ) : ( S.checkoutPayNote || '' ) );
				if ( res.redirect ) {
					addCheckoutFallback( res.redirect, S.checkoutContinue );
					// Give the message a beat to render, then navigate to the gateway
					// / order-pay page in the same tab.
					setTimeout( function () {
						window.location.assign( res.redirect );
					}, 700 );
				}
				return;
			}

			// fallback (or anything unexpected) → the normal checkout page.
			finalizeCheckout( co );
			addBubble( 'bot', S.checkoutFallback || '' );
			( ( res && res.details ) || [] ).forEach( function ( d ) {
				addBubble( 'bot', d );
			} );
			addCheckoutFallback( res && res.redirect );
		}

		function showCheckoutFieldErrors( co, errors ) {
			Object.keys( errors ).forEach( function ( k ) {
				if ( co.fields[ k ] ) {
					co.fields[ k ].err.textContent = errors[ k ];
					co.fields[ k ].input.classList.add( 'is-err' );
				} else if ( 'shipping_method' === k && co.shipErr ) {
					co.shipErr.textContent = errors[ k ];
				} else if ( 'payment_method' === k && co.payErr ) {
					co.payErr.textContent = errors[ k ];
				}
			} );
		}

		function finalizeCheckout( co ) {
			co.box.classList.add( 'is-done' );
			if ( co.actions ) {
				co.actions.remove();
			}
			Object.keys( co.fields ).forEach( function ( k ) {
				co.fields[ k ].input.disabled = true;
			} );
			// The order emptied the cart — hide the bar and reconcile with the server.
			updateCheckoutBar( 0, '' );
			refreshCheckoutBar();
		}

		function addOrderPlaced( order, url ) {
			var card = el( 'div', 'tuki-co-placed' );
			var t = el( 'div', 'tuki-co-placed-title' );
			t.textContent = S.checkoutPlaced || 'Order placed';
			card.appendChild( t );
			if ( order ) {
				var line = el( 'div', 'tuki-co-placed-line' );
				line.textContent = ( S.orderTitle || 'Order #%s' ).replace( '%s', order.number ) +
					' · ' + ( order.status || '' ) + ' · ' + ( order.total || '' );
				card.appendChild( line );
			}
			if ( url ) {
				var link = el( 'a', 'tuki-co-btn' );
				link.href = url;
				link.textContent = S.checkoutViewOrder || 'View your order';
				card.appendChild( link );
			}
			msgsEl.appendChild( card );
			scrollDown();
		}

		function addCheckoutFallback( url, label ) {
			if ( ! url ) {
				return;
			}
			var wrap = el( 'div', 'tuki-co-actions' );
			var link = el( 'a', 'tuki-co-btn' );
			link.href = url;
			link.textContent = label || S.checkoutFallbackBtn || 'Go to checkout';
			wrap.appendChild( link );
			msgsEl.appendChild( wrap );
			scrollDown();
		}

		function autosize() {
			inputEl.style.height = 'auto';
			inputEl.style.height = Math.min( 96, inputEl.scrollHeight ) + 'px';
		}

		function scrollDown() {
			msgsEl.scrollTop = msgsEl.scrollHeight;
		}

		function addBubble( role, text ) {
			var bubble = el( 'div', 'tuki-msg ' + role );
			bubble.textContent = text;
			msgsEl.appendChild( bubble );
			scrollDown();
			return bubble;
		}

		function addTyping() {
			var bubble = el( 'div', 'tuki-msg bot' );
			var dots = el( 'span', 'tuki-typing' );
			dots.innerHTML = '<span></span><span></span><span></span>';
			bubble.appendChild( dots );
			msgsEl.appendChild( bubble );
			scrollDown();
			return bubble;
		}

		function setBusy( state ) {
			busy = state;
			sendBtn.disabled = state;
		}

		function addComparison( cmp ) {
			var wrap = el( 'div', 'tuki-compare' );
			var scroll = el( 'div', 'tuki-compare-scroll' );
			var table = document.createElement( 'table' );
			table.className = 'tuki-compare-table';

			var head = document.createElement( 'tr' );
			head.appendChild( document.createElement( 'th' ) );
			cmp.products.forEach( function ( p ) {
				shownIds.push( p.id );
				var th = document.createElement( 'th' );
				th.className = 'tuki-compare-col';

				var img = document.createElement( 'img' );
				img.className = 'tuki-compare-img';
				img.src = p.image || '';
				img.alt = p.title || '';
				img.setAttribute( 'role', 'button' );
				img.setAttribute( 'tabindex', '0' );
				img.setAttribute( 'aria-label', ( S.viewDetails || 'View details' ) + ( p.title ? ( ': ' + p.title ) : '' ) );
				img.addEventListener( 'click', function () {
					openProduct( p, img );
				} );
				img.addEventListener( 'keydown', function ( e ) {
					if ( 'Enter' === e.key || ' ' === e.key || 13 === e.keyCode || 32 === e.keyCode ) {
						e.preventDefault();
						openProduct( p, img );
					}
				} );

				var title = el( 'div', 'tuki-compare-title' );
				title.textContent = p.title || '';

				th.appendChild( img );
				th.appendChild( title );

				if ( p.add_to_cart ) {
					var add = el( 'button', 'tuki-add' );
					add.type = 'button';
					add.textContent = S.addToCart || '';
					add.addEventListener( 'click', function () {
						addToCart( p, add );
					} );
					th.appendChild( add );
				} else if ( p.needs_options ) {
					var vopts = el( 'a', 'tuki-add tuki-add--link' );
					vopts.href = p.url || '#';
					vopts.target = '_blank';
					vopts.rel = 'noopener';
					vopts.textContent = S.viewProduct || 'View product';
					th.appendChild( vopts );
				} else {
					var oos = el( 'span', 'tuki-oos' );
					oos.textContent = S.outOfStock || '';
					th.appendChild( oos );
				}

				head.appendChild( th );
			} );
			table.appendChild( head );

			( cmp.rows || [] ).forEach( function ( row ) {
				var tr = document.createElement( 'tr' );
				var label = document.createElement( 'td' );
				label.className = 'tuki-compare-label';
				label.textContent = row.label;
				tr.appendChild( label );
				( row.values || [] ).forEach( function ( v ) {
					var td = document.createElement( 'td' );
					td.className = 'tuki-compare-cell';
					td.textContent = v;
					tr.appendChild( td );
				} );
				table.appendChild( tr );
			} );

			scroll.appendChild( table );
			wrap.appendChild( scroll );
			msgsEl.appendChild( wrap );
			scrollDown();
		}

		// Secure order-status lookup form. Nothing about the order is known here
		// until the server verifies ownership and returns it.
		function addOrderForm( meta ) {
			var wrap = el( 'div', 'tuki-order-form' );

			var numLabel = el( 'label', 'tuki-order-label' );
			numLabel.textContent = S.orderNumber || 'Order number';
			var num = el( 'input', 'tuki-order-input' );
			num.type = 'text';
			num.inputMode = 'numeric';
			num.placeholder = S.orderNumPlaceholder || '';

			var mailLabel = el( 'label', 'tuki-order-label' );
			mailLabel.textContent = S.orderEmail || 'Billing email';
			var mail = el( 'input', 'tuki-order-input' );
			mail.type = 'email';
			mail.placeholder = S.orderEmailPlaceholder || '';

			if ( meta && meta.logged_in ) {
				var hint = el( 'div', 'tuki-order-hint' );
				hint.textContent = S.orderEmailOptional || '';
				mailLabel.appendChild( hint );
			}

			var submit = el( 'button', 'tuki-order-submit' );
			submit.type = 'button';
			submit.textContent = S.orderCheck || 'Check order';

			var err = el( 'div', 'tuki-order-error' );

			function run() {
				if ( submit.disabled ) {
					return;
				}
				var id = parseInt( ( num.value || '' ).replace( /[^0-9]/g, '' ), 10 );
				if ( ! id ) {
					err.textContent = S.orderNeedNumber || '';
					return;
				}
				err.textContent = '';
				submit.disabled = true;
				submit.textContent = S.orderChecking || '';
				api( 'order-status', { order_id: id, email: ( mail.value || '' ).trim() } )
					.then( function ( data ) {
						submit.disabled = false;
						submit.textContent = S.orderCheck || 'Check order';
						if ( data && data.order ) {
							addOrderCard( data.order );
						}
					} )
					.catch( function ( e ) {
						submit.disabled = false;
						submit.textContent = S.orderCheck || 'Check order';
						err.textContent = ( e && e.data && e.data.message ) ? e.data.message : ( S.error || '' );
					} );
			}

			submit.addEventListener( 'click', run );
			num.addEventListener( 'keydown', function ( e ) {
				if ( 13 === e.keyCode ) {
					e.preventDefault();
					mail.focus();
				}
			} );
			mail.addEventListener( 'keydown', function ( e ) {
				if ( 13 === e.keyCode ) {
					e.preventDefault();
					run();
				}
			} );

			wrap.appendChild( numLabel );
			wrap.appendChild( num );
			wrap.appendChild( mailLabel );
			wrap.appendChild( mail );
			wrap.appendChild( submit );
			wrap.appendChild( err );
			msgsEl.appendChild( wrap );
			scrollDown();
		}

		// Renders a verified order summary. Server has already confirmed ownership.
		function addOrderCard( order ) {
			var card = el( 'div', 'tuki-order-card' );

			var head = el( 'div', 'tuki-order-head' );
			var title = el( 'div', 'tuki-order-title' );
			title.textContent = ( S.orderTitle || 'Order #%s' ).replace( '%s', order.number );
			head.appendChild( title );
			if ( order.status ) {
				var badge = el( 'span', 'tuki-order-badge' );
				badge.textContent = order.status;
				head.appendChild( badge );
			}
			card.appendChild( head );

			var rows = [
				[ S.orderDateLabel || 'Ordered', order.date ],
				[ S.orderTotalLabel || 'Total', order.total ],
				[ S.orderItemsLabel || 'Items', ( 'number' === typeof order.item_count ) ? String( order.item_count ) : order.item_count ]
			];
			if ( order.tracking ) {
				rows.push( [ S.orderTrackingLabel || 'Tracking', order.tracking ] );
			}

			rows.forEach( function ( r ) {
				if ( ! r[1] && '0' !== r[1] ) {
					return;
				}
				var row = el( 'div', 'tuki-order-row' );
				var key = el( 'span', 'tuki-order-key' );
				key.textContent = r[0];
				var val = el( 'span', 'tuki-order-val' );
				val.textContent = r[1];
				row.appendChild( key );
				row.appendChild( val );
				card.appendChild( row );
			} );

			msgsEl.appendChild( card );
			scrollDown();
		}

		// Size & fit advisor: a short guided form. The recommendation itself is
		// computed server-side (deterministic, neutral wording).
		function addSizeForm( meta ) {
			var imperial = meta && 'imperial' === meta.unit;
			var wrap = el( 'div', 'tuki-size-form' );

			if ( meta && meta.title ) {
				var head = el( 'div', 'tuki-size-formhead' );
				head.textContent = ( S.sizeIntro || 'Find your size' ) + ' — ' + meta.title;
				wrap.appendChild( head );
			}

			function field( labelText, node ) {
				var f = el( 'label', 'tuki-size-field' );
				var l = el( 'span', 'tuki-size-flabel' );
				l.textContent = labelText;
				f.appendChild( l );
				f.appendChild( node );
				wrap.appendChild( f );
				return node;
			}

			var height = el( 'input', 'tuki-input' );
			height.type = 'number';
			height.min = '0';
			height.inputMode = 'decimal';
			height.placeholder = imperial ? '68' : '173';
			field( ( S.sizeHeight || 'Height' ) + ' (' + ( imperial ? ( S.sizeIn || 'in' ) : ( S.sizeCm || 'cm' ) ) + ')', height );

			var weight = el( 'input', 'tuki-input' );
			weight.type = 'number';
			weight.min = '0';
			weight.inputMode = 'decimal';
			weight.placeholder = imperial ? '160' : '72';
			field( ( S.sizeWeight || 'Weight' ) + ' (' + ( imperial ? ( S.sizeLb || 'lb' ) : ( S.sizeKg || 'kg' ) ) + ')', weight );

			var brand = el( 'input', 'tuki-input' );
			brand.type = 'text';
			brand.placeholder = S.sizeBrandPh || 'e.g. M, or 32';
			field( S.sizeBrand || 'Your usual size in another brand (optional)', brand );

			var fit = el( 'select', 'tuki-input' );
			[
				[ 'slim', S.sizeFitSlim || 'Slim / snug' ],
				[ 'regular', S.sizeFitRegular || 'Regular' ],
				[ 'loose', S.sizeFitLoose || 'Loose / relaxed' ]
			].forEach( function ( o ) {
				var opt = document.createElement( 'option' );
				opt.value = o[0];
				opt.textContent = o[1];
				if ( 'regular' === o[0] ) {
					opt.selected = true;
				}
				fit.appendChild( opt );
			} );
			field( S.sizeFit || 'Preferred fit', fit );

			var submit = el( 'button', 'tuki-order-submit' );
			submit.type = 'button';
			submit.textContent = S.sizeSubmit || 'Recommend my size';
			var err = el( 'div', 'tuki-order-error' );

			submit.addEventListener( 'click', function () {
				if ( submit.disabled ) {
					return;
				}
				err.textContent = '';
				submit.disabled = true;
				submit.textContent = S.sizeChecking || 'Checking…';
				api( 'size-advice', {
					product_id: meta.product_id,
					height: parseFloat( height.value ) || 0,
					weight: parseFloat( weight.value ) || 0,
					brand_size: ( brand.value || '' ).trim(),
					fit: fit.value
				} )
					.then( function ( data ) {
						submit.disabled = false;
						submit.textContent = S.sizeSubmit || 'Recommend my size';
						addSizeResult( data );
					} )
					.catch( function ( e ) {
						submit.disabled = false;
						submit.textContent = S.sizeSubmit || 'Recommend my size';
						err.textContent = ( e && e.data && e.data.message ) ? e.data.message : ( S.error || '' );
					} );
			} );

			wrap.appendChild( submit );
			wrap.appendChild( err );
			msgsEl.appendChild( wrap );
			scrollDown();
		}

		function addSizeResult( data ) {
			var card = el( 'div', 'tuki-size-card' );

			if ( data && data.recommended ) {
				var head = el( 'div', 'tuki-size-head' );
				var lbl = el( 'span', 'tuki-size-reclabel' );
				lbl.textContent = S.sizeRecommended || 'Recommended size';
				var val = el( 'span', 'tuki-size-recsize' );
				val.textContent = data.recommended;
				head.appendChild( lbl );
				head.appendChild( val );
				if ( data.confidence_label ) {
					var badge = el( 'span', 'tuki-size-conf tuki-size-conf--' + ( data.confidence || 'low' ) );
					badge.textContent = data.confidence_label;
					head.appendChild( badge );
				}
				card.appendChild( head );
			}

			if ( data && data.rationale ) {
				var why = el( 'div', 'tuki-size-why' );
				why.textContent = data.rationale;
				card.appendChild( why );
			}

			if ( data && data.caveat ) {
				var caveat = el( 'div', 'tuki-size-caveat' );
				caveat.textContent = data.caveat;
				card.appendChild( caveat );
			}

			msgsEl.appendChild( card );
			scrollDown();
		}

		// "Shop the look": render one product group per detected item.
		function addLookGroups( groups ) {
			groups.forEach( function ( g ) {
				var section = el( 'div', 'tuki-look-group' );

				var head = el( 'div', 'tuki-look-head' );
				var label = el( 'span', 'tuki-look-label' );
				label.textContent = ( g.group || S.lookItem || 'Item' ) + ':';
				head.appendChild( label );
				if ( g.title ) {
					var sub = el( 'span', 'tuki-look-sub' );
					sub.textContent = g.title;
					head.appendChild( sub );
				}
				section.appendChild( head );

				if ( g.products && g.products.length ) {
					if ( g.rescue ) {
						var note = el( 'div', 'tuki-look-note' );
						note.textContent = S.lookClosest || 'Closest matches';
						section.appendChild( note );
					}
					var cards = el( 'div', 'tuki-cards' );
					g.products.forEach( function ( p ) {
						cards.appendChild( buildCard( p ) );
						shownIds.push( p.id );
					} );
					section.appendChild( cards );
				} else {
					var none = el( 'div', 'tuki-look-none' );
					none.textContent = S.lookNone || 'No close match found for this item.';
					section.appendChild( none );
				}

				msgsEl.appendChild( section );
			} );
			scrollDown();
		}

		function addChatCards( products, browse ) {
			var wrap = el( 'div', 'tuki-cards' );
			products.forEach( function ( p ) {
				wrap.appendChild( buildCard( p ) );
				shownIds.push( p.id );
			} );
			msgsEl.appendChild( wrap );

			if ( browse && browse.has_more ) {
				var more = el( 'button', 'tuki-more' );
				more.type = 'button';
				more.textContent = S.showMore || 'Show more';
				var state = { mode: browse.mode, category: browse.category, page: browse.page || 1 };
				more.addEventListener( 'click', function () {
					if ( more.disabled ) {
						return;
					}
					more.disabled = true;
					more.textContent = S.loading || '…';
					api( 'browse', { mode: state.mode, category: state.category, page: state.page + 1 } )
						.then( function ( data ) {
							state.page = data.page;
							( data.products || [] ).forEach( function ( p ) {
								wrap.appendChild( buildCard( p ) );
							} );
							if ( data.has_more ) {
								more.disabled = false;
								more.textContent = S.showMore || 'Show more';
							} else {
								more.remove();
							}
							scrollDown();
						} )
						.catch( function () {
							more.disabled = false;
							more.textContent = S.showMore || 'Show more';
						} );
				} );
				msgsEl.appendChild( more );
			}
			scrollDown();
		}

		function sendMessage( forced ) {
			var text = ( 'string' === typeof forced ? forced : inputEl.value ).trim();
			if ( ! text || busy ) {
				return;
			}
			engaged = true;
			addBubble( 'user', text );
			history.push( { role: 'user', content: text } );
			if ( 'string' !== typeof forced ) {
				inputEl.value = '';
				autosize();
			}
			setBusy( true );
			var typing = addTyping();
			api( 'chat', { message: text, history: history.slice( -10 ), clarify_count: clarifyCount, context_ids: shownIds.slice( -8 ) } )
				.then( function ( data ) {
					typing.remove();
					var reply = data && data.reply ? data.reply : ( S.empty || '' );
					addBubble( 'bot', reply );
					history.push( { role: 'assistant', content: reply } );
					if ( data && 'clarify' === data.intent ) {
						clarifyCount++;
					}
					if ( data && data.sources && data.sources.length ) {
						addSources( data.sources );
					}
					if ( data && data.quick_replies && data.quick_replies.length ) {
						addQuickReplies( data.quick_replies );
					}
					if ( data && data.comparison && data.comparison.products && data.comparison.products.length ) {
						addComparison( data.comparison );
					} else if ( data && data.products && data.products.length ) {
						addChatCards( data.products, data.browse );
					}
					if ( data && data.order_form ) {
						addOrderForm( data.order_form );
					}
					if ( data && data.size_form ) {
						addSizeForm( data.size_form );
					}
				} )
				.catch( function ( err ) {
					typing.remove();
					var msg = ( err && err.data && err.data.message ) ? err.data.message : ( S.error || '' );
					addBubble( 'bot', msg );
				} )
				.then( function () {
					setBusy( false );
					inputEl.focus();
				} );
		}

		// Citations: compact clickable chips for the KB pages that grounded the
		// answer. Only the sources the server actually used are passed here.
		function addSources( sources ) {
			var wrap = el( 'div', 'tuki-sources' );

			var label = el( 'span', 'tuki-sources-label' );
			label.textContent = S.sourcesLabel || 'Sources';
			wrap.appendChild( label );

			sources.forEach( function ( src ) {
				if ( ! src || ! src.url ) {
					return;
				}
				var chip = document.createElement( 'a' );
				chip.className = 'tuki-source-chip';
				chip.href = src.url;
				chip.target = '_blank';
				chip.rel = 'noopener';
				chip.textContent = src.title || src.url;
				wrap.appendChild( chip );
			} );

			msgsEl.appendChild( wrap );
			scrollDown();
		}

		function addQuickReplies( chips ) {
			var wrap = el( 'div', 'tuki-chips' );
			chips.forEach( function ( c ) {
				var chip = el( 'button', 'tuki-chip' );
				chip.type = 'button';
				chip.textContent = c;
				chip.addEventListener( 'click', function () {
					wrap.remove();
					sendMessage( c );
				} );
				wrap.appendChild( chip );
			} );
			msgsEl.appendChild( wrap );
			scrollDown();
		}

		function onImagePick( input ) {
			var file = input.files && input.files[0];
			input.value = '';
			if ( ! file ) {
				return;
			}
			if ( [ 'image/png', 'image/jpeg', 'image/webp' ].indexOf( file.type ) === -1 ) {
				addBubble( 'bot', S.imageBadType || '' );
				return;
			}
			if ( file.size > ( cfg.maxImageBytes || 4194304 ) ) {
				addBubble( 'bot', S.imageTooLarge || '' );
				return;
			}
			var reader = new FileReader();
			reader.onload = function () {
				sendImage( reader.result );
			};
			reader.onerror = function () {
				addBubble( 'bot', S.error || '' );
			};
			reader.readAsDataURL( file );
		}

		function sendImage( dataUrl ) {
			if ( busy ) {
				return;
			}
			var bubble = el( 'div', 'tuki-msg user tuki-msg-img' );
			var thumb = document.createElement( 'img' );
			thumb.className = 'tuki-msg-thumb';
			thumb.src = dataUrl;
			thumb.alt = S.imageSent || '';
			bubble.appendChild( thumb );
			msgsEl.appendChild( bubble );
			scrollDown();
			setBusy( true );
			var typing = addTyping();
			api( 'visual-search', { image: dataUrl } )
				.then( function ( data ) {
					typing.remove();
					addBubble( 'bot', data && data.reply ? data.reply : ( S.empty || '' ) );
					if ( data && data.groups && data.groups.length ) {
						addLookGroups( data.groups );
					} else if ( data && data.products && data.products.length ) {
						addChatCards( data.products, data.browse );
					}
				} )
				.catch( function ( err ) {
					typing.remove();
					var msg = ( err && err.data && err.data.message ) ? err.data.message : ( S.error || '' );
					addBubble( 'bot', msg );
				} )
				.then( function () {
					setBusy( false );
				} );
		}
	}

	/* ---------------------------------------------------------------------
	 * Search widget
	 * ------------------------------------------------------------------- */

	function createSearch( host, opts ) {
		var root = makeShadow( host );
		applyTheme( host, opts );
		host.setAttribute( 'data-inline', '' );

		var wrap = el( 'div', 'tuki-inline-widget' );
		if ( opts.heading ) {
			var heading = el( 'div', 'tuki-widget-heading' );
			heading.textContent = opts.heading;
			wrap.appendChild( heading );
		}

		var box = el( 'div', 'tuki-search-box' );
		var input = el( 'input', 'tuki-search-input' );
		input.type = 'text';
		input.placeholder = opts.placeholder || '';
		var btn = el( 'button', 'tuki-search-btn' );
		btn.type = 'button';
		btn.setAttribute( 'aria-label', S.send || 'Search' );
		btn.innerHTML = ICON.search;
		box.appendChild( input );
		box.appendChild( btn );

		var results = el( 'div', 'tuki-results' );
		wrap.appendChild( box );
		wrap.appendChild( results );
		root.appendChild( wrap );

		function run() {
			var query = input.value.trim();
			if ( ! query ) {
				return;
			}
			results.textContent = '';
			note( results, S.searching || '' );
			btn.disabled = true;
			api( 'search', { query: query, n: opts.count || 6 } )
				.then( function ( data ) {
					results.textContent = '';
					var products = ( data && data.products ) || [];
					if ( ! products.length ) {
						note( results, S.noResults || '' );
						return;
					}
					renderGrid( results, products );
				} )
				.catch( function ( err ) {
					results.textContent = '';
					note( results, ( err && err.data && err.data.message ) || S.error || '' );
				} )
				.then( function () {
					btn.disabled = false;
				} );
		}

		btn.addEventListener( 'click', run );
		input.addEventListener( 'keydown', function ( e ) {
			if ( 13 === e.keyCode ) {
				e.preventDefault();
				run();
			}
		} );
	}

	/* ---------------------------------------------------------------------
	 * Recommendations widget
	 * ------------------------------------------------------------------- */

	function createRecs( host, opts ) {
		var root = makeShadow( host );
		applyTheme( host, opts );
		host.setAttribute( 'data-inline', '' );

		var wrap = el( 'div', 'tuki-inline-widget' );
		if ( opts.heading ) {
			var heading = el( 'div', 'tuki-widget-heading' );
			heading.textContent = opts.heading;
			wrap.appendChild( heading );
		}
		var results = el( 'div', 'tuki-results' );
		wrap.appendChild( results );
		root.appendChild( wrap );

		note( results, S.loading || '' );

		api( 'recommendations', {
			product_id: opts.product_id || 0,
			category_id: opts.category_id || 0,
			count: opts.count || 4
		} )
			.then( function ( data ) {
				results.textContent = '';
				var products = ( data && data.products ) || [];
				if ( ! products.length ) {
					host.style.display = 'none';
					return;
				}
				renderGrid( results, products );
			} )
			.catch( function () {
				host.style.display = 'none';
			} );
	}

	function note( target, text ) {
		var n = el( 'div', 'tuki-note' );
		n.textContent = text;
		target.appendChild( n );
	}

	/* ---------------------------------------------------------------------
	 * Boot + inline mount scanning
	 * ------------------------------------------------------------------- */

	function initMount( mount ) {
		if ( mount.getAttribute( 'data-tuki-init' ) ) {
			return;
		}
		mount.setAttribute( 'data-tuki-init', '1' );

		var opts;
		try {
			opts = JSON.parse( mount.getAttribute( 'data-tuki-config' ) || '{}' );
		} catch ( e ) {
			opts = {};
		}

		// Bubble colour defaults to the global setting unless a widget overrides it.
		if ( undefined === opts.bubbleBg ) {
			opts.bubbleBg = cfg.bubbleBg;
		}

		if ( 'chat' === opts.w ) {
			createChat( mount, opts, 'launcher' === opts.mode ? 'launcher' : 'inline' );
		} else if ( 'search' === opts.w ) {
			createSearch( mount, opts );
		} else if ( 'recs' === opts.w ) {
			createRecs( mount, opts );
		}
	}

	function scanMounts() {
		var nodes = document.querySelectorAll( '.tuki-mount:not([data-tuki-init])' );
		Array.prototype.forEach.call( nodes, initMount );
	}

	function boot() {
		if ( cfg.floating ) {
			var host = el( 'div' );
			host.id = 'tukify-widget-root';
			createChat( host, { accent: cfg.accent, scheme: cfg.scheme, bubbleBg: cfg.bubbleBg }, 'floating' );
			document.body.appendChild( host );
		}

		scanMounts();

		if ( window.MutationObserver ) {
			var observer = new MutationObserver( function () {
				scanMounts();
			} );
			observer.observe( document.body, { childList: true, subtree: true } );
		}
	}

	boot();
} )();
