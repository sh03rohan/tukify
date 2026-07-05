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
		chat: '<svg viewBox="0 0 24 24" fill="none"><path d="M4 5h16a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H9l-4 3v-3H4a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1z" fill="currentColor"/></svg>',
		spark: '<svg viewBox="0 0 24 24" fill="none"><path d="M12 3l1.8 4.9L18 9.6l-4.2 1.7L12 16l-1.8-4.7L6 9.6l4.2-1.7L12 3z" fill="currentColor"/></svg>',
		close: '<svg viewBox="0 0 24 24" fill="none"><path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>',
		send: '<svg viewBox="0 0 24 24" fill="none"><path d="M4 12l16-8-6 16-3-7-7-1z" fill="currentColor"/></svg>',
		image: '<svg viewBox="0 0 24 24" fill="none"><rect x="3" y="5" width="18" height="14" rx="2" stroke="currentColor" stroke-width="1.6"/><circle cx="8.5" cy="10" r="1.5" fill="currentColor"/><path d="M5 17l4.5-4.5L13 16l3-3 3 3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>',
		search: '<svg viewBox="0 0 24 24" fill="none"><circle cx="11" cy="11" r="7" stroke="currentColor" stroke-width="1.8"/><path d="M20 20l-3.5-3.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>'
	};

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

	function openProduct( p ) {
		logClick( p.id );
		if ( p.url ) {
			window.open( p.url, '_blank', 'noopener' );
		}
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
		api( 'add-to-cart', { product_id: p.id, quantity: qty } )
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

		var img = document.createElement( 'img' );
		img.className = 'tuki-pcard-img';
		img.src = p.image || '';
		img.alt = p.title || '';
		img.loading = 'lazy';
		img.addEventListener( 'click', function () {
			openProduct( p );
		} );

		var body = el( 'div', 'tuki-pcard-body' );

		var title = document.createElement( 'a' );
		title.className = 'tuki-pcard-title';
		title.href = p.url || '#';
		title.target = '_blank';
		title.rel = 'noopener';
		title.textContent = p.title || '';
		title.addEventListener( 'click', function () {
			logClick( p.id );
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
		} else {
			var oos = el( 'span', 'tuki-oos' );
			oos.textContent = S.outOfStock || '';
			actions.appendChild( oos );
		}

		body.appendChild( title );
		body.appendChild( meta );
		body.appendChild( actions );
		card.appendChild( img );
		card.appendChild( body );
		return card;
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
		var msgsEl;
		var inputEl;
		var sendBtn;

		var pop = 'inline' !== kind;

		var panel = el( 'div', 'tuki-panel ' + ( 'inline' === kind ? 'tuki-panel--flow' : ( 'launcher' === kind ? 'tuki-panel--overlay' : '' ) ) );

		// Header.
		var head = el( 'div', 'tuki-head' );
		var avatar = el( 'div', 'tuki-head-avatar' );
		avatar.innerHTML = ICON.spark;
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

		panel.appendChild( head );
		panel.appendChild( msgsEl );
		panel.appendChild( row );
		root.appendChild( panel );

		if ( pop ) {
			var launcher = el( 'button', 'tuki-launcher' );
			launcher.type = 'button';
			launcher.setAttribute( 'aria-label', S.open || 'Open' );
			launcher.innerHTML = ICON.chat;
			launcher.addEventListener( 'click', function () {
				var open = panel.classList.toggle( 'is-open' );
				if ( open ) {
					engaged = true;
					greet();
					setTimeout( function () {
						inputEl.focus();
					}, 50 );
				}
			} );
			root.appendChild( launcher );
		} else {
			greet();
		}

		if ( 'floating' === kind && cfg.exitIntent && cfg.exitIntent.enabled ) {
			setupExitIntent();
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

		// Re-offer once after the shopper adds something to the cart.
		document.addEventListener( 'tuki:added', function () {
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
				img.addEventListener( 'click', function () {
					openProduct( p );
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
					if ( data && data.source && data.source.url ) {
						addSource( data.source );
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

		function addSource( src ) {
			var wrap = el( 'div', 'tuki-source' );
			var link = document.createElement( 'a' );
			link.className = 'tuki-source-link';
			link.href = src.url;
			link.target = '_blank';
			link.rel = 'noopener';
			link.textContent = src.title || '';
			wrap.appendChild( link );
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
			createChat( host, { accent: cfg.accent, scheme: cfg.scheme }, 'floating' );
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
