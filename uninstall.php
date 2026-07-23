<?php
/**
 * Uninstall handler: removes the plugin's transients and options.
 *
 * @package Online_Active_Users
 */

// If uninstall is not called from WordPress, exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit();
}

// Delete plugin transient.
delete_transient( 'users_status' );

// Delete all custom transients.
$stored_keys = get_option( 'wpoau_transient_keys', array() );
if ( ! empty( $stored_keys ) ) {
	foreach ( $stored_keys as $key ) {
		delete_transient( $key );
	}
	delete_option( 'wpoau_transient_keys' );
}

// Optional: Clear cache.
wp_cache_flush();
