<?php
/**
 * Freshsales form action for Elementor Pro forms.
 *
 * @package Cornerstone\Elementor_Freshsales
 */

namespace Cornerstone\Elementor_Freshsales;

use Elementor\Controls_Manager;
use Elementor\Repeater;
use Elementor\Settings;
use ElementorPro\Modules\Forms\Classes\Integration_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Registers the "Freshsales" submit action and creates a lead on submission.
 */
class Freshsales_Action extends Integration_Base {

	/**
	 * AJAX action name for the "Validate" button.
	 */
	const VALIDATE_ACTION = 'cornerstone_freshsales_validate';

	/**
	 * Lead source applied to every lead (hardcoded per requirements).
	 */
	const LEAD_SOURCE = 'Web Form';

	public function __construct() {
		if ( is_admin() ) {
			add_action( 'elementor/admin/after_create_settings/' . Settings::PAGE_ID, array( $this, 'register_admin_fields' ), 15 );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		}

		add_action( 'wp_ajax_' . self::VALIDATE_ACTION, array( $this, 'ajax_validate' ) );
	}

	public function get_name() {
		return 'freshsales';
	}

	public function get_label() {
		return esc_html__( 'Freshsales', 'elementor-freshsales' );
	}

	private function get_global_api_key() {
		return get_option( 'elementor_' . Freshsales_Handler::OPTION_API_KEY );
	}

	private function get_global_domain() {
		return get_option( 'elementor_' . Freshsales_Handler::OPTION_DOMAIN );
	}

	/**
	 * Build the Freshsales controls shown in the form widget's Actions panel.
	 *
	 * @param \ElementorPro\Modules\Forms\Widgets\Form $widget Form widget.
	 */
	public function register_settings_section( $widget ) {
		$widget->start_controls_section(
			'section_freshsales',
			array(
				'label'     => esc_html__( 'Freshsales', 'elementor-freshsales' ),
				'condition' => array(
					'submit_actions' => $this->get_name(),
				),
			)
		);

		self::global_api_control(
			$widget,
			$this->get_global_api_key(),
			esc_html__( 'Freshsales API Key', 'elementor-freshsales' ),
			array(
				'freshsales_api_key_source' => 'default',
			),
			$this->get_name()
		);

		$widget->add_control(
			'freshsales_api_key_source',
			array(
				'label'       => esc_html__( 'API Key', 'elementor-freshsales' ),
				'type'        => Controls_Manager::SELECT,
				'label_block' => false,
				'options'     => array(
					'default' => esc_html__( 'Default', 'elementor-freshsales' ),
					'custom'  => esc_html__( 'Custom', 'elementor-freshsales' ),
				),
				'default'     => 'default',
			)
		);

		$widget->add_control(
			'freshsales_custom_api_key',
			array(
				'label'       => esc_html__( 'Custom API Key', 'elementor-freshsales' ),
				'type'        => Controls_Manager::TEXT,
				'condition'   => array(
					'freshsales_api_key_source' => 'custom',
				),
				'description' => esc_html__( 'Use a custom Freshsales API Key for this form. The domain from Elementor → Settings → Integrations is always used.', 'elementor-freshsales' ),
				'ai'          => array(
					'active' => false,
				),
			)
		);

		// The domain is global-only and required — warn where the mapping is done if it's missing.
		if ( '' === (string) $this->get_global_domain() ) {
			$widget->add_control(
				'freshsales_domain_missing',
				array(
					'type'       => Controls_Manager::ALERT,
					'alert_type' => 'warning',
					'content'    => sprintf(
						/* translators: 1: opening link tag, 2: closing link tag. */
						esc_html__( 'Set your Freshsales Domain in the %1$sIntegrations Settings%2$s to enable lead creation.', 'elementor-freshsales' ),
						sprintf( '<a href="%s" target="_blank">', esc_url( Settings::get_settings_tab_url( 'integrations' ) ) ),
						'</a>'
					),
				)
			);
		}

		$this->register_map_control( $widget );

		$widget->add_control(
			'freshsales_capture_campaign',
			array(
				'label'       => esc_html__( 'Capture Campaign Data', 'elementor-freshsales' ),
				'type'        => Controls_Manager::SWITCHER,
				'default'     => 'yes',
				'separator'   => 'before',
				'description' => esc_html__( 'Record the UTM tags and ad click IDs from the page the visitor first arrived on, and send them with the lead. Anything you have mapped above takes precedence.', 'elementor-freshsales' ),
			)
		);

		$widget->end_controls_section();
	}

