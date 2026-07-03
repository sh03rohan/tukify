<?php
/**
 * Shared base for Tukify Elementor widgets.
 *
 * Provides the common style controls (mapped to the widget's CSS variables) and
 * outputs the Shadow-DOM mount point the frontend JS initializes.
 *
 * @package Tukify
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abstract base widget.
 */
abstract class Tuki_Widget_Base extends \Elementor\Widget_Base {

	/**
	 * All Tukify widgets live under the "Tukify" category.
	 *
	 * @return array
	 */
	public function get_categories() {
		return array( 'tukify' );
	}

	/**
	 * Depend on the shared widget script (registered by Tuki_Frontend).
	 *
	 * @return array
	 */
	public function get_script_depends() {
		return array( Tuki_Frontend::HANDLE );
	}

	/**
	 * Registers the shared Style section. Controls use render_type "template" so
	 * changing them re-renders the mount for live preview inside the editor.
	 *
	 * @return void
	 */
	protected function register_style_controls() {
		$this->start_controls_section(
			'tuki_style_section',
			array(
				'label' => __( 'Style', 'tukify' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'tuki_scheme',
			array(
				'label'       => __( 'Color scheme', 'tukify' ),
				'type'        => \Elementor\Controls_Manager::SELECT,
				'options'     => array(
					'dark'  => __( 'Dark', 'tukify' ),
					'light' => __( 'Light', 'tukify' ),
				),
				'default'     => Tuki_Settings::get( 'color_scheme' ),
				'render_type' => 'template',
			)
		);

		$this->add_control(
			'tuki_accent',
			array(
				'label'       => __( 'Accent', 'tukify' ),
				'type'        => \Elementor\Controls_Manager::COLOR,
				'default'     => Tuki_Settings::get( 'accent_color' ),
				'render_type' => 'template',
			)
		);

		$this->add_control(
			'tuki_bg',
			array(
				'label'       => __( 'Background', 'tukify' ),
				'type'        => \Elementor\Controls_Manager::COLOR,
				'default'     => '',
				'render_type' => 'template',
			)
		);

		$this->add_control(
			'tuki_text',
			array(
				'label'       => __( 'Text', 'tukify' ),
				'type'        => \Elementor\Controls_Manager::COLOR,
				'default'     => '',
				'render_type' => 'template',
			)
		);

		$this->add_control(
			'tuki_radius',
			array(
				'label'       => __( 'Corner radius', 'tukify' ),
				'type'        => \Elementor\Controls_Manager::SLIDER,
				'range'       => array( 'px' => array( 'min' => 0, 'max' => 32 ) ),
				'default'     => array( 'unit' => 'px', 'size' => 16 ),
				'render_type' => 'template',
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Extracts the shared style settings into the mount config.
	 *
	 * @param array $s Widget settings.
	 * @return array
	 */
	protected function style_config( $s ) {
		return array(
			'accent' => isset( $s['tuki_accent'] ) ? $s['tuki_accent'] : '',
			'bg'     => isset( $s['tuki_bg'] ) ? $s['tuki_bg'] : '',
			'text'   => isset( $s['tuki_text'] ) ? $s['tuki_text'] : '',
			'scheme' => isset( $s['tuki_scheme'] ) ? $s['tuki_scheme'] : 'dark',
			'radius' => isset( $s['tuki_radius']['size'] ) ? (int) $s['tuki_radius']['size'] : 16,
		);
	}

	/**
	 * Enqueues the widget script and prints the mount point.
	 *
	 * @param array $config Instance config passed to the JS.
	 * @return void
	 */
	protected function render_mount( array $config ) {
		Tuki_Frontend::enqueue();

		printf(
			'<div class="tuki-mount" data-tuki-config="%s"></div>',
			esc_attr( wp_json_encode( $config ) )
		);
	}
}
