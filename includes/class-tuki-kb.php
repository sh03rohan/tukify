<?php
/**
 * Knowledge base: policy / FAQ content indexing + retrieval.
 *
 * Pulls content from selected WordPress pages, WooCommerce policy pages, and
 * manual Q&A entries; embeds it into the {prefix}_tuki_kb table; and retrieves
 * the most relevant snippets for policy/support questions (grounded — the model
 * only answers from real store content).
 *
 * @package Tukify
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Indexes and searches the store's policy/FAQ knowledge base.
 */
class Tuki_KB {

	/**
	 * Action Scheduler hook for a full background re-index.
	 */
	const HOOK_REINDEX = 'tuki_kb_reindex';

	/**
	 * Action Scheduler hook for re-indexing a single post (on save).
	 */
	const HOOK_REINDEX_POST = 'tuki_kb_reindex_post';

	/**
	 * Words per content chunk (~400-500) and the overlap carried between
	 * consecutive chunks so a sentence straddling a boundary stays retrievable.
	 */
	const CHUNK_WORDS   = 450;
	const CHUNK_OVERLAP = 60;

	/**
	 * Safety cap on chunks per source, to keep embedding cost bounded on very
	 * long posts.
	 */
	const MAX_CHUNKS = 40;

	/**
	 * How many texts to send per embedding API call during a rebuild, so a large
	 * catalog is never sent as one enormous request.
	 */
	const EMBED_BATCH = 96;

	/**
	 * How many posts to load per page when gathering, to bound memory on big sites.
	 */
	const FETCH_BATCH = 100;

	/**
	 * Registers the re-embed hooks.
	 */
	public function __construct() {
		add_action( self::HOOK_REINDEX, array( $this, 'reindex' ) );
		add_action( self::HOOK_REINDEX_POST, array( $this, 'reindex_post' ) );
		add_action( 'save_post', array( $this, 'on_save_post' ), 20 );
		add_action( 'deleted_post', array( $this, 'on_delete_post' ), 20 );
	}

	/**
	 * Whether the knowledge base is enabled and has content indexed.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return (bool) Tuki_Settings::get( 'kb_enabled' ) && Tuki_DB::count_kb() > 0;
	}

	/*
	 * Indexing
	 */

	/**
	 * Rebuilds the whole knowledge base incrementally.
	 *
	 * Only chunks whose text changed (compared by content hash) are re-embedded;
	 * unchanged chunks keep their existing vector, and chunks that no longer
	 * exist (deleted posts, shortened content, disabled post types) are pruned.
	 * This keeps repeat reindexes cheap and bounded in API calls.
	 *
	 * @return array{total:int,embedded:int,skipped:int,removed:int}
	 */
	public function reindex() {
		if ( ! Tuki_Settings::get( 'kb_enabled' ) ) {
			Tuki_DB::clear_kb();

			return array(
				'total'    => 0,
				'embedded' => 0,
				'skipped'  => 0,
				'removed'  => 0,
			);
		}

		$entries  = $this->gather_entries();
		$existing = Tuki_DB::kb_hash_map();

		$desired  = array();
		$to_embed = array();
		$skipped  = 0;

		foreach ( $entries as $entry ) {
			$entry['content_hash'] = hash( 'sha256', $entry['content'] );
			$key                   = $entry['source_type'] . ':' . (int) $entry['source_id'] . ':' . (int) $entry['chunk'];
			$desired[ $key ]       = true;

			if ( isset( $existing[ $key ] ) && $existing[ $key ] === $entry['content_hash'] ) {
				++$skipped; // Unchanged — keep the stored row and its embedding.
				continue;
			}

			$to_embed[] = $entry;
		}

		$embedded = $this->embed_and_store( $to_embed );

		// Prune anything still stored but no longer wanted.
		$removed = 0;
		foreach ( $existing as $key => $hash ) {
			if ( isset( $desired[ $key ] ) ) {
				continue;
			}

			$parts = explode( ':', $key );
			if ( 3 !== count( $parts ) ) {
				continue;
			}

			Tuki_DB::delete_kb_chunk( $parts[0], (int) $parts[1], (int) $parts[2] );
			++$removed;
		}

		return array(
			'total'    => Tuki_DB::count_kb(),
			'embedded' => $embedded,
			'skipped'  => $skipped,
			'removed'  => $removed,
		);
	}

