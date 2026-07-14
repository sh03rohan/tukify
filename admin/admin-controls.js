/**
 * Tukify settings — custom accessible single-select dropdowns.
 *
 * Loaded ONLY on the Tukify Settings screen. Progressive enhancement: each
 * native <select> (single) is kept in the DOM (hidden, same id + name) as the
 * source of truth and the thing that actually submits, while a custom, dark-
 * themed listbox drives it. If this script never runs, the native selects still
 * work. Multi-selects (Knowledge Base) are plain checkbox lists and are left
 * untouched here.
 *
 * ARIA: trigger is a button with aria-haspopup="listbox" + aria-expanded; the
 * panel is role="listbox" with role="option" children (aria-selected). Keyboard:
 * open with Enter/Space/Arrow, navigate with Arrow/Home/End, choose with
 * Enter/Space, close with Escape/outside-click/Tab.
 */
( function () {
	'use strict';

	var CHEVRON =
		'<svg viewBox="0 0 24 24" width="16" height="16" fill="none" aria-hidden="true">' +
		'<path d="M6 9l6 6 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
	var CHECK =
		'<svg viewBox="0 0 24 24" width="15" height="15" fill="none" aria-hidden="true">' +
		'<path d="M5 12.5l4.2 4.2L19 7" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>';

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
		var selects = root.querySelectorAll( 'select:not([multiple])' );
		Array.prototype.forEach.call( selects, enhance );
	} );

	function enhance( select ) {
		if ( select.dataset.tkfyEnhanced ) {
			return;
		}
		select.dataset.tkfyEnhanced = '1';

		var baseId = select.id || 'tkfy-select-' + Math.random().toString( 36 ).slice( 2 );

		// Wrapper holds the (hidden) native select + custom UI.
		var wrap = document.createElement( 'div' );
		wrap.className = 'tkfy-select';
		select.parentNode.insertBefore( wrap, select );
		wrap.appendChild( select );
		select.classList.add( 'tkfy-select-native' );
		select.setAttribute( 'tabindex', '-1' );
		select.setAttribute( 'aria-hidden', 'true' );

		var trigger = document.createElement( 'button' );
		trigger.type = 'button';
		trigger.className = 'tkfy-select-trigger';
		trigger.id = baseId + '-trigger';
		trigger.setAttribute( 'aria-haspopup', 'listbox' );
		trigger.setAttribute( 'aria-expanded', 'false' );

		var labelSpan = document.createElement( 'span' );
		labelSpan.className = 'tkfy-select-label';
		var chevron = document.createElement( 'span' );
		chevron.className = 'tkfy-select-chevron';
		chevron.innerHTML = CHEVRON;
		trigger.appendChild( labelSpan );
		trigger.appendChild( chevron );

		var panel = document.createElement( 'ul' );
		panel.className = 'tkfy-select-panel';
		panel.setAttribute( 'role', 'listbox' );
		panel.id = baseId + '-listbox';
		panel.tabIndex = -1;

		// Associate the field's visible label (if any) with both controls.
		var fieldLabel = document.querySelector( 'label[for="' + select.id + '"]' );
		if ( fieldLabel ) {
			if ( ! fieldLabel.id ) {
				fieldLabel.id = baseId + '-label';
			}
			trigger.setAttribute( 'aria-labelledby', fieldLabel.id + ' ' + trigger.id );
			panel.setAttribute( 'aria-labelledby', fieldLabel.id );
		}

		var options = Array.prototype.slice.call( select.options );
		var optionEls = [];
		var active = -1;

		options.forEach( function ( opt, i ) {
			var li = document.createElement( 'li' );
			li.className = 'tkfy-select-option';
			li.setAttribute( 'role', 'option' );
			li.id = baseId + '-opt-' + i;
			li.setAttribute( 'data-index', String( i ) );
			li.tabIndex = -1;

			var check = document.createElement( 'span' );
			check.className = 'tkfy-select-check';
			check.innerHTML = CHECK;

			var text = document.createElement( 'span' );
			text.className = 'tkfy-select-option-text';
			text.textContent = opt.textContent;

			li.appendChild( check );
			li.appendChild( text );
			li.addEventListener( 'click', function () {
				choose( i );
			} );
			panel.appendChild( li );
			optionEls.push( li );
		} );

		wrap.appendChild( trigger );
		wrap.appendChild( panel );

		function selectedIndex() {
			return select.selectedIndex < 0 ? 0 : select.selectedIndex;
		}

		function syncFromNative() {
			var idx = selectedIndex();
			labelSpan.textContent = options[ idx ] ? options[ idx ].textContent : '';
			optionEls.forEach( function ( li, i ) {
				var sel = i === idx;
				li.setAttribute( 'aria-selected', sel ? 'true' : 'false' );
				li.classList.toggle( 'is-selected', sel );
			} );
		}

		var isOpen = false;

		// Decide open direction (down default, up when there isn't room below)
		// and cap the height to the space available so the panel is never cut off
		// by the viewport edge. The panel is anchored to the trigger via CSS, so
		// it follows the trigger on scroll; we only recompute flip + height here.
		function positionPanel() {
			wrap.classList.remove( 'is-up' );
			panel.style.maxHeight = '';

			var rect = trigger.getBoundingClientRect();
			var vh = window.innerHeight || document.documentElement.clientHeight;
			var margin = 8;
			var spaceBelow = vh - rect.bottom - margin;
			var spaceAbove = rect.top - margin;
			var content = Math.min( 280, panel.scrollHeight );

			var up = spaceBelow < content && spaceAbove > spaceBelow;
			wrap.classList.toggle( 'is-up', up );

			var avail = up ? spaceAbove : spaceBelow;
			panel.style.maxHeight = Math.min( content, Math.max( 80, avail ) ) + 'px';
		}

		function onReposition() {
			if ( isOpen ) {
				positionPanel();
			}
		}

		function open() {
			if ( isOpen ) {
				return;
			}
			isOpen = true;
			wrap.classList.add( 'is-open' );
			trigger.setAttribute( 'aria-expanded', 'true' );
			positionPanel();
			window.addEventListener( 'resize', onReposition );
			window.addEventListener( 'scroll', onReposition, true );
			setActive( selectedIndex(), true );
		}

		function close( focusTrigger ) {
			if ( ! isOpen ) {
				return;
			}
			isOpen = false;
			wrap.classList.remove( 'is-open' );
			trigger.setAttribute( 'aria-expanded', 'false' );
			window.removeEventListener( 'resize', onReposition );
			window.removeEventListener( 'scroll', onReposition, true );
			if ( focusTrigger ) {
				trigger.focus();
			}
		}

		function setActive( i, focusIt ) {
			if ( i < 0 || i >= optionEls.length ) {
				return;
			}
			active = i;
			optionEls.forEach( function ( li, j ) {
				li.classList.toggle( 'is-active', j === i );
			} );
			panel.setAttribute( 'aria-activedescendant', optionEls[ i ].id );
			if ( focusIt ) {
				optionEls[ i ].focus();
			}
		}

		function choose( i ) {
			if ( i < 0 || i >= options.length ) {
				return;
			}
			if ( select.selectedIndex !== i ) {
				select.selectedIndex = i;
				try {
					select.dispatchEvent( new Event( 'change', { bubbles: true } ) );
				} catch ( e ) {
					// Legacy fallback.
					var evt = document.createEvent( 'HTMLEvents' );
					evt.initEvent( 'change', true, false );
					select.dispatchEvent( evt );
				}
			}
			syncFromNative();
			close( true );
		}

		trigger.addEventListener( 'click', function () {
			if ( isOpen ) {
				close( true );
			} else {
				open();
			}
		} );

		trigger.addEventListener( 'keydown', function ( event ) {
			switch ( event.key ) {
				case 'ArrowDown':
				case 'ArrowUp':
				case 'Enter':
				case ' ':
				case 'Spacebar':
					event.preventDefault();
					open();
					break;
				default:
			}
		} );

		panel.addEventListener( 'keydown', function ( event ) {
			switch ( event.key ) {
				case 'ArrowDown':
					event.preventDefault();
					setActive( Math.min( optionEls.length - 1, active + 1 ), true );
					break;
				case 'ArrowUp':
					event.preventDefault();
					setActive( Math.max( 0, active - 1 ), true );
					break;
				case 'Home':
					event.preventDefault();
					setActive( 0, true );
					break;
				case 'End':
					event.preventDefault();
					setActive( optionEls.length - 1, true );
					break;
				case 'Enter':
				case ' ':
				case 'Spacebar':
					event.preventDefault();
					choose( active );
					break;
				case 'Escape':
					event.preventDefault();
					close( true );
					break;
				case 'Tab':
					close( false );
					break;
				default:
			}
		} );

		document.addEventListener( 'click', function ( event ) {
			if ( ! wrap.contains( event.target ) ) {
				close( false );
			}
		} );

		syncFromNative();
	}
} )();

