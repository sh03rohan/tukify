<?php
/**
 * Tukify Search Elementor widget — an AI semantic search bar with product cards.
 *
 * @package Tukify
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Search widget.
 */
class Tuki_Widget_Search extends Tuki_Widget_Base {

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return 'tukify_search';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_title() {
		return __( 'Tukify Search', 'tukify' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_icon() {
		return 'eicon-search';
	}

	/**
	 * Registers content + style controls.
	 *
	 * @return void
	 */
	protected function register_controls() {
		$this->start_controls_section(
			'tuki_content_section',
			array( 'label' => __( 'Search', 'tukify' ) )
		);

		$this->add_control(
			'heading',
			array(
				'label'       => __( 'Heading', 'tukify' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => __( 'Search products', 'tukify' ),
				'render_type' => 'template',
			)
		);

		$this->add_control(
			'placeholder',
			array(
				'label'       => __( 'Input placeholder', 'tukify' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => __( 'What are you looking for?', 'tukify' ),
				'render_type' => 'template',
			)
		);

		$this->add_control(
			'count',
			array(
				'label'       => __( 'Number of products', 'tukify' ),
				'type'        => \Elementor\Controls_Manager::NUMBER,
				'min'         => 1,
				'max'         => 24,
				'default'     => 6,
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
	 * Outputs the mount point.
	 *
	 * @return void
	 */
	protected function render() {
		$s = $this->get_settings_for_display();

		$config = array_merge(
			$this->style_config( $s ),
			array(
				'w'           => 'search',
				'heading'     => isset( $s['heading'] ) ? $s['heading'] : '',
				'placeholder' => isset( $s['placeholder'] ) ? $s['placeholder'] : '',
				'count'       => isset( $s['count'] ) ? (int) $s['count'] : 6,
				'columns'     => isset( $s['columns'] ) ? (int) $s['columns'] : 2,
			)
		);

		$this->render_mount( $config );
	}
}