	/**
	 * Re-indexes a single post incrementally (used on save_post).
	 *
	 * Removes the post's rows when it is gone, unpublished, or no longer an
	 * indexed source; otherwise re-embeds only its changed chunks and prunes
	 * chunks that disappeared.
	 *
	 * @param int $post_id Post id.
	 * @return void
	 */
	public function reindex_post( $post_id ) {
		$post_id = absint( $post_id );

		if ( ! Tuki_Settings::get( 'kb_enabled' ) ) {
			return;
		}

		$post = get_post( $post_id );

		if ( ! $post || 'publish' !== $post->post_status || ! $this->is_kb_source( $post_id ) ) {
			Tuki_DB::delete_kb_post( $post_id );
			return;
		}

		$source_type = $this->source_type_for( $post );

		// Clear any rows this post left under a different source type (e.g. the
		// WooCommerce-policy toggle changed), so it is never indexed twice.
		Tuki_DB::delete_kb_other_types( $post_id, $source_type );

		$entries  = $this->post_entries( $post, $source_type );
		$existing = Tuki_DB::kb_source_hashes( $source_type, $post_id );

		$to_embed = array();
		$keep     = array();

		foreach ( $entries as $entry ) {
			$entry['content_hash']         = hash( 'sha256', $entry['content'] );
			$keep[ (int) $entry['chunk'] ] = true;

			$stored = isset( $existing[ (int) $entry['chunk'] ] ) ? $existing[ (int) $entry['chunk'] ] : '';

			if ( $stored === $entry['content_hash'] ) {
				continue;
			}

			$to_embed[] = $entry;
		}

		try {
			$this->embed_and_store( $to_embed );
		} catch ( Exception $e ) {
			// Never let an embedding hiccup block the editor save; the next full
			// reindex (or the next save) will pick it up.
			return;
		}

		// Drop chunks that no longer exist because the post got shorter.
		foreach ( $existing as $chunk => $hash ) {
			if ( ! isset( $keep[ (int) $chunk ] ) ) {
				Tuki_DB::delete_kb_chunk( $source_type, $post_id, (int) $chunk );
			}
		}
	}

	/**
	 * Embeds a list of entries in batches and upserts them.
	 *
	 * Each entry must already carry its content_hash. Embedding is done in
	 * EMBED_BATCH-sized API calls so a large rebuild is never one huge request.
	 *
	 * @param array $entries Entries to embed (may be empty).
	 * @return int Number of chunks actually stored.
	 * @throws Exception If the provider call fails (callers decide how to react).
	 */
	private function embed_and_store( array $entries ) {
		if ( empty( $entries ) ) {
			return 0;
		}

		$model      = Tuki_Settings::embedding_model();
		$embeddings = new Tuki_Embeddings();
		$stored     = 0;

		foreach ( array_chunk( $entries, self::EMBED_BATCH ) as $batch ) {
			$texts = array();
			foreach ( $batch as $entry ) {
				$texts[] = $entry['content'];
			}

			$vectors = $embeddings->embed_batch( $texts );

			foreach ( array_values( $batch ) as $i => $entry ) {
				$vector = isset( $vectors[ $i ] ) ? $vectors[ $i ] : array();

				if ( empty( $vector ) ) {
					continue;
				}

				Tuki_DB::upsert_kb(
					array_merge(
						$entry,
						array(
							'embedding' => wp_json_encode( $vector ),
							'model'     => $model,
						)
					)
				);
				++$stored;
			}
		}

		return $stored;
	}

