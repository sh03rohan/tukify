<?php
/**
 * Product indexing.
 *
 * Turns products into embeddings and stores their vectors. All embedding work
 * runs in the background via Action Scheduler (bundled with WooCommerce) — no
 * synchronous AI ever happens on a page load or product save.
 *
 * @package Tukify
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Coordinates single-product and full-catalog indexing.
 */
class Tuki_Indexer {

	/**
	 * Action Scheduler hook for indexing one product.
	 */
	const HOOK_SINGLE = 'tuki_index_product';

	/**
	 * Action Scheduler hook for processing one reindex batch.
	 */
	const HOOK_BATCH = 'tuki_reindex_batch';

	/**
	 * Action Scheduler group.
	 */
	const GROUP = 'tukify';

	/**
	 * Option holding the current reindex state/queue.
	 */
	const STATE_OPTION = 'tuki_reindex_state';

	/**
	 * Products processed per background batch.
	 */
	const BATCH_SIZE = 20;

	/**
	 * Registers product-change hooks and background action handlers.
	 */
	public function __construct() {
		// Keep the index in sync as products change.
		add_action( 'save_post_product', array( $this, 'on_product_save' ), 20, 3 );
		add_action( 'woocommerce_update_product', array( $this, 'on_product_update' ), 20 );
		add_action( 'woocommerce_product_set_stock', array( $this, 'on_stock_change' ), 20 );
		add_action( 'before_delete_post', array( $this, 'on_product_delete' ), 20 );
		add_action( 'wp_trash_post', array( $this, 'on_product_delete' ), 20 );

		// Background workers.
		add_action( self::HOOK_SINGLE, array( $this, 'index_product' ), 10, 1 );
		add_action( self::HOOK_BATCH, array( $this, 'run_reindex_batch' ), 10 );
	}

	/*
	 * Product-change listeners
	 */

	/**
	 * Handles a product save. Skips autosaves/revisions.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @param bool    $update  Whether this is an update.
	 * @return void
	 */
	public function on_product_save( $post_id, $post, $update ) {
		unset( $post, $update );

		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		self::schedule_single( $post_id );
	}

	/**
	 * Handles WooCommerce's product-update hook.
	 *
	 * @param int $product_id Product ID.
	 * @return void
	 */
	public function on_product_update( $product_id ) {
		self::schedule_single( $product_id );
	}

	/**
	 * Handles a stock change.
	 *
	 * @param WC_Product|int $product Product object or ID.
	 * @return void
	 */
	public function on_stock_change( $product ) {
		$product_id = is_object( $product ) ? $product->get_id() : absint( $product );
		self::schedule_single( $product_id );
	}

	/**
	 * Removes a product's embedding when it is deleted or trashed.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function on_product_delete( $post_id ) {
		if ( 'product' === get_post_type( $post_id ) ) {
			Tuki_DB::delete_embedding( $post_id );
		}
	}

	/*
	 * Background workers
	 */

	/**
	 * Indexes a single product (Action Scheduler callback).
	 *
	 * Skips the embed call when the content hash and model are unchanged.
	 * Non-published or missing products have their row removed.
	 *
	 * @param int $product_id Product ID.
	 * @return void
	 */
	public function index_product( $product_id ) {
		$product_id = absint( $product_id );

		if ( ! $product_id || ! function_exists( 'wc_get_product' ) ) {
			return;
		}

		$product = wc_get_product( $product_id );

		if ( ! $product || 'publish' !== get_post_status( $product_id ) ) {
			Tuki_DB::delete_embedding( $product_id );
			return;
		}

		$text  = self::build_product_text( $product );
		$hash  = hash( 'sha256', $text );
		$model = (string) Tuki_Settings::get( 'embedding_model' );

		$existing = Tuki_DB::get_embedding_row( $product_id );

		if ( $existing && $existing['content_hash'] === $hash && $existing['model'] === $model ) {
			return; // Unchanged — skip the paid re-embed.
		}

		$embeddings = new Tuki_Embeddings();

		try {
			$vectors = $embeddings->embed_batch( array( $text ) );
		} catch ( Exception $e ) {
			// No/invalid key or a transient provider error: leave any existing
			// embedding in place and let the next save or a manual reindex retry.
			// Swallowing this keeps a missing key from turning every product save
			// into a failed (and logged) Action Scheduler job.
			return;
		}

		$vector = isset( $vectors[0] ) ? $vectors[0] : array();

		if ( empty( $vector ) ) {
			return;
		}

		Tuki_DB::upsert_embedding( $product_id, $hash, wp_json_encode( $vector ), $model, count( $vector ) );
	}

