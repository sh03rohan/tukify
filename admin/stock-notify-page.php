<?php
/**
 * Admin view: pending back-in-stock notifications, grouped per product.
 *
 * Uses native WP admin table styling (no scoped assets needed). Each subscriber
 * can be removed with a nonce-protected link; rows disappear automatically once
 * the "it's back" email is sent.
 *
 * @package Tukify
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! current_user_can( 'manage_options' ) ) {
	return;
}

$tuki_sn_notice = '';

// Handle a single-subscriber removal (nonce-scoped to the exact row id).
$tuki_sn_action = isset( $_GET['tuki_sn_action'] ) ? sanitize_key( wp_unslash( $_GET['tuki_sn_action'] ) ) : '';

if ( 'delete' === $tuki_sn_action ) {
	$tuki_sn_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

	check_admin_referer( 'tuki_sn_delete_' . $tuki_sn_id );

	if ( $tuki_sn_id > 0 ) {
		Tuki_DB::delete_stock_notify( $tuki_sn_id );
		$tuki_sn_notice = __( 'Subscriber removed.', 'tukify' );
	}
}

$tuki_sn_products = Tuki_DB::stock_notify_products();
$tuki_sn_total    = Tuki_DB::count_stock_notify_pending();
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Back in stock — pending notifications', 'tukify' ); ?></h1>

	<?php if ( '' !== $tuki_sn_notice ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $tuki_sn_notice ); ?></p></div>
	<?php endif; ?>

	<?php if ( ! Tuki_Stock_Notify::is_enabled() ) : ?>
		<div class="notice notice-warning">
			<p>
				<?php
				printf(
					/* translators: %s: link to the settings screen. */
					esc_html__( 'Back-in-stock alerts are currently turned off. Enable them under %s.', 'tukify' ),
					'<a href="' . esc_url( admin_url( 'admin.php?page=tukify-settings' ) ) . '">' . esc_html__( 'Settings → Products & Cart', 'tukify' ) . '</a>'
				);
				?>
			</p>
		</div>
	<?php endif; ?>

	<p class="description">
		<?php
		printf(
			/* translators: %d: number of pending subscribers. */
			esc_html( _n( '%d shopper is waiting to be notified.', '%d shoppers are waiting to be notified.', $tuki_sn_total, 'tukify' ) ),
			(int) $tuki_sn_total
		);
		?>
		<?php esc_html_e( 'Emails are removed automatically the moment a notification is sent, and shoppers can unsubscribe from any email.', 'tukify' ); ?>
	</p>

	<?php if ( empty( $tuki_sn_products ) ) : ?>
		<p><?php esc_html_e( 'No one is waiting on a restock right now.', 'tukify' ); ?></p>
	<?php else : ?>
		<?php foreach ( $tuki_sn_products as $tuki_sn_row ) : ?>
			<?php
			$tuki_pid     = (int) $tuki_sn_row['product_id'];
			$tuki_product = function_exists( 'wc_get_product' ) ? wc_get_product( $tuki_pid ) : null;
			$tuki_name    = $tuki_product ? $tuki_product->get_name() : sprintf( /* translators: %d: product id. */ __( 'Product #%d (deleted)', 'tukify' ), $tuki_pid );
			$tuki_edit    = get_edit_post_link( $tuki_pid );
			$tuki_pending = Tuki_DB::stock_notify_pending( $tuki_pid );
			?>
			<h2 style="margin-top:1.5em;">
				<?php if ( $tuki_edit ) : ?>
					<a href="<?php echo esc_url( $tuki_edit ); ?>"><?php echo esc_html( $tuki_name ); ?></a>
				<?php else : ?>
					<?php echo esc_html( $tuki_name ); ?>
				<?php endif; ?>
				<span class="count">(<?php echo esc_html( number_format_i18n( (int) $tuki_sn_row['pending'] ) ); ?>)</span>
			</h2>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Email', 'tukify' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Requested', 'tukify' ); ?></th>
						<th scope="col" style="width:8em;"><?php esc_html_e( 'Action', 'tukify' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $tuki_pending as $tuki_sub ) : ?>
						<?php
						$tuki_sub_id = (int) $tuki_sub['id'];
						$tuki_remove = wp_nonce_url(
							add_query_arg(
								array(
									'page'           => 'tukify-stock-notify',
									'tuki_sn_action' => 'delete',
									'id'             => $tuki_sub_id,
								),
								admin_url( 'admin.php' )
							),
							'tuki_sn_delete_' . $tuki_sub_id
						);
						?>
						<tr>
							<td><?php echo esc_html( $tuki_sub['email'] ); ?></td>
							<td><?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $tuki_sub['created_at'] ) ); ?></td>
							<td><a href="<?php echo esc_url( $tuki_remove ); ?>" class="submitdelete" onclick="return confirm('<?php echo esc_js( __( 'Remove this subscriber?', 'tukify' ) ); ?>');"><?php esc_html_e( 'Remove', 'tukify' ); ?></a></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endforeach; ?>
	<?php endif; ?>
</div>