	/**
	 * Gathers all knowledge-base entries from every configured source:
	 * manual Q&A, curated/WooCommerce-policy pages, and every published item of
	 * each enabled post type (posts, pages, products, …). Each underlying post is
	 * indexed once — bulk indexing skips ids already covered by a curated source.
	 *
	 * @return array List of entry arrays.
	 */
	private function gather_entries() {
		$entries     = array();
		$covered_ids = array(); // Post ids already added, so bulk indexing skips them.

		// 1) Manual Q&A.
		$qa = Tuki_Settings::get( 'kb_qa' );
		if ( is_array( $qa ) ) {
			$i = 0;
			foreach ( $qa as $pair ) {
				$question = isset( $pair['q'] ) ? trim( (string) $pair['q'] ) : '';
				$answer   = isset( $pair['a'] ) ? trim( (string) $pair['a'] ) : '';

				if ( '' === $question || '' === $answer ) {
					continue;
				}

				$entries[] = array(
					'source_type' => 'qa',
					'source_id'   => $i,
					'chunk'       => 0,
					'title'       => $question,
					'content'     => $question . "\n" . $answer,
					'url'         => '',
				);
				++$i;
			}
		}

		// 2) Curated pages + WooCommerce policy pages (explicit selections).
		$policy_ids = $this->wc_policy_page_ids();
		$page_ids   = array_map( 'absint', (array) Tuki_Settings::get( 'kb_pages' ) );

		if ( Tuki_Settings::get( 'kb_wc_policies' ) ) {
			$page_ids = array_merge( $page_ids, $policy_ids );
		}

		$page_ids = array_unique( array_filter( $page_ids ) );

		foreach ( $page_ids as $page_id ) {
			$post = get_post( $page_id );

			if ( ! $post || 'publish' !== $post->post_status ) {
				continue;
			}

			$type = in_array( (int) $page_id, $policy_ids, true ) ? 'wc' : 'page';

			foreach ( $this->post_entries( $post, $type ) as $entry ) {
				$entries[] = $entry;
			}

			$covered_ids[ (int) $page_id ] = true;
		}

		// 3) Bulk: every published item of each enabled post type, paged to keep
		// memory bounded on large sites.
		foreach ( $this->enabled_post_types() as $post_type ) {
			$paged = 1;

			do {
				$ids = get_posts(
					array(
						'post_type'        => $post_type,
						'post_status'      => 'publish',
						'posts_per_page'   => self::FETCH_BATCH,
						'paged'            => $paged,
						'fields'           => 'ids',
						'orderby'          => 'ID',
						'order'            => 'ASC',
						// get_posts() defaults this to true; wordpress.org prohibits
						// that, so set it explicitly to false.
						'suppress_filters' => false,
						'no_found_rows'    => true,
					)
				);

				$fetched_count = count( $ids );

				foreach ( $ids as $id ) {
					$id = (int) $id;

					if ( isset( $covered_ids[ $id ] ) ) {
						continue; // Already added via a curated/policy source.
					}

					$post = get_post( $id );

					if ( ! $post ) {
						continue;
					}

					foreach ( $this->post_entries( $post, $post_type ) as $entry ) {
						$entries[] = $entry;
					}

					$covered_ids[ $id ] = true;
				}

				++$paged;
			} while ( self::FETCH_BATCH === $fetched_count );
		}

		return $entries;
	}

	/**
	 * The enabled post types, intersected with the site's indexable public types
	 * so a stale setting can never reference a type that no longer exists.
	 *
	 * @return array
	 */
	private function enabled_post_types() {
		return array_intersect(
			Tuki_Settings::indexable_post_types(),
			array_map( 'sanitize_key', (array) Tuki_Settings::get( 'kb_post_types' ) )
		);
	}

	/**
	 * Builds the chunked KB entries for a single post.
	 *
	 * @param WP_Post $post        Post object.
	 * @param string  $source_type Source type to store the chunks under.
	 * @return array
	 */
	private function post_entries( $post, $source_type ) {
		$chunks  = $this->chunk( $this->post_text( $post ) );
		$title   = get_the_title( $post );
		$url     = get_permalink( $post );
		$entries = array();

		foreach ( $chunks as $chunk_index => $chunk_text ) {
			$entries[] = array(
				'source_type' => $source_type,
				'source_id'   => (int) $post->ID,
				'chunk'       => $chunk_index,
				'title'       => $title,
				'content'     => $chunk_text,
				'url'         => $url,
			);
		}

		return $entries;
	}

