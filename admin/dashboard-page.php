<?php
/**
 * Tukify dashboard view (dark design system).
 *
 * Rendered by Tuki_Admin::render_dashboard_page(). Real data from the
 * {prefix}_tuki_events table and the embeddings index.
 *
 * @package Tukify
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$tuki_data      = Tuki_Analytics::dashboard_data();
$tuki_indexed   = Tuki_DB::count_embeddings();

// -------- Conversion KPI range (read-only GET filter, same pattern as the
// demand insights filter on the Settings screen) --------
$tuki_kpi_ranges = array(
	7   => __( '7 days', 'tukify' ),
	30  => __( '30 days', 'tukify' ),
	90  => __( '90 days', 'tukify' ),
	365 => __( '1 year', 'tukify' ),
	0   => __( 'All time', 'tukify' ),
);

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display filter, no state change.
$tuki_kpi_raw = isset( $_GET['kpi_range'] ) ? sanitize_text_field( wp_unslash( $_GET['kpi_range'] ) ) : '';

// Checked for numeric-ness before absint(): absint( 'bogus' ) is 0, and 0 is a
// real range here ("All time"), so a malformed URL would silently widen the
// window instead of falling back to the 30-day default.
$tuki_kpi_range = is_numeric( $tuki_kpi_raw ) ? absint( $tuki_kpi_raw ) : 30;
if ( ! array_key_exists( $tuki_kpi_range, $tuki_kpi_ranges ) ) {
	$tuki_kpi_range = 30;
}

$tuki_kpi      = Tuki_Analytics::conversion_kpis( $tuki_kpi_range );
$tuki_kpi_base = admin_url( 'admin.php?page=tukify' );

// Money for the cards: WooCommerce formatting when available, plain otherwise.
$tuki_money = static function ( $amount ) {
	if ( function_exists( 'wc_price' ) && class_exists( 'Tuki_Cart' ) ) {
		return Tuki_Cart::price_text( wc_price( (float) $amount ) );
	}

	return number_format_i18n( (float) $amount, 2 );
};

// A signed percentage for the trend sub-labels, e.g. "+18%".
$tuki_signed = static function ( $value ) {
	return ( $value >= 0 ? '+' : '' ) . number_format_i18n( (int) $value ) . '%';
};
$tuki_total     = Tuki_Admin::total_products();
$tuki_pending   = max( 0, $tuki_total - $tuki_indexed );
$tuki_state     = Tuki_Indexer::get_state();
$tuki_running   = ( 'running' === $tuki_state['status'] );
$tuki_embed_pct = $tuki_total > 0 ? min( 100, (int) round( $tuki_indexed / $tuki_total * 100 ) ) : 0;

// Warning glyph (inline so no icon font is needed). The brand mark is the
// Tukify logo PNG shipped in images/ (rendered in the header below).
$tuki_warn_svg = '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" aria-hidden="true"><path d="M12 4l9 16H3L12 4z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><path d="M12 10v4M12 17h.01" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>';

$tuki_svg_allowed = array(
	'svg'  => array(
		'viewbox'     => true,
		'width'       => true,
		'height'      => true,
		'fill'        => true,
		'aria-hidden' => true,
	),
	'path' => array(
		'd'               => true,
		'fill'            => true,
		'opacity'         => true,
		'stroke'          => true,
		'stroke-width'    => true,
		'stroke-linejoin' => true,
		'stroke-linecap'  => true,
	),
);
?>
<div class="tuki-admin" data-theme="<?php echo esc_attr( Tuki_Admin::admin_theme() ); ?>" data-tk-screen="dashboard">
	<?php
	$tuki_current = 'dashboard';
	require TUKI_PLUGIN_DIR . 'admin/partials/sidebar.php';
	?>

	<div class="tk-main">
		<div class="tk-scroll">
			<?php require TUKI_PLUGIN_DIR . 'admin/partials/header.php'; ?>

	<div class="tuki-app">
		<div class="tuki-panel">

			<div class="tuki-body">

				<!-- Metric cards -->
				<div class="tuki-cards">
					<div class="tuki-card">
						<div class="tuki-card-label"><?php esc_html_e( 'Products indexed', 'tukify' ); ?></div>
						<div class="tuki-card-value" id="tuki-indexed-count"><?php echo esc_html( number_format_i18n( $tuki_indexed ) ); ?></div>
						<?php if ( $tuki_total > 0 && 0 === $tuki_pending ) : ?>
							<div class="tuki-card-sub is-success"><?php esc_html_e( 'All synced', 'tukify' ); ?></div>
						<?php elseif ( $tuki_pending > 0 ) : ?>
							<div class="tuki-card-sub is-warning">
								<?php
								/* translators: %s: number of products awaiting indexing. */
								echo esc_html( sprintf( __( '%s pending', 'tukify' ), number_format_i18n( $tuki_pending ) ) );
								?>
							</div>
						<?php else : ?>
							<div class="tuki-card-sub is-muted"><?php esc_html_e( 'No products yet', 'tukify' ); ?></div>
						<?php endif; ?>
					</div>

					<div class="tuki-card">
						<div class="tuki-card-label"><?php esc_html_e( 'Queries this week', 'tukify' ); ?></div>
						<div class="tuki-card-value"><?php echo esc_html( number_format_i18n( $tuki_data['queries_week'] ) ); ?></div>
						<?php if ( $tuki_data['queries_week'] > 0 ) : ?>
							<div class="tuki-card-sub is-accent">
								<?php
								$tuki_trend = (int) $tuki_data['queries_trend'];
								/* translators: %s: signed percentage, e.g. "+18%". */
								echo esc_html( sprintf( __( '%s vs last', 'tukify' ), ( $tuki_trend >= 0 ? '+' : '' ) . $tuki_trend . '%' ) );
								?>
							</div>
						<?php else : ?>
							<div class="tuki-card-sub is-muted"><?php esc_html_e( 'No queries yet', 'tukify' ); ?></div>
						<?php endif; ?>
					</div>

				</div>

				<!-- Conversion KPIs -->
				<div class="tuki-block">
					<div class="tuki-block-top">
						<div>
							<div class="tuki-block-title"><?php esc_html_e( 'Conversion', 'tukify' ); ?></div>
							<div class="tuki-block-caption"><?php esc_html_e( 'How the assistant turns conversations into orders. Aggregated counts only — no shopper is identifiable.', 'tukify' ); ?></div>
						</div>
					</div>

					<div class="tuki-range" role="group" aria-label="<?php esc_attr_e( 'Date range', 'tukify' ); ?>">
						<?php foreach ( $tuki_kpi_ranges as $tuki_days => $tuki_range_label ) : ?>
							<a
								class="tuki-range-btn<?php echo ( $tuki_days === $tuki_kpi_range ) ? ' is-active' : ''; ?>"
								href="<?php echo esc_url( add_query_arg( 'kpi_range', $tuki_days, $tuki_kpi_base ) ); ?>"
								<?php echo ( $tuki_days === $tuki_kpi_range ) ? 'aria-current="true"' : ''; ?>
							><?php echo esc_html( $tuki_range_label ); ?></a>
						<?php endforeach; ?>
					</div>

					<div class="tuki-cards tuki-cards--kpi">

						<!-- Chat-to-sale (existing metric, now range-aware and session-consistent) -->
						<div class="tuki-card">
							<div class="tuki-card-label"><?php esc_html_e( 'Chat-to-sale', 'tukify' ); ?></div>
							<div class="tuki-card-value"><?php echo esc_html( number_format_i18n( $tuki_kpi['chat_to_sale'], 1 ) ); ?>%</div>
							<?php if ( $tuki_kpi['query_sessions'] > 0 ) : ?>
								<div class="tuki-card-sub is-success">
									<?php
									printf(
										/* translators: 1: number of converting chat sessions, 2: total chat sessions. */
										esc_html__( '%1$s of %2$s chats', 'tukify' ),
										esc_html( number_format_i18n( $tuki_kpi['chat_sale_sessions'] ) ),
										esc_html( number_format_i18n( $tuki_kpi['query_sessions'] ) )
									);
									?>
								</div>
							<?php else : ?>
								<div class="tuki-card-sub is-muted"><?php esc_html_e( 'No chats in range', 'tukify' ); ?></div>
							<?php endif; ?>
						</div>

						<!-- Cart recovery -->
						<div class="tuki-card">
							<div class="tuki-card-label"><?php esc_html_e( 'Cart recovery', 'tukify' ); ?></div>
							<div class="tuki-card-value"><?php echo esc_html( number_format_i18n( $tuki_kpi['cart_recovery'], 1 ) ); ?>%</div>
							<?php if ( $tuki_kpi['iv_sessions'] > 0 ) : ?>
								<div class="tuki-card-sub is-success">
									<?php
									printf(
										/* translators: 1: recovered sessions, 2: sessions the nudge fired in. */
										esc_html__( '%1$s of %2$s nudged', 'tukify' ),
										esc_html( number_format_i18n( $tuki_kpi['iv_recovered'] ) ),
										esc_html( number_format_i18n( $tuki_kpi['iv_sessions'] ) )
									);
									?>
								</div>
							<?php else : ?>
								<div class="tuki-card-sub is-muted"><?php esc_html_e( 'Nudge has not fired yet', 'tukify' ); ?></div>
							<?php endif; ?>
						</div>

						<!-- Resolution rate -->
						<div class="tuki-card">
							<div class="tuki-card-label"><?php esc_html_e( 'Resolution rate', 'tukify' ); ?></div>
							<div class="tuki-card-value"><?php echo esc_html( number_format_i18n( $tuki_kpi['resolution'], 1 ) ); ?>%</div>
							<?php if ( $tuki_kpi['query_sessions'] > 0 ) : ?>
								<?php if ( $tuki_kpi['unresolved_sessions'] > 0 ) : ?>
									<div class="tuki-card-sub is-warning">
										<?php
										printf(
											/* translators: %s: number of chat sessions with an unanswered turn. */
											esc_html( _n( '%s chat unanswered', '%s chats unanswered', $tuki_kpi['unresolved_sessions'], 'tukify' ) ),
											esc_html( number_format_i18n( $tuki_kpi['unresolved_sessions'] ) )
										);
										?>
									</div>
								<?php else : ?>
									<div class="tuki-card-sub is-success"><?php esc_html_e( 'Every chat answered', 'tukify' ); ?></div>
								<?php endif; ?>
							<?php else : ?>
								<div class="tuki-card-sub is-muted"><?php esc_html_e( 'No chats in range', 'tukify' ); ?></div>
							<?php endif; ?>
						</div>

						<!-- Chat-assisted AOV vs store-wide -->
						<div class="tuki-card">
							<div class="tuki-card-label"><?php esc_html_e( 'Chat-assisted AOV', 'tukify' ); ?></div>
							<div class="tuki-card-value"><?php echo esc_html( $tuki_money( $tuki_kpi['chat_aov'] ) ); ?></div>
							<?php if ( null !== $tuki_kpi['aov_delta'] ) : ?>
								<div class="tuki-card-sub <?php echo esc_attr( $tuki_kpi['aov_delta'] >= 0 ? 'is-success' : 'is-warning' ); ?>">
									<?php
									printf(
										/* translators: 1: signed percentage, 2: store-wide average order value. */
										esc_html__( '%1$s vs %2$s store-wide', 'tukify' ),
										esc_html( $tuki_signed( $tuki_kpi['aov_delta'] ) ),
										esc_html( $tuki_money( $tuki_kpi['store_aov'] ) )
									);
									?>
								</div>
							<?php elseif ( $tuki_kpi['chat_orders'] > 0 ) : ?>
								<div class="tuki-card-sub is-muted">
									<?php
									printf(
										/* translators: %s: number of chat-assisted orders. */
										esc_html( _n( '%s assisted order', '%s assisted orders', $tuki_kpi['chat_orders'], 'tukify' ) ),
										esc_html( number_format_i18n( $tuki_kpi['chat_orders'] ) )
									);
									?>
								</div>
							<?php else : ?>
								<div class="tuki-card-sub is-muted"><?php esc_html_e( 'No assisted orders yet', 'tukify' ); ?></div>
							<?php endif; ?>
						</div>

						<!-- Attributed revenue -->
						<div class="tuki-card">
							<div class="tuki-card-label"><?php esc_html_e( 'Attributed revenue', 'tukify' ); ?></div>
							<div class="tuki-card-value"><?php echo esc_html( $tuki_money( $tuki_kpi['chat_revenue'] ) ); ?></div>
							<?php if ( $tuki_kpi['chat_orders'] > 0 ) : ?>
								<div class="tuki-card-sub is-accent">
									<?php
									printf(
										/* translators: %s: number of chat-assisted orders. */
										esc_html( _n( 'from %s order', 'from %s orders', $tuki_kpi['chat_orders'], 'tukify' ) ),
										esc_html( number_format_i18n( $tuki_kpi['chat_orders'] ) )
									);
									?>
								</div>
							<?php else : ?>
								<div class="tuki-card-sub is-muted"><?php esc_html_e( 'No revenue in range', 'tukify' ); ?></div>
							<?php endif; ?>
						</div>

					</div><!-- .tuki-cards -->

					<?php
					// -------- Trend line: attributed orders per day across the range --------
					// "All time" has no fixed start, so the line is drawn over the last 30
					// days in that case; every other range plots its own window.
					$tuki_line_days = ( 0 === $tuki_kpi_range ) ? 30 : min( 90, $tuki_kpi_range );
					// phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
					$tuki_line_now = current_time( 'timestamp' );

					$tuki_points = array();
					$tuki_peak   = 0;

					for ( $tuki_i = $tuki_line_days - 1; $tuki_i >= 0; $tuki_i-- ) {
						$tuki_day    = gmdate( 'Y-m-d', $tuki_line_now - ( $tuki_i * DAY_IN_SECONDS ) );
						$tuki_orders = isset( $tuki_kpi['series'][ $tuki_day ]['orders'] ) ? (int) $tuki_kpi['series'][ $tuki_day ]['orders'] : 0;

						$tuki_points[] = array(
							'day'    => $tuki_day,
							'orders' => $tuki_orders,
						);

						$tuki_peak = max( $tuki_peak, $tuki_orders );
					}

					$tuki_has_line = $tuki_peak > 0 && count( $tuki_points ) > 1;
					?>

					<div class="tuki-trend">
						<div class="tuki-trend-head">
							<span class="tuki-trend-title"><?php esc_html_e( 'Attributed orders per day', 'tukify' ); ?></span>
							<?php if ( $tuki_has_line ) : ?>
								<span class="tuki-trend-peak">
									<?php
									printf(
										/* translators: %s: highest number of orders in a single day. */
										esc_html__( 'peak %s/day', 'tukify' ),
										esc_html( number_format_i18n( $tuki_peak ) )
									);
									?>
								</span>
							<?php endif; ?>
						</div>

						<?php if ( ! $tuki_has_line ) : ?>
							<div class="tuki-empty"><?php esc_html_e( 'No attributed orders in this range yet — the trend line appears once the assistant helps close a sale.', 'tukify' ); ?></div>
						<?php else : ?>
							<?php
							// Build the polyline in a 100x30 viewBox and let the SVG scale to
							// the card width, so no pixel measurements are needed.
							$tuki_last  = count( $tuki_points ) - 1;
							$tuki_coords = array();

							foreach ( $tuki_points as $tuki_idx => $tuki_point ) {
								$tuki_x        = ( 0 === $tuki_last ) ? 0 : round( ( $tuki_idx / $tuki_last ) * 100, 2 );
								$tuki_y        = round( 30 - ( ( $tuki_point['orders'] / $tuki_peak ) * 28 ), 2 );
								$tuki_coords[] = $tuki_x . ',' . $tuki_y;
							}

							$tuki_line_points = implode( ' ', $tuki_coords );
							// Close the shape along the baseline for the soft area fill.
							$tuki_area_points = '0,30 ' . $tuki_line_points . ' 100,30';

							$tuki_first_day = $tuki_points[0]['day'];
							$tuki_last_day  = $tuki_points[ $tuki_last ]['day'];

							$tuki_total_orders = 0;
							foreach ( $tuki_points as $tuki_point ) {
								$tuki_total_orders += $tuki_point['orders'];
							}

							$tuki_line_label = sprintf(
								/* translators: 1: number of orders, 2: number of days. */
								_n( '%1$s attributed order over the last %2$s days', '%1$s attributed orders over the last %2$s days', $tuki_total_orders, 'tukify' ),
								number_format_i18n( $tuki_total_orders ),
								number_format_i18n( $tuki_line_days )
							);
							?>
							<svg class="tuki-trend-svg" viewBox="0 0 100 30" preserveAspectRatio="none" role="img" aria-label="<?php echo esc_attr( $tuki_line_label ); ?>">
								<polygon class="tuki-trend-area" points="<?php echo esc_attr( $tuki_area_points ); ?>" />
								<polyline class="tuki-trend-line" points="<?php echo esc_attr( $tuki_line_points ); ?>" />
							</svg>
							<div class="tuki-trend-axis">
								<span><?php echo esc_html( date_i18n( 'M j', strtotime( $tuki_first_day ) ) ); ?></span>
								<span><?php echo esc_html( date_i18n( 'M j', strtotime( $tuki_last_day ) ) ); ?></span>
							</div>
						<?php endif; ?>
					</div>
				</div><!-- .tuki-block -->

				<!-- Reindex panel -->
				<div class="tuki-block">
					<div class="tuki-block-top">
						<div>
							<div class="tuki-block-title"><?php esc_html_e( 'Product index', 'tukify' ); ?></div>
							<div class="tuki-block-caption" id="tuki-reindex-caption"><?php echo esc_html( Tuki_Admin::last_reindex_human() ); ?></div>
						</div>
						<div class="tuki-block-actions">
							<button type="button" class="tuki-btn tuki-btn--accent" id="tuki-reindex-all"><?php esc_html_e( 'Reindex all', 'tukify' ); ?></button>
							<button type="button" class="tuki-btn tuki-btn--ghost" id="tuki-reindex-cancel" <?php echo $tuki_running ? '' : 'hidden'; ?>><?php esc_html_e( 'Cancel', 'tukify' ); ?></button>
						</div>
					</div>
					<div class="tuki-progress">
						<div class="tuki-progress-bar" id="tuki-progress-bar" style="width:<?php echo esc_attr( $tuki_embed_pct ); ?>%"></div>
					</div>
					<div class="tuki-progress-text" id="tuki-progress-text" role="status" aria-live="polite">
						<?php
						/* translators: 1: embedded product count, 2: total product count. */
						echo esc_html( sprintf( __( '%1$s / %2$s products embedded', 'tukify' ), number_format_i18n( $tuki_indexed ), number_format_i18n( $tuki_total ) ) );
						?>
					</div>
					<span id="tuki-reindex-boot" data-running="<?php echo esc_attr( $tuki_running ? '1' : '0' ); ?>" hidden></span>
				</div>

				<!-- Top searches -->
				<div class="tuki-block">
					<div class="tuki-block-title tuki-searches-title"><?php esc_html_e( 'Top searches', 'tukify' ); ?></div>
					<?php if ( empty( $tuki_data['top_searches'] ) ) : ?>
						<div class="tuki-empty"><?php esc_html_e( 'No searches yet — shopper queries will appear here.', 'tukify' ); ?></div>
					<?php else : ?>
						<div class="tuki-list">
							<?php foreach ( $tuki_data['top_searches'] as $tuki_row ) : ?>
								<div class="<?php echo esc_attr( $tuki_row['zero_result'] ? 'tuki-list-row is-zero' : 'tuki-list-row' ); ?>">
									<span class="tuki-list-label">
										<?php if ( $tuki_row['zero_result'] ) : ?>
											<span class="tuki-warn-icon"><?php echo wp_kses( $tuki_warn_svg, $tuki_svg_allowed ); ?></span>
										<?php endif; ?>
										<?php echo esc_html( $tuki_row['query'] ); ?>
									</span>
									<?php if ( $tuki_row['zero_result'] ) : ?>
										<span class="tuki-list-value is-zero"><?php esc_html_e( '0 results', 'tukify' ); ?></span>
									<?php else : ?>
										<span class="tuki-list-value"><?php echo esc_html( number_format_i18n( $tuki_row['count'] ) ); ?></span>
									<?php endif; ?>
								</div>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
				</div>

			</div><!-- .tuki-body -->
		</div><!-- .tuki-panel -->
	</div><!-- .tuki-app -->

		</div><!-- .tk-scroll -->
	</div><!-- .tk-main -->
</div>
