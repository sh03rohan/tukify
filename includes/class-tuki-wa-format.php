<?php
/**
 * Renders the shared reply object into WhatsApp-native messages.
 *
 * This is the only place that knows what a reply looks like on WhatsApp. The
 * brain returns the same object for every channel (see Tuki_Channel); the web
 * widget renders it as cards, and this renders it as text / an image with a
 * caption / an interactive list.
 *
 * Language: nothing here translates the assistant's words — the reply text comes
 * back from the model already in the shopper's language (same behaviour as the
 * web chat, Bangla included). Only Tukify's own labels are translated.
 *
 * @package Tukify
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Formats reply objects for WhatsApp.
 */
class Tuki_WA_Format {

	/**
	 * WhatsApp hard limits we format against.
	 */
	const MAX_CAPTION   = 1024;
	const MAX_BODY      = 4096;
	const MAX_ROWS      = 10;
	const ROW_TITLE     = 24;
	const ROW_DESC      = 72;
	const MAX_QUICK     = 3;

	/**
	 * Builds the outbound message descriptors for one reply.
	 *
	 * @param array $reply Reply object from Tuki_Channel::handle_message().
	 * @return array List of descriptors: text | image | list.
	 */
	public static function build( array $reply ) {
		$text     = trim( (string) ( $reply['reply'] ?? '' ) );
		$products = ( ! empty( $reply['products'] ) && is_array( $reply['products'] ) ) ? $reply['products'] : array();
		$out      = array();

		// Order status / size advice ask for details the web renders as a form —
		// on WhatsApp we keep the assistant's text and point at the site.
		if ( in_array( (string) ( $reply['intent'] ?? '' ), array( 'order_status', 'size_advice' ), true ) ) {
			$out[] = array(
				'type' => 'text',
				'text' => self::truncate( $text, self::MAX_BODY ),
			);

			return $out;
		}

		if ( empty( $products ) ) {
			// Clarifying questions can carry quick replies — append them as a hint.
			if ( ! empty( $reply['quick_replies'] ) && is_array( $reply['quick_replies'] ) ) {
				$options = array_slice( array_filter( array_map( 'strval', $reply['quick_replies'] ) ), 0, self::MAX_QUICK );

				if ( $options ) {
					$text .= "\n\n" . implode( "\n", array_map(
						static function ( $option ) {
							return '• ' . $option;
						},
						$options
					) );
				}
			}

			$out[] = array(
				'type' => 'text',
				'text' => self::truncate( $text, self::MAX_BODY ),
			);

			return $out;
		}

		// One product → image + caption (name, price, stock) + links.
		if ( 1 === count( $products ) ) {
			$product = $products[0];

			$out[] = array(
				'type'    => 'image',
				'link'    => (string) ( $product['image'] ?? '' ),
				'caption' => self::truncate( self::product_caption( $product, $text ), self::MAX_CAPTION ),
			);

			return $out;
		}

		// Several products → the assistant's text, then a tappable list. Selecting a
		// row sends its title back as a normal message, so the next turn narrows in.
		$out[] = array(
			'type' => 'text',
			'text' => self::truncate( $text, self::MAX_BODY ),
		);

		$rows     = array();
		$fallback = array();

		foreach ( array_slice( $products, 0, self::MAX_ROWS ) as $product ) {
			$title = self::truncate( (string) ( $product['title'] ?? '' ), self::ROW_TITLE );

			if ( '' === $title ) {
				continue;
			}

			$rows[] = array(
				'id'          => 'p_' . absint( $product['id'] ?? 0 ),
				'title'       => $title,
				'description' => self::truncate( self::product_line( $product ), self::ROW_DESC ),
			);

			$fallback[] = '• ' . (string) ( $product['title'] ?? '' ) . ' — ' . self::product_line( $product );
		}

		if ( $rows ) {
			$out[] = array(
				'type'     => 'list',
				'body'     => __( 'Tap to see one of these:', 'tukify' ),
				'button'   => __( 'View products', 'tukify' ),
				'section'  => __( 'Products', 'tukify' ),
				'rows'     => $rows,
				// If the interactive send is rejected, we still say something useful.
				'fallback' => self::truncate( implode( "\n", $fallback ), self::MAX_BODY ),
			);
		}

		return $out;
	}

	/**
	 * Caption for a single product: the assistant's line, then name/price/stock and
	 * the two links (product page + tokenized add-to-cart).
	 *
	 * @param array  $product Product card payload.
	 * @param string $intro   The assistant's reply text.
	 * @return string
	 */
	private static function product_caption( array $product, $intro ) {
		$lines = array();

		$intro = trim( (string) $intro );

		if ( '' !== $intro ) {
			$lines[] = $intro;
			$lines[] = '';
		}

		$title = (string) ( $product['title'] ?? '' );

		if ( '' !== $title ) {
			// *bold* is WhatsApp's markup for emphasis.
			$lines[] = '*' . $title . '*';
		}

		$line = self::product_line( $product );

		if ( '' !== $line ) {
			$lines[] = $line;
		}

		$url = (string) ( $product['url'] ?? '' );

		if ( '' !== $url ) {
			$lines[] = '';
			/* translators: %s: product page URL. */
			$lines[] = sprintf( __( 'View: %s', 'tukify' ), $url );
		}

		// Add to cart is only offered when the product can actually be added.
		if ( ! empty( $product['add_to_cart'] ) && ! empty( $product['id'] ) ) {
			/* translators: %s: tokenized add-to-cart URL. */
			$lines[] = sprintf( __( 'Add to cart: %s', 'tukify' ), Tuki_WhatsApp::add_to_cart_url( (int) $product['id'] ) );
		}

		return implode( "\n", $lines );
	}

	/**
	 * One-line price + stock summary.
	 *
	 * @param array $product Product card payload.
	 * @return string
	 */
	private static function product_line( array $product ) {
		$bits = array();

		$price = trim( (string) ( $product['price'] ?? '' ) );

		if ( '' !== $price ) {
			$bits[] = $price;
		}

		$bits[] = empty( $product['stock'] )
			? __( 'Out of stock', 'tukify' )
			: __( 'In stock', 'tukify' );

		return implode( ' · ', $bits );
	}

	/**
	 * Truncates to a hard limit without cutting mid-word where avoidable.
	 *
	 * @param string $text  Text.
	 * @param int    $limit Max characters.
	 * @return string
	 */
	private static function truncate( $text, $limit ) {
		$text = trim( (string) $text );

		if ( mb_strlen( $text ) <= $limit ) {
			return $text;
		}

		return rtrim( mb_substr( $text, 0, $limit - 1 ) ) . '…';
	}
}