	/**
	 * The canonical source type a post is stored under, mirroring the decision
	 * made in gather_entries() so single-post and full reindex stay consistent.
	 *
	 * @param WP_Post $post Post object.
	 * @return string
	 */
	private function source_type_for( $post ) {
		$is_policy = in_array( (int) $post->ID, $this->wc_policy_page_ids(), true );
		$curated   = in_array( (int) $post->ID, array_map( 'absint', (array) Tuki_Settings::get( 'kb_pages' ) ), true );

		if ( $is_policy && ( $curated || Tuki_Settings::get( 'kb_wc_policies' ) ) ) {
			return 'wc';
		}

		return $post->post_type;
	}

	/**
	 * Extracts clean, searchable plain text from a post: title, content and
	 * excerpt with HTML and shortcodes stripped. Product entries also carry their
	 * category names so "do you sell …" questions match.
	 *
	 * @param WP_Post $post Post object.
	 * @return string
	 */
	private function post_text( $post ) {
		$parts = array( get_the_title( $post ), (string) $post->post_content );

		if ( ! empty( $post->post_excerpt ) ) {
			$parts[] = (string) $post->post_excerpt;
		}

		if ( 'product' === $post->post_type && taxonomy_exists( 'product_cat' ) ) {
			$terms = get_the_terms( $post->ID, 'product_cat' );

			if ( is_array( $terms ) ) {
				$names = wp_list_pluck( $terms, 'name' );

				if ( ! empty( $names ) ) {
					$parts[] = implode( ', ', $names );
				}
			}
		}

		$text = wp_strip_all_tags( strip_shortcodes( implode( "\n", $parts ) ) );
		$text = html_entity_decode( $text, ENT_QUOTES );

		return trim( preg_replace( '/\s+/', ' ', $text ) );
	}

	/**
	 * Splits text into ~CHUNK_WORDS-word chunks with CHUNK_OVERLAP words of
	 * overlap between neighbours, capped at MAX_CHUNKS to bound embedding cost.
	 *
	 * @param string $text Text.
	 * @return array
	 */
	private function chunk( $text ) {
		$text = trim( (string) $text );

		if ( '' === $text ) {
			return array();
		}

		$words = preg_split( '/\s+/', $text );
		$total = count( $words );

		if ( $total <= self::CHUNK_WORDS ) {
			return array( $text );
		}

		$chunks = array();
		$step   = max( 1, self::CHUNK_WORDS - self::CHUNK_OVERLAP );

		for ( $offset = 0; $offset < $total; $offset += $step ) {
			$slice    = array_slice( $words, $offset, self::CHUNK_WORDS );
			$chunks[] = trim( implode( ' ', $slice ) );

			// Stop at the chunk cap or once the last window reached the end.
			if ( count( $chunks ) >= self::MAX_CHUNKS || $offset + self::CHUNK_WORDS >= $total ) {
				break;
			}
		}

		return $chunks;
	}

	/**
	 * WooCommerce built-in policy page IDs (terms, privacy).
	 *
	 * @return array
	 */
	private function wc_policy_page_ids() {
		$ids = array();

		if ( function_exists( 'wc_terms_and_conditions_page_id' ) ) {
			$terms = (int) wc_terms_and_conditions_page_id();
			if ( $terms ) {
				$ids[] = $terms;
			}
		}

		if ( function_exists( 'wc_privacy_policy_page_id' ) ) {
			$privacy = (int) wc_privacy_policy_page_id();
			if ( $privacy ) {
				$ids[] = $privacy;
			}
		}

		return $ids;
	}

	/*
	 * Re-embed on change
	 */

