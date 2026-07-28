<?php
/**
 * Plugin Name: Elementor Freshsales
 * Description: Adds a "Freshsales" action to Elementor Pro forms that creates a Freshsales CRM lead from each submission, with field mapping.
 * Version:     1.0.0
 * Author:      Cornerstone
 * Text Domain: elementor-freshsales
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package Cornerstone\Elementor_Freshsales
 */

namespace Cornerstone\Elementor_Freshsales;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

const VERSION = '1.0.0';

define( __NAMESPACE__ . '\PLUGIN_FILE', __FILE__ );
define( __NAMESPACE__ . '\PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( __NAMESPACE__ . '\PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Register the Freshsales form action with Elementor Pro.
 *
 * The action class is only required here because it extends an Elementor Pro
 * base class that does not exist until Pro is loaded — this hook guarantees it.
 */
add_action(
	'elementor_pro/forms/actions/register',
	function ( $actions_registrar ) {
		require_once PLUGIN_PATH . 'includes/class-freshsales-handler.php';
		require_once PLUGIN_PATH . 'includes/class-freshsales-map-control.php';
		require_once PLUGIN_PATH . 'includes/class-freshsales-action.php';

		$actions_registrar->register( new Freshsales_Action() );
	}
);

/**
 * Register the custom "form field → Freshsales field" mapping control.
 */
add_action(
	'elementor/controls/register',
	function ( $controls_manager ) {
		require_once PLUGIN_PATH . 'includes/class-freshsales-map-control.php';
		$controls_manager->register( new Freshsales_Map_Control() );
	}
);

/**
 * The Freshsales lead fields offered in the form's Field Mapping control.
 *
 * Single source of truth: consumed by the editor script (below) and matched by
 * Freshsales_Action::run() when building the lead payload.
 *
 * @return array<int, array<string, mixed>>
 */
function get_remote_fields() {
	return array(
		array(
			'remote_id'       => 'first_name',
			'remote_label'    => __( 'First Name', 'elementor-freshsales' ),
			'remote_type'     => 'text',
			'remote_required' => false,
			'group'           => __( 'Contact', 'elementor-freshsales' ),
		),
		array(
			'remote_id'       => 'last_name',
			'remote_label'    => __( 'Last Name', 'elementor-freshsales' ),
			'remote_type'     => 'text',
			'remote_required' => false,
			'group'           => __( 'Contact', 'elementor-freshsales' ),
		),
		array(
			'remote_id'       => 'email',
			'remote_label'    => __( 'Email', 'elementor-freshsales' ),
			'remote_type'     => 'email',
			'remote_required' => true,
			'group'           => __( 'Contact', 'elementor-freshsales' ),
		),
		array(
			'remote_id'       => 'mobile_number',
			'remote_label'    => __( 'Mobile', 'elementor-freshsales' ),
			'remote_type'     => 'text',
			'remote_required' => false,
			'group'           => __( 'Contact', 'elementor-freshsales' ),
		),
		array(
			'remote_id'       => 'company_name',
			'remote_label'    => __( 'Company Name', 'elementor-freshsales' ),
			'remote_type'     => 'text',
			'remote_required' => false,
			'group'           => __( 'Company', 'elementor-freshsales' ),
		),
		array(
			'remote_id'       => 'medium',
			'remote_label'    => __( 'Medium', 'elementor-freshsales' ),
			'remote_type'     => 'text',
			'remote_required' => false,
			'group'           => __( 'Source information', 'elementor-freshsales' ),
		),
		array(
			'remote_id'       => 'keyword',
			'remote_label'    => __( 'Keyword', 'elementor-freshsales' ),
			'remote_type'     => 'text',
			'remote_required' => false,
			'group'           => __( 'Source information', 'elementor-freshsales' ),
		),
		array(
			'remote_id'       => 'notes',
			'remote_label'    => __( 'Recent Note', 'elementor-freshsales' ),
			'remote_type'     => 'text',
			'remote_required' => false,
			'group'           => __( 'Notes', 'elementor-freshsales' ),
		),
		// Custom lead fields (remote_id prefixed "cf_") are written into the lead's
		// custom_field object by run(). Add more of your account's custom fields here.
		array(
			'remote_id'       => 'cf_notes',
			'remote_label'    => __( 'Notes', 'elementor-freshsales' ),
			'remote_type'     => 'text',
			'remote_required' => false,
			'group'           => __( 'Notes', 'elementor-freshsales' ),
		),
	);
}

/**
 * Enqueue the editor script that populates the Freshsales field-mapping control,
 * and hand it the field list so the list lives in exactly one place (PHP).
 */
add_action(
	'elementor/editor/after_enqueue_scripts',
	function () {
		wp_enqueue_script(
			'cornerstone-freshsales-editor',
			PLUGIN_URL . 'assets/js/editor.js',
			array( 'elementor-editor' ),
			VERSION,
			true
		);

		wp_add_inline_script(
			'cornerstone-freshsales-editor',
			'window.CornerstoneFreshsalesData = ' . wp_json_encode( array( 'remoteFields' => get_remote_fields() ) ) . ';',
			'before'
		);

		wp_enqueue_style(
			'cornerstone-freshsales-editor',
			PLUGIN_URL . 'assets/css/editor.css',
			array(),
			VERSION
		);
	}
);

/**
 * Show an admin notice if Elementor Pro is not active — the plugin does nothing without it.
 */
add_action(
	'admin_notices',
	function () {
		if ( did_action( 'elementor_pro/init' ) || class_exists( '\ElementorPro\Plugin' ) ) {
			return;
		}

		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html__( 'Elementor Freshsales requires Elementor Pro (with the Forms widget) to be active.', 'elementor-freshsales' )
		);
	}
);
