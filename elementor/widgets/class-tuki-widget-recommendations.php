<?php
/**
 * Tukify Recommendations Elementor widget — cart/context-aware "you might like".
 *
 * @package Tukify
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Recommendations widget.
 */
class Tuki_Widget_Recommendations extends Tuki_Widget_Base {

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return 'tukify_recommendations';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_title() {
		return __( 'Tukify Recommendations', 'tukify' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_icon() {
		return 'eicon-products';
	}

	/**
	 * Registers content + style controls.
	 *
	 * @return void
	 */
	protected function register_controls() {
		$this->start_controls_section(
			'tuki_content_section',
			array( 'label' => __( 'Recommendations', 'tukify' ) )
		);

		$this->add_control(
			'heading',
			array(
				'label'       => __( 'Heading', 'tukify' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => __( 'You might like', 'tukify' ),
				'render_type' => 'template',
			)
		);

		$this->add_control(
			'count',
			array(
				'label'       => __( 'Number of products', 'tukify' ),
				'type'        => \Elementor\Controls_Manager::NUMBER,
				'min'         => 1,
				'max'         => 12,
				'default'     => 4,
				'render_type' => 'template',
			)
		);

		$this->add_control(
			'columns',
			array(
				'label'       => __( 'Columns', 'tukify' ),
				'type'        => \Elementor\Controls_Manager::NUMBER,
				'min'         => 1,
				'max'         => 4,
				'default'     => 2,
				'render_type' => 'template',
			)
		);

		$this->end_controls_section();

		$this->register_style_controls();
	}

	/**
	 * Outputs the mount point, seeded with the current page context.
	 *
	 * @return void
	 */
	protected function render() {
		$s = $this->get_settings_for_display();

		$product_id  = 0;
		$category_id = 0;

		if ( function_exists( 'is_product' ) && is_product() ) {
			$product_id = (int) get_the_ID();
		} elseif ( function_exists( 'is_product_category' ) && is_product_category() ) {
			$term = get_queried_object();

			if ( $term && isset( $term->term_id ) ) {
				$category_id = (int) $term->term_id;
			}
		}

		$config = array_merge(
			$this->style_config( $s ),
			array(
				'w'           => 'recs',
				'heading'     => isset( $s['heading'] ) ? $s['heading'] : '',
				'count'       => isset( $s['count'] ) ? (int) $s['count'] : 4,
				'columns'     => isset( $s['columns'] ) ? (int) $s['columns'] : 2,
				'product_id'  => $product_id,
				'category_id' => $category_id,
			)
		);

		$this->render_mount( $config );
	}
}