	/**
	 * Re-indexes just the changed post (in the background) when a KB source is
	 * saved. Only that item is touched — never the whole knowledge base.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function on_save_post( $post_id ) {
		if ( wp_is_post_revision( $post_id ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
			return;
		}

		if ( ! $this->is_kb_source( $post_id ) ) {
			return;
		}

		$this->enqueue_post_reindex( $post_id );
	}

	/**
	 * Removes a post's KB rows when it is permanently deleted.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function on_delete_post( $post_id ) {
		Tuki_DB::delete_kb_post( absint( $post_id ) );
	}

	/**
	 * Queues a single-post reindex (async via Action Scheduler, or synchronously
	 * as a fallback), de-duplicating any already-scheduled run for the same post.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	private function enqueue_post_reindex( $post_id ) {
		$post_id = absint( $post_id );

		if ( function_exists( 'as_enqueue_async_action' ) ) {
			if ( ! function_exists( 'as_has_scheduled_action' ) || ! as_has_scheduled_action( self::HOOK_REINDEX_POST, array( $post_id ), 'tukify' ) ) {
				as_enqueue_async_action( self::HOOK_REINDEX_POST, array( $post_id ), 'tukify' );
			}
		} else {
			$this->reindex_post( $post_id );
		}
	}

	/**
	 * Whether a post is currently an indexed KB source: a published item of an
	 * enabled post type, a curated page, or a WooCommerce policy page.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private function is_kb_source( $post_id ) {
		$post_id = absint( $post_id );
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return false;
		}

		if ( in_array( $post->post_type, $this->enabled_post_types(), true ) ) {
			return true;
		}

		$pages = array_map( 'absint', (array) Tuki_Settings::get( 'kb_pages' ) );

		if ( in_array( $post_id, $pages, true ) ) {
			return true;
		}

		return Tuki_Settings::get( 'kb_wc_policies' ) && in_array( $post_id, $this->wc_policy_page_ids(), true );
	}

	/*
	 * Retrieval
	 */

	/**
	 * Returns the top-K knowledge-base snippets for a query.
	 *
	 * @param string $query Query text.
	 * @param int    $k     Number of snippets.
	 * @return array List of [ 'title', 'content', 'url', 'score' ], ranked descending.
	 */
	public function search( $query, $k = 3 ) {
		$query = trim( (string) $query );

		if ( '' === $query ) {
			return array();
		}

		$rows = Tuki_DB::kb_rows();

		if ( empty( $rows ) ) {
			return array();
		}

		$embeddings = new Tuki_Embeddings();
		$vector     = $embeddings->embed_query( $query );

		if ( empty( $vector ) ) {
			return array();
		}

		// In-database cosine ranking: linear in the number of chunks, loading
		// every stored embedding into PHP per query. This is fine up to a few
		// thousand chunks. Past tens of thousands, move retrieval to a real vector
		// store (pgvector, Qdrant, Pinecone, or MySQL 9's VECTOR type with an ANN
		// index) and replace this loop with an approximate-nearest-neighbour query
		// — the table already stores one embedding per chunk, so only this method
		// needs to change.
		$scored = array();

		foreach ( $rows as $row ) {
			$vec = json_decode( $row['embedding'], true );

			if ( ! is_array( $vec ) || empty( $vec ) ) {
				continue;
			}

			$scored[] = array(
				'title'   => $row['title'],
				'content' => $row['content'],
				'url'     => $row['url'],
				'score'   => self::cosine_similarity( $vector, $vec ),
			);
		}

		usort(
			$scored,
			static function ( $a, $b ) {
				return $b['score'] <=> $a['score'];
			}
		);

		return array_slice( $scored, 0, max( 1, (int) $k ) );
	}

	/**
	 * Cosine similarity between two float vectors.
	 *
	 * Returns a value in [-1, 1]: 1 = same direction, 0 = orthogonal,
	 * -1 = opposite. Returns 0.0 when either vector is empty or has zero
	 * magnitude (an undefined direction), so callers never divide by zero.
	 *
	 * @param array $a Vector A.
	 * @param array $b Vector B.
	 * @return float
	 */
	public static function cosine_similarity( array $a, array $b ) {
		$length = min( count( $a ), count( $b ) );

		if ( 0 === $length ) {
			return 0.0;
		}

		$dot   = 0.0;
		$mag_a = 0.0;
		$mag_b = 0.0;

		for ( $i = 0; $i < $length; $i++ ) {
			$x      = (float) $a[ $i ];
			$y      = (float) $b[ $i ];
			$dot   += $x * $y;
			$mag_a += $x * $x;
			$mag_b += $y * $y;
		}

		if ( $mag_a <= 0.0 || $mag_b <= 0.0 ) {
			return 0.0;
		}

		return $dot / ( sqrt( $mag_a ) * sqrt( $mag_b ) );
	}
}