	/**
	 * Processes one reindex batch, then schedules the next (Action Scheduler callback).
	 *
	 * On a rate-limit/transient failure the batch is requeued and retried with
	 * exponential backoff, so a large reindex can never hard-fail midway.
	 *
	 * @return void
	 */
	public function run_reindex_batch() {
		$state = self::get_state();

		if ( 'running' !== $state['status'] ) {
			return;
		}

		$queue = is_array( $state['queue'] ) ? $state['queue'] : array();

		if ( empty( $queue ) ) {
			self::finish_state( $state );
			return;
		}

		$ids     = array_splice( $queue, 0, self::BATCH_SIZE );
		$model   = (string) Tuki_Settings::get( 'embedding_model' );
		$pending = array();

		foreach ( $ids as $product_id ) {
			$product_id = absint( $product_id );
			$product    = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;

			if ( ! $product || 'publish' !== get_post_status( $product_id ) ) {
				Tuki_DB::delete_embedding( $product_id );
				continue;
			}

			$text     = self::build_product_text( $product );
			$hash     = hash( 'sha256', $text );
			$existing = Tuki_DB::get_embedding_row( $product_id );

			if ( $existing && $existing['content_hash'] === $hash && $existing['model'] === $model ) {
				continue; // Unchanged.
			}

			$pending[ $product_id ] = array(
				'text' => $text,
				'hash' => $hash,
			);
		}

		if ( ! empty( $pending ) ) {
			$product_ids = array_keys( $pending );
			$texts       = array();

			foreach ( $product_ids as $product_id ) {
				$texts[] = $pending[ $product_id ]['text'];
			}

			try {
				$embeddings = new Tuki_Embeddings();
				$vectors    = $embeddings->embed_batch( $texts );
			} catch ( Exception $e ) {
				// Requeue this batch at the front and retry with backoff.
				$attempts            = (int) $state['attempts'] + 1;
				$state['queue']      = array_merge( $ids, $queue );
				$state['attempts']   = $attempts;
				$state['last_error'] = $e->getMessage();
				update_option( self::STATE_OPTION, $state, false );

				$delay = min( 300, 15 * pow( 2, min( $attempts, 5 ) ) );
				self::schedule_batch( (int) $delay );
				return;
			}

			foreach ( $product_ids as $offset => $product_id ) {
				$vector = isset( $vectors[ $offset ] ) ? $vectors[ $offset ] : array();

				if ( empty( $vector ) ) {
					continue;
				}

				Tuki_DB::upsert_embedding(
					$product_id,
					$pending[ $product_id ]['hash'],
					wp_json_encode( $vector ),
					$model,
					count( $vector )
				);
			}
		}

		$state['queue']      = $queue;
		$state['processed']  = (int) $state['processed'] + count( $ids );
		$state['attempts']   = 0;
		$state['last_error'] = '';

		if ( empty( $queue ) ) {
			self::finish_state( $state );
		} else {
			update_option( self::STATE_OPTION, $state, false );
			self::schedule_batch( 0 );
		}
	}

	/*
	 * Reindex control (called from the admin UI)
	 */

	/**
	 * Starts a full-catalog reindex.
	 *
	 * @return array The new state.
	 */
	public static function start_reindex() {
		$ids = get_posts(
			array(
				'post_type'        => 'product',
				'post_status'      => 'publish',
				'fields'           => 'ids',
				'numberposts'      => -1,
				'no_found_rows'    => true,
				// get_posts() defaults this to true; wordpress.org prohibits that, so
				// set it explicitly to false. Still returns all published products.
				'suppress_filters' => false,
			)
		);

		$ids = array_map( 'absint', (array) $ids );

		$state = array(
			'total'      => count( $ids ),
			'processed'  => 0,
			'queue'      => $ids,
			'status'     => empty( $ids ) ? 'complete' : 'running',
			'started'    => time(),
			'finished'   => empty( $ids ) ? time() : 0,
			'attempts'   => 0,
			'last_error' => '',
		);

		update_option( self::STATE_OPTION, $state, false );

		if ( ! empty( $ids ) ) {
			self::schedule_batch( 0 );
		}

		return $state;
	}

