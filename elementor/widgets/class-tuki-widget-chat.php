<?php
/**
 * Tukify Chat Elementor widget — an embedded chat panel (inline or launcher).
 *
 * @package Tukify
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Chat widget.
 */
class Tuki_Widget_Chat extends Tuki_Widget_Base {

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return 'tukify_chat';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_title() {
		return __( 'Tukify Chat', 'tukify' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_icon() {
		return 'eicon-commenting-o';
	}

	/**
	 * Registers content + style controls.
	 *
	 * @return void
	 */
	protected function register_controls() {
		$this->start_controls_section(
			'tuki_content_section',
			array( 'label' => __( 'Chat', 'tukify' ) )
		);

		$this->add_control(
			'heading',
			array(
				'label'       => __( 'Heading', 'tukify' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => '',
				'placeholder' => __( 'Ask our assistant', 'tukify' ),
				'render_type' => 'template',
			)
		);

		$this->add_control(
			'mode',
			array(
				'label'       => __( 'Mode', 'tukify' ),
				'type'        => \Elementor\Controls_Manager::SELECT,
				'options'     => array(
					'inline'   => __( 'Inline panel', 'tukify' ),
					'launcher' => __( 'Launcher button', 'tukify' ),
				),
				'default'     => 'inline',
				'render_type' => 'template',
			)
		);

		$this->add_control(
			'placeholder',
			array(
				'label'       => __( 'Input placeholder', 'tukify' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => __( 'Ask about products…', 'tukify' ),
				'render_type' => 'template',
			)
		);

		$this->add_control(
			'height',
			array(
				'label'       => __( 'Height (px)', 'tukify' ),
				'type'        => \Elementor\Controls_Manager::SLIDER,
				'range'       => array( 'px' => array( 'min' => 320, 'max' => 800 ) ),
				'default'     => array( 'unit' => 'px', 'size' => 480 ),
				'condition'   => array( 'mode' => 'inline' ),
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
				'w'           => 'chat',
				'heading'     => isset( $s['heading'] ) ? $s['heading'] : '',
				'mode'        => isset( $s['mode'] ) ? $s['mode'] : 'inline',
				'placeholder' => isset( $s['placeholder'] ) ? $s['placeholder'] : '',
				'height'      => isset( $s['height']['size'] ) ? (int) $s['height']['size'] : 480,
			)
		);

		$this->render_mount( $config );
	}
}
