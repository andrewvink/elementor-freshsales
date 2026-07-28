<?php
/**
 * Uninstall routine — removes every trace of the plugin. No junk left behind.
 *
 * Runs only when the plugin is deleted from the WordPress admin.
 *
 * @package Cornerstone\Elementor_Freshsales
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// The handler is Pro-independent and self-guarded; it holds the option-key and
// transient-prefix constants so those strings are defined in exactly one place.
require_once __DIR__ . '/includes/class-freshsales-handler.php';

use Cornerstone\Elementor_Freshsales\Freshsales_Handler;

/**
 * Delete the plugin's options and cached transients for the current site.
 */
function cornerstone_freshsales_cleanup() {
	global $wpdb;

	delete_option( 'elementor_' . Freshsales_Handler::OPTION_API_KEY );
	delete_option( 'elementor_' . Freshsales_Handler::OPTION_DOMAIN );

	// Remove cached lead-source lookups (DB-backed transients created by the handler).
	// Note: under a persistent object cache these live in the cache, not wp_options, and
	// there they self-expire (12h TTL) — nothing persistent is left behind either way.
	$prefix       = Freshsales_Handler::SOURCE_TRANSIENT_PREFIX;
	$like         = $wpdb->esc_like( '_transient_' . $prefix ) . '%';
	$like_timeout = $wpdb->esc_like( '_transient_timeout_' . $prefix ) . '%';

	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$like,
			$like_timeout
		)
	);
}

if ( is_multisite() ) {
	$site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $site_ids as $site_id ) {
		switch_to_blog( $site_id );
		cornerstone_freshsales_cleanup();
		restore_current_blog();
	}
} else {
	cornerstone_freshsales_cleanup();
}