/**
 * Appearance tab — WordPress colour pickers for the chat bubble + logo colours,
 * with a live preview bubble and a contrast hint. Requires jQuery +
 * wp-color-picker (both enqueued as dependencies on the Settings screen).
 */
( function ( $ ) {
	if ( ! $ || ! $.fn || ! $.fn.wpColorPicker ) {
		return;
	}

	$( function () {
		var pickers = $( '.tuki-wp-color' );

		if ( ! pickers.length ) {
			return;
		}

		var bubbleInput = document.getElementById( 'tuki_bubble_bg_color' );
		var bagInput = document.getElementById( 'tuki_logo_bag_color' );
		var preview = document.getElementById( 'tuki_bubble_preview' );
		var note = document.getElementById( 'tuki_bubble_contrast' );

		// Relative luminance (WCAG) of a #hex colour, or null if unparseable.
		function luminance( hex ) {
			var c = ( hex || '' ).replace( '#', '' );

			if ( 3 === c.length ) {
				c = c[0] + c[0] + c[1] + c[1] + c[2] + c[2];
			}

			if ( 6 !== c.length ) {
				return null;
			}

			var channels = [ 0, 2, 4 ].map( function ( i ) {
				var v = parseInt( c.substr( i, 2 ), 16 ) / 255;
				return v <= 0.03928 ? v / 12.92 : Math.pow( ( v + 0.055 ) / 1.055, 2.4 );
			} );

			return 0.2126 * channels[0] + 0.7152 * channels[1] + 0.0722 * channels[2];
		}

		function contrastRatio( a, b ) {
			var la = luminance( a );
			var lb = luminance( b );

			if ( null === la || null === lb ) {
				return null;
			}

			return ( Math.max( la, lb ) + 0.05 ) / ( Math.min( la, lb ) + 0.05 );
		}

		function render() {
			var bubble = ( bubbleInput && bubbleInput.value ) || '#7C6FF0';
			var bag = ( bagInput && bagInput.value ) || '#3B82F6';

			if ( preview ) {
				preview.style.background = bubble;
				preview.style.color = bag;
			}

			if ( note ) {
				var ratio = contrastRatio( bubble, bag );

				if ( null !== ratio && ratio < 1.6 ) {
					note.textContent = ( window.tukiAdmin && tukiAdmin.lowContrast ) || 'These colours are very similar — the logo may be hard to see.';
					note.className = 'tkfy-bubble-note is-warn';
				} else {
					note.textContent = '';
					note.className = 'tkfy-bubble-note';
				}
			}
		}

		// wpColorPicker writes the input value AFTER these callbacks fire, so the
		// preview update is deferred a tick to read the committed value.
		pickers.each( function () {
			$( this ).wpColorPicker( {
				change: function () {
					window.setTimeout( render, 0 );
				},
				clear: function () {
					window.setTimeout( render, 0 );
				}
			} );
		} );

		render();
	} );
} )( window.jQuery );