	/**
	 * Add the inverted mapping control: one row per form field, each with a Freshsales
	 * dropdown. Item shape stays { local_id, remote_id }; the editor script fills each row.
	 *
	 * @param \ElementorPro\Modules\Forms\Widgets\Form $widget Form widget.
	 */
	private function register_map_control( $widget ) {
		$repeater = new Repeater();
		$repeater->add_control( 'local_id', array( 'type' => Controls_Manager::HIDDEN ) );
		$repeater->add_control( 'remote_id', array( 'type' => Controls_Manager::SELECT ) );

		$widget->add_control(
			'freshsales_fields_map',
			array(
				'label'       => esc_html__( 'Field Mapping', 'elementor-freshsales' ),
				'description' => esc_html__( 'Every submission creates a new lead in Freshsales.', 'elementor-freshsales' ),
				'type'        => Freshsales_Map_Control::CONTROL_TYPE,
				'separator'   => 'before',
				'fields'      => $repeater->get_controls(),
				// Only show the mapping once an API key is available.
				'conditions'  => array(
					'relation' => 'or',
					'terms'    => array(
						array(
							'name'     => 'freshsales_custom_api_key',
							'operator' => '!==',
							'value'    => '',
						),
						array(
							'name'     => 'freshsales_api_key_source',
							'operator' => '=',
							'value'    => 'default',
						),
					),
				),
			)
		);
	}

	/**
	 * Strip plugin settings from exported templates (never export credentials/mapping).
	 *
	 * @param array $element Element data.
	 * @return array
	 */
	public function on_export( $element ) {
		unset(
			$element['settings']['freshsales_api_key_source'],
			$element['settings']['freshsales_custom_api_key'],
			$element['settings']['freshsales_fields_map']
		);

		return $element;
	}

	/**
	 * Create the Freshsales lead on form submission.
	 *
	 * @param \ElementorPro\Modules\Forms\Classes\Form_Record  $record       Submission record.
	 * @param \ElementorPro\Modules\Forms\Classes\Ajax_Handler $ajax_handler Ajax handler.
	 *
	 * @throws \Exception On unrecoverable failure (surfaced to admins by Elementor).
	 */
	public function run( $record, $ajax_handler ) {
		$form_settings = $record->get( 'form_settings' );

		// Respect the admin's explicit choice: 'custom' uses the per-form key even when
		// empty (so the handler surfaces a clear "missing key" error), otherwise the global key.
		$source = isset( $form_settings['freshsales_api_key_source'] ) ? $form_settings['freshsales_api_key_source'] : 'default';
		if ( 'custom' === $source ) {
			$api_key = isset( $form_settings['freshsales_custom_api_key'] ) ? $form_settings['freshsales_custom_api_key'] : '';
		} else {
			$api_key = $this->get_global_api_key();
		}
		$domain = $this->get_global_domain();

		$lead = $this->build_lead( $record );

		// First-touch campaign data, when the form opts in. Never overrides the mapping.
		// Absent on forms saved before this setting existed — default to capturing.
		$capture  = isset( $form_settings['freshsales_capture_campaign'] ) ? $form_settings['freshsales_capture_campaign'] : 'yes';
		$campaign = ( 'yes' === $capture ) ? $this->get_campaign_data() : array();

		$this->apply_campaign_fields( $lead, $campaign );

		// Freshsales requires at least one contact method to create a lead.
		if ( empty( $lead['email'] ) && empty( $lead['mobile_number'] ) ) {
			throw new \Exception( esc_html__( 'requires a mapped Email or Mobile field with a value.', 'elementor-freshsales' ) );
		}

		try {
			$handler = new Freshsales_Handler( $api_key, $domain );

			// Best-effort lead source — never blocks lead creation.
			$source_id = $handler->get_lead_source_id( self::LEAD_SOURCE );
			if ( $source_id > 0 ) {
				$lead['lead_source_id'] = $source_id;
			}

			// Campaign is a reference field: resolve the utm_campaign name to an id.
			// Best-effort — an unknown campaign simply stays in the campaign note.
			if ( ! empty( $campaign['utm_campaign'] ) && empty( $lead['campaign_id'] ) ) {
				$campaign_id = $handler->get_campaign_id( $campaign['utm_campaign'] );
				if ( $campaign_id > 0 ) {
					$lead['campaign_id'] = $campaign_id;
				}
			}

			$lead_id = $handler->create_lead( $lead );

			// Best-effort notes — never block lead creation.
			$note = sanitize_textarea_field( $this->get_mapped_value( $record, 'notes' ) );
			if ( $lead_id > 0 && '' !== $note ) {
				$handler->add_note( $lead_id, $note );
			}

			// Attribution goes in its own note so it never dilutes the enquiry note.
			$campaign_note = $this->build_campaign_note( $campaign );
			if ( $lead_id > 0 && '' !== $campaign_note ) {
				$handler->add_note( $lead_id, $campaign_note );
			}
		} catch ( \Exception $e ) {
			// A transient Freshsales problem (network/timeout = code 0, rate-limit 408/429,
			// server 5xx) must NOT break the visitor's submission — skip this lead.
			// Configuration/auth errors (missing key/domain, bad token, bad request = 4xx)
			// are re-thrown so Elementor shows them to logged-in admins to fix.
			$code = (int) $e->getCode();
			if ( 0 === $code || 408 === $code || 429 === $code || $code >= 500 ) {
				return;
			}
			throw $e;
		}
	}

