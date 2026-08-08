<?php
/**
 * Plugin Name: Elementor Freshsales
 * Description: Adds a "Freshsales" action to Elementor Pro forms that creates a Freshsales CRM lead from each submission, with field mapping.
 * Version:     1.3.1
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

const VERSION = '1.3.1';

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
 * Cookie holding the visitor's first-touch campaign data.
 *
 * Written by assets/js/campaign.js on the first page of the visit that carries any
 * tracked parameter, and read back in Freshsales_Action::get_campaign_data(). The
 * browser sends it with the form's admin-ajax POST, which is how the landing page's
 * query string survives a visitor browsing the site before submitting.
 */
const CAMPAIGN_COOKIE = 'csfs_campaign';

/**
 * How long the first-touch campaign cookie lives, in days.
 */
const CAMPAIGN_COOKIE_DAYS = 180;

/**
 * Longest value accepted for any single campaign parameter.
 *
 * The cookie is visitor-controlled, so every value is length-capped and sanitised
 * before it can reach the Freshsales payload.
 */
const CAMPAIGN_VALUE_MAX = 200;

/**
 * Query parameters captured from the landing URL.
 *
 * Single source of truth: injected into assets/js/campaign.js and used as the
 * allowlist when the cookie is read back, so nothing outside this list is ever sent.
 *
 * @return array<int, string>
 */
function get_campaign_params() {
	return array(
		// Standard UTM tags.
		'utm_source',
		'utm_medium',
		'utm_campaign',
		'utm_term',
		'utm_content',
		// Ad-platform click identifiers.
		'gclid',
		'gbraid',
		'wbraid',
		'fbclid',
		'msclkid',
		'ttclid',
	);
}

/**
 * Reserved `local_id` for the "Form Name" mapping row.
 *
 * Virtual sources use a `__` prefix so they can never collide with an Elementor form
 * field's custom id. Kept next to get_virtual_fields() as the single source.
 */
const FORM_NAME_SOURCE = '__form_name';

/**
 * Mappable sources that are not form fields.
 *
 * These are rendered above the form's own fields in the Field Mapping control, so a
 * value that lives on the form itself (rather than in a submitted field) can still be
 * sent to Freshsales. Resolved by Freshsales_Action::get_mapped_value().
 *
 * @return array<int, array<string, string>>
 */
function get_virtual_fields() {
	return array(
		array(
			'local_id' => FORM_NAME_SOURCE,
			'label'    => __( 'Form Name', 'elementor-freshsales' ),
		),
	);
}

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
			'group'           => __( 'Name & contact', 'elementor-freshsales' ),
		),
		array(
			'remote_id'       => 'last_name',
			'remote_label'    => __( 'Last Name', 'elementor-freshsales' ),
			'remote_type'     => 'text',
			'remote_required' => false,
			'group'           => __( 'Name & contact', 'elementor-freshsales' ),
		),
		array(
			'remote_id'       => 'email',
			'remote_label'    => __( 'Email', 'elementor-freshsales' ),
			'remote_type'     => 'email',
			'remote_required' => true,
			'group'           => __( 'Name & contact', 'elementor-freshsales' ),
		),
		array(
			'remote_id'       => 'mobile_number',
			'remote_label'    => __( 'Mobile', 'elementor-freshsales' ),
			'remote_type'     => 'text',
			'remote_required' => false,
			'group'           => __( 'Name & contact', 'elementor-freshsales' ),
		),
		array(
			'remote_id'       => 'company_name',
			'remote_label'    => __( 'Company Name', 'elementor-freshsales' ),
			'remote_type'     => 'text',
			'remote_required' => false,
			'group'           => __( 'Company', 'elementor-freshsales' ),
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
			'remote_id'       => 'cf_enquiry_type',
			'remote_label'    => __( 'Enquiry Type', 'elementor-freshsales' ),
			'remote_type'     => 'text',
			'remote_required' => false,
			'group'           => __( 'Enquiry', 'elementor-freshsales' ),
		),
		array(
			'remote_id'       => 'cf_product_enquiry',
			'remote_label'    => __( 'Product Enquiry', 'elementor-freshsales' ),
			'remote_type'     => 'text',
			'remote_required' => false,
			'group'           => __( 'Enquiry', 'elementor-freshsales' ),
		),
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
			'window.CornerstoneFreshsalesData = ' . wp_json_encode(
				array(
					'remoteFields'  => get_remote_fields(),
					'virtualFields' => get_virtual_fields(),
				)
			) . ';',
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
 * Capture first-touch campaign data on the front end.
 *
 * Enqueued on every page (not just pages with a form) and in the header, because the
 * visitor usually lands on a UTM-tagged URL and only reaches the form later.
 */
add_action(
	'wp_enqueue_scripts',
	function () {
		/**
		 * Filter whether first-touch campaign capture runs on the front end.
		 *
		 * Return false to stop the cookie ever being written — the hook a consent-management
		 * plugin or site owner needs to suppress capture without editing this file.
		 *
		 * @param bool $enabled Whether to enqueue the capture script.
		 */
		if ( ! apply_filters( 'cornerstone_freshsales_capture_campaign', true ) ) {
			return;
		}

		// Never set a tracking cookie on a site where the integration was never configured.
		require_once PLUGIN_PATH . 'includes/class-freshsales-handler.php';

		if ( '' === (string) get_option( 'elementor_' . Freshsales_Handler::OPTION_DOMAIN ) ) {
			return;
		}

		wp_enqueue_script(
			'cornerstone-freshsales-campaign',
			PLUGIN_URL . 'assets/js/campaign.js',
			array(),
			VERSION,
			false // Header: capture before the visitor can navigate away.
		);

		wp_add_inline_script(
			'cornerstone-freshsales-campaign',
			'window.CornerstoneFreshsalesCampaign = ' . wp_json_encode(
				array(
					'cookie' => CAMPAIGN_COOKIE,
					'days'   => CAMPAIGN_COOKIE_DAYS,
					'max'    => CAMPAIGN_VALUE_MAX,
					'params' => get_campaign_params(),
				)
			) . ';',
			'before'
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
