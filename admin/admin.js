/**
 * Tukify admin scripts.
 *
 * Test connection (settings), diagnostic search (settings), and the reindex
 * panel with polling progress (dashboard).
 */
( function ( $ ) {
	'use strict';

	function fmt( n ) {
		return ( Number( n ) || 0 ).toLocaleString();
	}

	$( function () {
		initTestConnection();
		initProviderToggles();
		initSearchTest();
		initReindex();
		initKnowledgeBase();
		initSizeCharts();
		initClearCache();
		initReviewNotice();
	} );

	/**
	 * Review request notice: records the shopper's choice so it never nags again.
	 * "Leave a review" and "Don't show again" dismiss for good; "Maybe later" and
	 * the native × snooze it. Fire-and-forget — the notice hides either way.
	 */
	function initReviewNotice() {
		var $notice = $( '.tuki-review-notice' );
		if ( ! $notice.length ) {
			return;
		}

		function record( choice ) {
			$.post( tukiAdmin.ajaxUrl, {
				action: 'tuki_review_action',
				nonce: $notice.data( 'nonce' ) || tukiAdmin.nonce,
				choice: choice
			} );
		}

		// Permanent dismiss: leaving a review (link still opens in a new tab) or
		// explicitly opting out.
		$notice.on( 'click', '.tuki-review-go, .tuki-review-done', function () {
			record( 'done' );
			$notice.slideUp( 150 );
		} );

		// Snooze: "Maybe later" or the core dismiss (×).
		$notice.on( 'click', '.tuki-review-later, .notice-dismiss', function () {
			record( 'later' );
			$notice.slideUp( 150 );
		} );
	}

	/**
	 * "Clear cache" button on the Analytics tab.
	 */
	function initClearCache() {
		var $btn = $( '#tuki-clear-cache' );

		if ( ! $btn.length ) {
			return;
		}

		var $status = $( '#tuki-cache-status' );

		$btn.on( 'click', function ( event ) {
			event.preventDefault();
			$btn.prop( 'disabled', true );
			$status.removeClass( 'is-success is-error' ).text( tukiAdmin.cacheClearing );

			$.post( tukiAdmin.ajaxUrl, {
				action: 'tuki_clear_cache',
				nonce: tukiAdmin.nonce
			} ).done( function ( response ) {
				if ( response && response.success ) {
					$status.addClass( 'is-success' ).text( tukiAdmin.cacheCleared );
				} else {
					$status.addClass( 'is-error' ).text( tukiAdmin.cacheClearError );
				}
			} ).fail( function () {
				$status.addClass( 'is-error' ).text( tukiAdmin.cacheClearError );
			} ).always( function () {
				$btn.prop( 'disabled', false );
			} );
		} );
	}

	/**
	 * Size-chart repeater: add/remove per-category size bands.
	 */
	function initSizeCharts() {
		var $wrap = $( '#tuki-size-charts' );
		var $add = $( '#tuki-size-add' );
		var $tpl = $( '#tuki-size-tpl' );

		if ( ! $add.length || ! $wrap.length || ! $tpl.length ) {
			return;
		}

		$add.on( 'click', function ( event ) {
			event.preventDefault();
			var i = parseInt( $wrap.data( 'next' ), 10 ) || 0;
			$wrap.data( 'next', i + 1 );
			$wrap.append( $tpl.text().replace( /__IDX__/g, i ) );
		} );

		$wrap.on( 'click', '.tuki-size-remove', function ( event ) {
			event.preventDefault();
			$( this ).closest( '.tuki-size-row' ).remove();
		} );
	}

	/**
	 * Knowledge-base Q&A repeater + reindex button.
	 */
	function initKnowledgeBase() {
		var $qa = $( '#tuki-kb-qa' );
		var $add = $( '#tuki-kb-add' );
		var $reindex = $( '#tuki-kb-reindex' );

		if ( $add.length && $qa.length ) {
			var option = $qa.data( 'option' );

			$add.on( 'click', function ( event ) {
				event.preventDefault();
				var i = parseInt( $qa.data( 'next' ), 10 ) || 0;
				$qa.data( 'next', i + 1 );
				var base = option + '[kb_qa][' + i + ']';
				var row = $( '<div class="tuki-kb-row"></div>' );
				$( '<input type="text" class="tuki-input" />' )
					.attr( 'name', base + '[q]' )
					.attr( 'placeholder', tukiAdmin.qPlaceholder )
					.appendTo( row );
				$( '<textarea class="tuki-input" rows="2"></textarea>' )
					.attr( 'name', base + '[a]' )
					.attr( 'placeholder', tukiAdmin.aPlaceholder )
					.appendTo( row );
				$( '<button type="button" class="tuki-btn tuki-btn--ghost tuki-kb-remove"></button>' )
					.text( tukiAdmin.remove )
					.appendTo( row );
				$qa.append( row );
			} );

			$qa.on( 'click', '.tuki-kb-remove', function ( event ) {
				event.preventDefault();
				$( this ).closest( '.tuki-kb-row' ).remove();
			} );
		}

		if ( $reindex.length ) {
			var $status = $( '#tuki-kb-status' );
			var $count = $( '#tuki-kb-count' );

			$reindex.on( 'click', function ( event ) {
				event.preventDefault();
				$status.removeClass( 'is-success is-error' ).text( tukiAdmin.kbReindexing );
				$reindex.prop( 'disabled', true );

				$.post( tukiAdmin.ajaxUrl, {
					action: 'tuki_kb_reindex',
					nonce: tukiAdmin.nonce
				} ).done( function ( response ) {
					if ( response && response.success ) {
						var data = response.data;
						$count.text( fmt( data.total ) );
						var detail = tukiAdmin.kbDetail
							.replace( '%1$s', fmt( data.embedded ) )
							.replace( '%2$s', fmt( data.skipped ) )
							.replace( '%3$s', fmt( data.removed ) );
						$status.addClass( 'is-success' ).text(
							tukiAdmin.kbDone.replace( '%s', fmt( data.total ) ) + ' — ' + detail
						);
					} else {
						var message =
							response && response.data && response.data.message
								? response.data.message
								: tukiAdmin.kbError;
						$status.addClass( 'is-error' ).text( message );
					}
				} ).fail( function () {
					$status.addClass( 'is-error' ).text( tukiAdmin.kbError );
				} ).always( function () {
					$reindex.prop( 'disabled', false );
				} );
			} );
		}
	}

	/**
	 * Live "Test connection" button on the settings screen.
	 */
	function initTestConnection() {
		// One "Test" button per provider key row.
		$( '.tuki-test-conn' ).on( 'click', function ( event ) {
			event.preventDefault();

			var $button   = $( this );
			var provider  = $button.data( 'provider' );
			var $keyInput = $( '#' + $button.data( 'key' ) );
			var $result   = $( '.tuki-test-result[data-provider="' + provider + '"]' );

			$result.removeClass( 'is-success is-error' ).text( tukiAdmin.testing );
			$button.prop( 'disabled', true );

			$.post( tukiAdmin.ajaxUrl, {
				action: 'tuki_test_connection',
				nonce: tukiAdmin.nonce,
				provider: provider,
				api_key: $keyInput.length ? $keyInput.val() : ''
			} )
				.done( function ( response ) {
					if ( response && response.success ) {
						$result.addClass( 'is-success' ).text( response.data.message );
					} else {
						var message =
							response && response.data && response.data.message
								? response.data.message
								: tukiAdmin.failed;
						$result.addClass( 'is-error' ).text( message );
					}
				} )
				.fail( function () {
					$result.addClass( 'is-error' ).text( tukiAdmin.failed );
				} )
				.always( function () {
					$button.prop( 'disabled', false );
				} );
		} );
	}

	/**
	 * Shows only the selected chat/embedding providers' model dropdowns, and
	 * highlights the "no embeddings" note when the chat provider lacks them.
	 */
	function initProviderToggles() {
		var $chat = $( '#tuki_chat_provider' );
		var $emb  = $( '#tuki_embedding_provider' );

		if ( ! $chat.length ) {
			return;
		}

		function syncChat() {
			var provider = $chat.val();
			$( '.tuki-chatmodel-field' ).each( function () {
				$( this ).prop( 'hidden', $( this ).data( 'provider' ) !== provider );
			} );
			// Emphasise the embedding note when the chat provider can't embed.
			var hasEmbeddings = $chat.find( 'option:selected' ).data( 'embeddings' ) === 1;
			$( '#tuki-embedding-note' ).css( 'opacity', hasEmbeddings ? '' : '1' ).toggleClass( 'is-warn', ! hasEmbeddings );
		}

		function syncEmb() {
			var provider = $emb.val();
			$( '.tuki-embmodel-field' ).each( function () {
				$( this ).prop( 'hidden', $( this ).data( 'provider' ) !== provider );
			} );
		}

		$chat.on( 'change', syncChat );
		$emb.on( 'change', syncEmb );
		syncChat();
		syncEmb();
	}

	/**
	 * Diagnostic semantic-search box on the settings screen.
	 */
	function initSearchTest() {
		var $input = $( '#tuki-search-query' );
		var $run = $( '#tuki-search-run' );
		var $status = $( '#tuki-search-status' );
		var $results = $( '#tuki-search-results' );

		if ( ! $run.length ) {
			return;
		}

		function runSearch() {
			var query = $.trim( $input.val() );

			$status.removeClass( 'is-success is-error' );
			$results.empty();

			if ( ! query ) {
				$status.addClass( 'is-error' ).text( tukiAdmin.searchEmpty );
				return;
			}

			$status.text( tukiAdmin.searching );
			$run.prop( 'disabled', true );

			$.post( tukiAdmin.ajaxUrl, {
				action: 'tuki_test_search',
				nonce: tukiAdmin.nonce,
				query: query
			} ).done( function ( response ) {
				if ( response && response.success ) {
					var items = response.data.results || [];

					if ( ! items.length ) {
						$status.addClass( 'is-error' ).text( tukiAdmin.searchNone );
						return;
					}

					$status.addClass( 'is-success' ).text( '' );

					$.each( items, function ( index, item ) {
						var $li = $( '<li></li>' );
						var $link = $( '<a></a>' )
							.attr( 'href', item.url )
							.attr( 'target', '_blank' )
							.text( item.title );
						var $score = $( '<span class="tuki-search-score"></span>' ).text(
							' (' + item.score + ')'
						);
						$li.append( $link ).append( $score );
						$results.append( $li );
					} );
				} else {
					var message =
						response && response.data && response.data.message
							? response.data.message
							: tukiAdmin.searchError;
					$status.addClass( 'is-error' ).text( message );
				}
			} ).fail( function () {
				$status.addClass( 'is-error' ).text( tukiAdmin.searchError );
			} ).always( function () {
				$run.prop( 'disabled', false );
			} );
		}

		$run.on( 'click', function ( event ) {
			event.preventDefault();
			runSearch();
		} );

		$input.on( 'keydown', function ( event ) {
			if ( event.which === 13 ) {
				event.preventDefault();
				runSearch();
			}
		} );
	}

	/**
	 * Reindex panel + polling progress on the dashboard screen.
	 */
	function initReindex() {
		var $start = $( '#tuki-reindex-all' );
		var $cancel = $( '#tuki-reindex-cancel' );
		var $bar = $( '#tuki-progress-bar' );
		var $text = $( '#tuki-progress-text' );
		var $count = $( '#tuki-indexed-count' );
		var $caption = $( '#tuki-reindex-caption' );
		var $boot = $( '#tuki-reindex-boot' );
		var polling = null;

		if ( ! $start.length ) {
			return;
		}

		function embeddedText( indexed, total ) {
			return tukiAdmin.productsEmbedded
				.replace( '%1$s', fmt( indexed ) )
				.replace( '%2$s', fmt( total ) );
		}

		function render( data ) {
			var total = Number( data.totalProducts ) || 0;
			var running = 'running' === data.status;
			var pct = total > 0
				? Math.min( 100, Math.round( ( data.indexed / total ) * 100 ) )
				: ( running ? ( data.percent || 0 ) : 0 );

			$count.text( fmt( data.indexed ) );
			$bar.css( 'width', pct + '%' );

			var text = embeddedText( data.indexed, total );
			if ( running && data.lastError ) {
				text += ' — ' + data.lastError;
			}
			$text.text( text );

			$cancel.prop( 'hidden', ! running );
			$start.prop( 'disabled', running );

			if ( ! running && data.lastReindex ) {
				$caption.text( data.lastReindex );
			}
		}

		function poll() {
			$.post( tukiAdmin.ajaxUrl, {
				action: 'tuki_reindex_status',
				nonce: tukiAdmin.nonce
			} ).done( function ( response ) {
				if ( response && response.success ) {
					render( response.data );
					if ( 'running' !== response.data.status ) {
						stopPolling();
					}
				}
			} );
		}

		function startPolling() {
			if ( polling ) {
				return;
			}
			polling = window.setInterval( poll, 2000 );
		}

		function stopPolling() {
			if ( polling ) {
				window.clearInterval( polling );
				polling = null;
			}
		}

		$start.on( 'click', function ( event ) {
			event.preventDefault();

			if ( ! window.confirm( tukiAdmin.reindexConfirm ) ) {
				return;
			}

			$start.prop( 'disabled', true );

			$.post( tukiAdmin.ajaxUrl, {
				action: 'tuki_start_reindex',
				nonce: tukiAdmin.nonce
			} ).done( function ( response ) {
				if ( response && response.success ) {
					render( response.data );
					if ( 'running' === response.data.status ) {
						startPolling();
					}
				} else {
					$start.prop( 'disabled', false );
					$text.text( tukiAdmin.reindexError );
				}
			} ).fail( function () {
				$start.prop( 'disabled', false );
				$text.text( tukiAdmin.reindexError );
			} );
		} );

		$cancel.on( 'click', function ( event ) {
			event.preventDefault();

			$.post( tukiAdmin.ajaxUrl, {
				action: 'tuki_cancel_reindex',
				nonce: tukiAdmin.nonce
			} ).done( function ( response ) {
				if ( response && response.success ) {
					render( response.data );
					stopPolling();
				}
			} );
		} );

		// Resume polling if a reindex was already running when the page loaded.
		if ( $boot.data( 'running' ) === 1 || $boot.data( 'running' ) === '1' ) {
			startPolling();
		}
	}
} )( jQuery );
