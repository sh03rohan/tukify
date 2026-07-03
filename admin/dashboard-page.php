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
$tuki_total     = Tuki_Admin::total_products();
$tuki_pending   = max( 0, $tuki_total - $tuki_indexed );
$tuki_conn      = Tuki_Admin::connection_state();
$tuki_state     = Tuki_Indexer::get_state();
$tuki_running   = ( 'running' === $tuki_state['status'] );
$tuki_embed_pct = $tuki_total > 0 ? min( 100, (int) round( $tuki_indexed / $tuki_total * 100 ) ) : 0;

// Brand + warning glyphs (inline so no icon font is needed).
$tuki_spark_svg = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" aria-hidden="true"><path d="M12 3l1.8 4.9L18 9.6l-4.2 1.7L12 16l-1.8-4.7L6 9.6l4.2-1.7L12 3z" fill="currentColor"/><path d="M18.5 14l.9 2.3 2.3.9-2.3.9-.9 2.3-.9-2.3-2.3-.9 2.3-.9.9-2.3z" fill="currentColor" opacity="0.7"/></svg>';
$tuki_warn_svg  = '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" aria-hidden="true"><path d="M12 4l9 16H3L12 4z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><path d="M12 10v4M12 17h.01" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>';

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
		'opacity'        => true,
		'stroke'          => true,
		'stroke-width'    => true,
		'stroke-linejoin' => true,
		'stroke-linecap'  => true,
	),
);
?>
<div class="wrap tuki-wrap">
	<div class="tuki-app">
		<div class="tuki-panel">

			<header class="tuki-header">
				<div class="tuki-brand">
					<span class="tuki-brand-chip"><?php echo wp_kses( $tuki_spark_svg, $tuki_svg_allowed ); ?></span>
					<span class="tuki-brand-text">
						<span class="tuki-brand-title">Tukify</span>
						<span class="tuki-brand-sub"><?php esc_html_e( 'AI shopping assistant', 'tukify' ); ?></span>
					</span>
				</div>
				<span class="tuki-pill tuki-pill--<?php echo esc_attr( $tuki_conn['state'] ); ?>">
					<span class="tuki-dot"></span><?php echo esc_html( $tuki_conn['label'] ); ?>
				</span>
			</header>

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

					<div class="tuki-card">
						<div class="tuki-card-label"><?php esc_html_e( 'Chat-to-sale', 'tukify' ); ?></div>
						<div class="tuki-card-value"><?php echo esc_html( number_format_i18n( $tuki_data['chat_to_sale'], 1 ) ); ?>%</div>
						<?php if ( $tuki_data['attributed'] > 0 ) : ?>
							<div class="tuki-card-sub is-success">
								<?php
								/* translators: %s: number of attributed orders. */
								echo esc_html( sprintf( _n( '%s order', '%s orders', $tuki_data['attributed'], 'tukify' ), number_format_i18n( $tuki_data['attributed'] ) ) );
								?>
							</div>
						<?php else : ?>
							<div class="tuki-card-sub is-muted"><?php esc_html_e( 'No sales yet', 'tukify' ); ?></div>
						<?php endif; ?>
					</div>
				</div>

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
</div>
