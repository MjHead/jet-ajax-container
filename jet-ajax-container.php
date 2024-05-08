<?php
/**
 * Plugin Name: Ajax Container for Elementor by Crocoblock
 * Plugin URI:  
 * Description: Allow to load content of any Elementor container asynchronous
 * Version:     1.0.0
 * Author:      Crocoblock
 * Author URI:  https://crocoblock.com/
 * License:     GPL-3.0+
 * License URI: http://www.gnu.org/licenses/gpl-3.0.txt
 * Domain Path: /languages
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die();
}

class Jet_Ajax_Container {

	private $has_ajax_containers = false;
	private $is_getting_ajax = false;

	public function __construct() {

		add_action(
			'elementor/element/container/section_layout_container/after_section_end',
			[ $this, 'register_controls' ]
		);

		add_action( 
			'elementor/frontend/container/before_render',
			[ $this, 'prevent_childern_for_ajax_containers' ]
		);

		add_filter(
			'elementor/frontend/container/should_render',
			[ $this, 'prevent_render_ajax_containers' ], 10, 2
		);

		add_action(
			'wp_footer',
			[ $this, 'print_assets' ], 9999
		);

		add_action(
			'init',
			[ $this, 'ajax_get_container' ], 9999
		);

	}

	/**
	 * Ajax callback to get container by it's ID and parent document ID
	 * 
	 * @return void
	 */
	public function ajax_get_container() {
		
		if ( empty( $_GET['jet_ajax_load_container'] ) || empty( $_GET['jet_ajax_document'] ) ) {
			return;
		}

		// Let callbacks know that we currently render content with Ajax
		$this->is_getting_ajax = true;

		$element_id = $_GET['jet_ajax_load_container'];
		$post_id    = absint( $_GET['jet_ajax_document'] );
		$container  = $this->find_widget_in_document( $post_id, $element_id );

		if ( $container ) {

			// Tell the world we doing AJAX request but in a bit unusual way
			add_filter( 'wp_doing_ajax', '__return_true' );

			ob_start();
			$container->print_element();
			$content = ob_get_clean();
			wp_send_json_success( $content );
		} else {
			wp_send_json_error();
		}

	}

	/**
	 * Print JS script to load containers with AJAX
	 * Printed only if at least 1 container found on the page
	 * 
	 * @return void
	 */
	public function print_assets() {

		if ( ! $this->has_ajax_containers ) {
			return;
		}

		$ajax_url = add_query_arg( [ 
			'jet_ajax_load_container' => 'container_id', 
			'jet_ajax_document'       => 'document_id' 
		] );

		?>
		<script>
			( function( $ ) {

				"use strict";

				const jetAjaxURL = '<?php echo $ajax_url; ?>';

				$( 'div[data-jet-ajax-container]' ).each( ( index, el ) => {

					const ajaxURL = jetAjaxURL.replace( 'container_id', el.dataset.jetAjaxContainer ).replace( 'document_id', el.dataset.document );

					$.ajax({
						url: ajaxURL,
						type: 'GET',
						dataType: 'json',
						data: {}
					}).then( ( response ) => {
						const $newData = $( response.data );
						$( el ).replaceWith( $newData );

						$newData.find('[data-element_type]').each( ( index, item ) => {
							
							const $this = $( item );
							let elementType = $this.data('element_type');

							if ( 'widget' === elementType ) {
								elementType = $this.data('widget_type');
								window.elementorFrontend.hooks.doAction ('frontend/element_ready/widget', $this, $ );
							}

							window.elementorFrontend.hooks.doAction( 'frontend/element_ready/global', $this, $ );
							window.elementorFrontend.hooks.doAction( 'frontend/element_ready/' + elementType, $this, $ );

						});

					} ).fail( () => {
						alert( 'Error!' );
					} );
				});

			} ( jQuery ) );
		</script>
		<?php
	}

	/**
	 * Reset children elements for containers marked to load with Ajax
	 * 
	 * @param  Elementor\Includes\Elements\Container $container Elementor container instance
	 * @return void
	 */
	public function prevent_childern_for_ajax_containers( $container ) {

		// Don't reset if we currently getting container content
		if ( $this->is_getting_ajax ) {
			return;
		}

		$enabled = filter_var( $container->get_settings( 'jet_ajax_container' ), FILTER_VALIDATE_BOOLEAN );

		if ( ! $enabled ) {
			return;
		}

		$this->has_ajax_containers = true;

		add_filter( 'elementor/element/get_child_type', '__return_false' );

	}

	/**
	 * Prevent containers marked to load with ajax from rendering any HTML markup
	 * 
	 * @param  boolean $should_render Render container or not
	 * @param  Elementor\Includes\Elements\Container $container Elementor container instance
	 * @return boolean
	 */
	public function prevent_render_ajax_containers( $should_render, $container ) {

		// Don't prevent if we currently loading content with Ajax
		if ( $this->is_getting_ajax ) {
			return $should_render;
		}

		$enabled = filter_var( $container->get_settings( 'jet_ajax_container' ), FILTER_VALIDATE_BOOLEAN );

		if ( ! $enabled ) {
			return $should_render;
		}

		remove_filter( 'elementor/element/get_child_type', '__return_false' );

		$current_document = \Elementor\Plugin::$instance->documents->get_current();

		if ( ! $current_document ) {
			$post_id = get_the_ID();
		} else {
			$post_id = $current_document->get_main_id();
		}

		printf(
			'<div data-jet-ajax-container="%1$s" data-document="%2$s"></div>',
			$container->get_id(),
			$post_id
		);

		return false;


	}

	/**
	 * Find filtered widget inside given page content
	 * 
	 * @param  int    $post_id   Post/Page/Document ID to search element in
	 * @param  string $widget_id widget/element ID to find
	 * @return element instance or false
	 */
	public function find_widget_in_document( $post_id, $widget_id ) {

		$elementor       = \Elementor\Plugin::instance();
		$document        = $elementor->documents->get( $post_id );
		$widget_instance = false;

		if ( $document ) {

			$widget = $this->find_widget_recursive( $document->get_elements_data(), $widget_id );

			if ( $widget ) {
				$widget_instance = $elementor->elements_manager->create_element_instance( $widget );
			}

		}

		return $widget_instance;

	}

	/**
	 * Find required widget in given widgets stack
	 * 
	 * @param  array  $widgets   widgets stack
	 * @param  string $widget_id widget ID to search
	 * @return element instance or false
	 */
	public function find_widget_recursive( $widgets, $widget_id ) {

		foreach ( $widgets as $widget ) {

			if ( $widget_id === $widget['id'] ) {
				return $widget;
			}

			if ( ! empty( $widget['elements'] ) ) {

				$widget = $this->find_widget_recursive( $widget['elements'], $widget_id );

				if ( $widget ) {
					return $widget;
				}
			}
		}

		return false;
	}

	/**
	 * Register controls
	 * 
	 * @param  Elementor\Includes\Elements\Container $widget constiner instance
	 * @return void
	 */
	public function register_controls( $widget ) {

		$widget->start_controls_section(
			'jet_ajax_section',
			array(
				'label' => 'Ajax Container',
				'tab'   => \Elementor\Controls_Manager::TAB_LAYOUT,
			)
		);

		$widget->add_control(
			'jet_ajax_container',
			array(
				'type'           => \Elementor\Controls_Manager::SWITCHER,
				'label'          => __( 'Enable', 'jet-engine' ),
				'render_type'    => 'template',
				'description'    => 'Load content of thiscontainer with Ajax',
				'style_transfer' => false,
			)
		);

		$widget->end_controls_section();

	}

}

new Jet_Ajax_Container();
