<?php
/**
 * Elementor integration: registers the "Tukify" category and widgets.
 *
 * @package Tukify
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires Tukify's Elementor widgets into the editor.
 */
class Tuki_Elementor {

	/**
	 * Hooks category + widget registration and editor asset enqueue.
	 */
	public function __construct() {
		add_action( 'elementor/elements/categories_registered', array( $this, 'add_category' ) );
		add_action( 'elementor/widgets/register', array( $this, 'register_widgets' ) );
		add_action( 'elementor/preview/enqueue_scripts', array( 'Tuki_Frontend', 'enqueue' ) );
	}

	/**
	 * Adds a dedicated "Tukify" widget category.
	 *
	 * @param \Elementor\Elements_Manager $manager Categories manager.
	 * @return void
	 */
	public function add_category( $manager ) {
		$manager->add_category(
			'tukify',
			array(
				'title' => __( 'Tukify', 'tukify' ),
				'icon'  => 'eicon-commenting-o',
			)
		);
	}

	/**
	 * Registers the three Tukify widgets.
	 *
	 * @param \Elementor\Widgets_Manager $widgets_manager Widgets manager.
	 * @return void
	 */
	public function register_widgets( $widgets_manager ) {
		require_once TUKI_PLUGIN_DIR . 'elementor/widgets/class-tuki-widget-base.php';
		require_once TUKI_PLUGIN_DIR . 'elementor/widgets/class-tuki-widget-chat.php';
		require_once TUKI_PLUGIN_DIR . 'elementor/widgets/class-tuki-widget-search.php';
		require_once TUKI_PLUGIN_DIR . 'elementor/widgets/class-tuki-widget-recommendations.php';

		$widgets_manager->register( new Tuki_Widget_Chat() );
		$widgets_manager->register( new Tuki_Widget_Search() );
		$widgets_manager->register( new Tuki_Widget_Recommendations() );
	}
}