	/**
	 * Cancels an in-progress reindex.
	 *
	 * @return array The updated state.
	 */
	public static function cancel_reindex() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK_BATCH, array(), self::GROUP );
		}

		$state           = self::get_state();
		$state['status'] = 'cancelled';
		$state['queue']  = array();
		update_option( self::STATE_OPTION, $state, false );

		return $state;
	}

	/**
	 * Returns the current reindex state, filled with defaults.
	 *
	 * @return array
	 */
	public static function get_state() {
		$state = get_option( self::STATE_OPTION );

		if ( ! is_array( $state ) ) {
			$state = array();
		}

		return wp_parse_args(
			$state,
			array(
				'total'      => 0,
				'processed'  => 0,
				'queue'      => array(),
				'status'     => 'idle',
				'started'    => 0,
				'finished'   => 0,
				'attempts'   => 0,
				'last_error' => '',
			)
		);
	}

	/*
	 * Helpers
	 */

	/**
	 * Marks a reindex state complete and persists it.
	 *
	 * @param array $state Current state.
	 * @return void
	 */
	private static function finish_state( $state ) {
		$state['status']   = 'complete';
		$state['queue']    = array();
		$state['finished'] = time();
		update_option( self::STATE_OPTION, $state, false );
	}

	/**
	 * Schedules a single-product index job (deduplicated).
	 *
	 * @param int $product_id Product ID.
	 * @return void
	 */
	private static function schedule_single( $product_id ) {
		$product_id = absint( $product_id );

		if ( ! $product_id || ! function_exists( 'as_enqueue_async_action' ) ) {
			return;
		}

		if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( self::HOOK_SINGLE, array( $product_id ), self::GROUP ) ) {
			return;
		}

		as_enqueue_async_action( self::HOOK_SINGLE, array( $product_id ), self::GROUP );
	}

	/**
	 * Schedules the next reindex batch, optionally after a delay.
	 *
	 * @param int $delay Delay in seconds (0 = as soon as possible).
	 * @return void
	 */
	private static function schedule_batch( $delay = 0 ) {
		if ( $delay > 0 && function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + $delay, self::HOOK_BATCH, array(), self::GROUP );
		} elseif ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::HOOK_BATCH, array(), self::GROUP );
		}
	}

	/**
	 * Builds the text that represents a product for embedding.
	 *
	 * Title + short description + categories + key attributes.
	 *
	 * @param WC_Product $product Product object.
	 * @return string
	 */
	public static function build_product_text( $product ) {
		$parts = array();

		$parts[] = $product->get_name();

		$description = $product->get_short_description();

		if ( '' === trim( (string) $description ) ) {
			$description = $product->get_description();
		}

		$description = trim( wp_strip_all_tags( (string) $description ) );

		if ( '' !== $description ) {
			$parts[] = $description;
		}

		$categories = wp_get_post_terms( $product->get_id(), 'product_cat', array( 'fields' => 'names' ) );

		if ( ! is_wp_error( $categories ) && ! empty( $categories ) ) {
			$parts[] = __( 'Categories', 'tukify' ) . ': ' . implode( ', ', $categories );
		}

		$attributes = $product->get_attributes();

		if ( ! empty( $attributes ) ) {
			$attribute_strings = array();

			foreach ( $attributes as $attribute ) {
				if ( ! is_object( $attribute ) || ! method_exists( $attribute, 'get_name' ) ) {
					continue;
				}

				$label = function_exists( 'wc_attribute_label' ) ? wc_attribute_label( $attribute->get_name() ) : $attribute->get_name();

				if ( $attribute->is_taxonomy() ) {
					$terms  = wp_get_post_terms( $product->get_id(), $attribute->get_name(), array( 'fields' => 'names' ) );
					$values = is_wp_error( $terms ) ? array() : $terms;
				} else {
					$values = $attribute->get_options();
				}

				if ( ! empty( $values ) ) {
					$attribute_strings[] = $label . ': ' . implode( ', ', $values );
				}
			}

			if ( ! empty( $attribute_strings ) ) {
				$parts[] = implode( ' | ', $attribute_strings );
			}
		}

		$text = implode( '. ', array_filter( array_map( 'trim', $parts ) ) );
		$text = trim( preg_replace( '/\s+/', ' ', $text ) );

		if ( function_exists( 'mb_substr' ) ) {
			$text = mb_substr( $text, 0, 2000 );
		} else {
			$text = substr( $text, 0, 2000 );
		}

		return $text;
	}
}