	/**
	 * Read the visitor's first-touch campaign data back out of the cookie.
	 *
	 * SECURITY: the cookie is written by the browser and is therefore entirely
	 * visitor-controlled. Only keys on the allowlist are accepted, every value is
	 * length-capped and sanitised, and the payload size is bounded — nothing here may
	 * be trusted on its way into the Freshsales request.
	 *
	 * @return array<string, string> Sanitised parameter => value pairs (possibly empty).
	 */
	private function get_campaign_data() {
		if ( empty( $_COOKIE[ CAMPAIGN_COOKIE ] ) ) {
			return array();
		}

		$raw = (string) wp_unslash( $_COOKIE[ CAMPAIGN_COOKIE ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised per value below.

		// Bound the work before decoding: a cookie this large is not ours.
		if ( strlen( $raw ) > 4096 ) {
			return array();
		}

		$decoded = json_decode( $raw, true );

		if ( ! is_array( $decoded ) ) {
			return array();
		}

		$allowed = array_merge( get_campaign_params(), array( 'landing_page', 'referrer' ) );
		$data    = array();

		foreach ( $allowed as $key ) {
			if ( ! isset( $decoded[ $key ] ) || ! is_scalar( $decoded[ $key ] ) ) {
				continue;
			}

			$value = sanitize_text_field( substr( (string) $decoded[ $key ], 0, CAMPAIGN_VALUE_MAX ) );

			if ( '' !== $value ) {
				$data[ $key ] = $value;
			}
		}

		return $data;
	}

	/**
	 * Apply captured campaign data to the lead, without disturbing explicit mappings.
	 *
	 * Only fills fields the field mapping left empty — a deliberate mapping always wins.
	 *
	 * @param array $lead     Lead payload built from the field mapping (by reference).
	 * @param array $campaign Sanitised campaign data.
	 */
	private function apply_campaign_fields( array &$lead, array $campaign ) {
		$targets = array(
			'utm_medium' => 'medium',
			'utm_term'   => 'keyword',
		);

		foreach ( $targets as $param => $remote_id ) {
			if ( isset( $campaign[ $param ] ) && empty( $lead[ $remote_id ] ) ) {
				$lead[ $remote_id ] = $campaign[ $param ];
			}
		}
	}

	/**
	 * Build the timeline note that records how the lead found the site.
	 *
	 * Written as its own note so it never dilutes the enquiry note the form's own
	 * fields produce. Returns '' when there is nothing worth recording.
	 *
	 * @param array $campaign Sanitised campaign data.
	 * @return string
	 */
	private function build_campaign_note( array $campaign ) {
		if ( empty( $campaign ) ) {
			return '';
		}

		$labels = array(
			'utm_source'   => __( 'Source', 'elementor-freshsales' ),
			'utm_medium'   => __( 'Medium', 'elementor-freshsales' ),
			'utm_campaign' => __( 'Campaign', 'elementor-freshsales' ),
			'utm_term'     => __( 'Term', 'elementor-freshsales' ),
			'utm_content'  => __( 'Content', 'elementor-freshsales' ),
			'gclid'        => __( 'Google click ID', 'elementor-freshsales' ),
			'gbraid'       => __( 'Google click ID (gbraid)', 'elementor-freshsales' ),
			'wbraid'       => __( 'Google click ID (wbraid)', 'elementor-freshsales' ),
			'fbclid'       => __( 'Facebook click ID', 'elementor-freshsales' ),
			'msclkid'      => __( 'Microsoft click ID', 'elementor-freshsales' ),
			'ttclid'       => __( 'TikTok click ID', 'elementor-freshsales' ),
			'landing_page' => __( 'Landing page', 'elementor-freshsales' ),
			'referrer'     => __( 'Referrer', 'elementor-freshsales' ),
		);

		$lines = array();

		foreach ( $labels as $key => $label ) {
			if ( isset( $campaign[ $key ] ) ) {
				$lines[] = $label . ': ' . $campaign[ $key ];
			}
		}

		if ( empty( $lines ) ) {
			return '';
		}

		array_unshift( $lines, __( 'Campaign data captured from the visitor’s first visit:', 'elementor-freshsales' ) );

		return implode( "\n", $lines );
	}

	/**
	 * Assemble the sanitised lead payload from the mapped form fields.
	 *
	 * @param \ElementorPro\Modules\Forms\Classes\Form_Record $record Submission record.
	 * @return array
	 */
	private function build_lead( $record ) {
		$lead = array();

		// Plain, top-level text fields on the lead.
		foreach ( array( 'first_name', 'last_name', 'mobile_number', 'medium', 'keyword' ) as $remote_id ) {
			$value = sanitize_text_field( $this->get_mapped_value( $record, $remote_id ) );
			if ( '' !== $value ) {
				$lead[ $remote_id ] = $value;
			}
		}

		// Email is validated before being sent.
		$email = sanitize_email( $this->get_mapped_value( $record, 'email' ) );
		if ( '' !== $email && is_email( $email ) ) {
			$lead['email'] = $email;
		}

		// Company is a nested object on the lead.
		$company = sanitize_text_field( $this->get_mapped_value( $record, 'company_name' ) );
		if ( '' !== $company ) {
			$lead['company'] = array( 'name' => $company );
		}

		// Custom fields — any remote id prefixed "cf_" — go into the lead's custom_field object.
		// Add more of your account's custom fields to get_remote_fields() and they land here automatically.
		$custom = array();
		foreach ( get_remote_fields() as $remote_field ) {
			$remote_id = $remote_field['remote_id'];
			if ( 0 !== strpos( $remote_id, 'cf_' ) ) {
				continue;
			}
			// Textarea sanitiser: these are free-text fields, so a value concatenated from
			// several form fields keeps one entry per line instead of running together.
			$value = sanitize_textarea_field( $this->get_mapped_value( $record, $remote_id ) );
			if ( '' !== $value ) {
				$custom[ $remote_id ] = $value;
			}
		}
		if ( ! empty( $custom ) ) {
			$lead['custom_field'] = $custom;
		}

		return $lead;
	}

	/**
	 * Resolve the submitted value(s) for a mapped remote field.
	 *
	 * When more than one source is mapped to the same Freshsales field, every non-empty
	 * value is concatenated (newline-separated, panel order) instead of the first one winning.
	 *
	 * @param \ElementorPro\Modules\Forms\Classes\Form_Record $record    Submission record.
	 * @param string                                          $remote_id Remote field id.
	 * @return string
	 */
	private function get_mapped_value( $record, $remote_id ) {
		$fields = $record->get( 'fields' );
		$map    = $record->get_form_settings( 'freshsales_fields_map' );

		if ( ! is_array( $map ) ) {
			return '';
		}

		$values = array();

		foreach ( $map as $item ) {
			if ( ! isset( $item['remote_id'] ) || $item['remote_id'] !== $remote_id ) {
				continue;
			}

			$local_id = isset( $item['local_id'] ) ? $item['local_id'] : '';

			if ( '' === $local_id ) {
				continue;
			}

			// Virtual sources live on the form itself, not in the submitted fields.
			if ( FORM_NAME_SOURCE === $local_id ) {
				$value = (string) $record->get_form_settings( 'form_name' );
			} elseif ( isset( $fields[ $local_id ]['value'] ) ) {
				$value = (string) $fields[ $local_id ]['value'];
			} else {
				$value = '';
			}

			$value = trim( $value );

			if ( '' !== $value ) {
				$values[] = $value;
			}
		}

		// Several sources may point at one Freshsales field — keep them all rather than
		// letting the first win, in the order the rows appear in the panel, one per line.
		// Note destinations are sanitised with sanitize_textarea_field() so the breaks
		// survive; single-line destinations collapse them to spaces via sanitize_text_field(),
		// so a doubled-up name still reads "John Smith".
		return implode( "\n", $values );
	}

	/**
	 * Add the API key + domain fields (and a Validate button) to Elementor's Integrations tab.
	 *
	 * @param Settings $settings Elementor settings manager.
	 */
	public function register_admin_fields( Settings $settings ) {
		$settings->add_section(
			Settings::TAB_INTEGRATIONS,
			'freshsales',
			array(
				'callback' => function () {
					echo '<hr><h2>' . esc_html__( 'Freshsales', 'elementor-freshsales' ) . '</h2>';
				},
				'fields'   => array(
					Freshsales_Handler::OPTION_DOMAIN  => array(
						'label'      => esc_html__( 'Domain', 'elementor-freshsales' ),
						'field_args' => array(
							'type' => 'text',
							'desc' => esc_html__( 'Your Freshsales account domain, e.g. yourcompany.freshsales.io', 'elementor-freshsales' ),
						),
					),
					Freshsales_Handler::OPTION_API_KEY => array(
						'label'      => esc_html__( 'API Key', 'elementor-freshsales' ),
						'field_args' => array(
							'type' => 'text',
							'desc' => sprintf(
								/* translators: 1: opening anchor tag, 2: closing anchor tag. */
								esc_html__( 'Find your API key in Freshsales under %1$sProfile Settings → API Settings%2$s.', 'elementor-freshsales' ),
								'<a href="https://developer.freshsales.io/api/#authentication" target="_blank" rel="noopener noreferrer">',
								'</a>'
							),
						),
					),
					'validate_api_data'  => array(
						'field_args' => array(
							'type' => 'raw_html',
							'html' => sprintf(
								'<button data-action="%1$s" data-nonce="%2$s" class="button elementor-button-spinner" id="cornerstone_freshsales_validate_button">%3$s</button>',
								esc_attr( self::VALIDATE_ACTION ),
								esc_attr( wp_create_nonce( self::VALIDATE_ACTION ) ),
								esc_html__( 'Validate Connection', 'elementor-freshsales' )
							),
						),
					),
				),
			)
		);
	}

	/**
	 * Enqueue the tiny admin script that powers the Validate button.
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public function enqueue_admin_assets( $hook ) {
		if ( false === strpos( (string) $hook, 'elementor' ) ) {
			return;
		}

		wp_enqueue_script(
			'cornerstone-freshsales-admin',
			PLUGIN_URL . 'assets/js/admin.js',
			array(),
			VERSION,
			true
		);
	}

	/**
	 * AJAX handler for the Validate button. Tests the entered API key + domain.
	 */
	public function ajax_validate() {
		if ( ! check_ajax_referer( self::VALIDATE_ACTION, '_nonce', false ) ) {
			wp_send_json_error( esc_html__( 'Security check failed.', 'elementor-freshsales' ), 403 );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( esc_html__( 'Permission denied.', 'elementor-freshsales' ), 403 );
		}

		$api_key = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';
		$domain  = isset( $_POST['domain'] ) ? sanitize_text_field( wp_unslash( $_POST['domain'] ) ) : '';

		$saved_api_key = (string) $this->get_global_api_key();
		$saved_domain  = (string) $this->get_global_domain();

		// Fall back to saved values so the button also works after saving.
		if ( '' === $api_key ) {
			$api_key = $saved_api_key;
		}
		if ( '' === $domain ) {
			$domain = $saved_domain;
		}

		try {
			$handler = new Freshsales_Handler( $api_key, $domain );
			$handler->validate();
		} catch ( \Exception $e ) {
			wp_send_json_error( esc_html__( 'Could not connect to Freshsales. Check the domain and API key.', 'elementor-freshsales' ) );
		}

		// The button tests what is typed in the fields, which is not necessarily what is
		// stored. Say so plainly — a green "connected" on credentials that were never saved
		// is what makes forms fail later with "missing an API key".
		$is_saved = ( trim( $api_key ) === trim( $saved_api_key ) && trim( $domain ) === trim( $saved_domain ) );

		wp_send_json_success(
			array(
				'saved'   => $is_saved,
				'message' => $is_saved
					? esc_html__( 'Connected to Freshsales successfully.', 'elementor-freshsales' )
					: esc_html__( 'These credentials work — now click Save Changes to store them.', 'elementor-freshsales' ),
			)
		);
	}
}
