<?php
/**
 * Active users tracking, admin display, and geolocation helpers.
 *
 * @package Online_Active_Users
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tracks online users and renders their status across wp-admin.
 */
class Wpoau_Active_Users {

	/**
	 * Main plugin class instance.
	 *
	 * @var Webi_Active_User
	 */
	protected $main_class;

	/**
	 * Constructor.
	 *
	 * @param Webi_Active_User $main_class Main plugin class instance.
	 */
	public function __construct( $main_class ) {
		$this->main_class = $main_class;
	}

	/**
	 * Record the current user's online status, IP, country, and timezone.
	 */
	public function wpoau_users_status_init() {
		$logged_in_users = get_transient( 'users_status' );

		if ( ! is_array( $logged_in_users ) ) {
			$logged_in_users = array();
		}

		$user = wp_get_current_user();
		if ( $user->ID ) {
			$ip       = $this->wpoau_get_user_ip();
			$country  = $this->wpoau_get_user_country( $ip );
			$timezone = $this->wpoau_get_user_timezone( $ip );

			$logged_in_users[ $user->ID ] = array(
				'id'       => $user->ID,
				'username' => $user->user_login,
				'last'     => time(),
				'ip'       => $ip,
				'country'  => $country,
				'timezone' => $timezone,
			);

			// Store without expiration, keep refreshing via heartbeat.
			set_transient( 'users_status', $logged_in_users );
			update_user_meta( $user->ID, 'last_seen', time() );
		}
	}

	/**
	 * Check if a user has been online in the last 50 seconds.
	 *
	 * @param int $id User ID.
	 * @return bool
	 */
	public function wpoau_is_user_online( $id ) {
		$logged_in_users = get_transient( 'users_status' );

		// User is online if found in transient and last activity is within 50 seconds.
		if ( isset( $logged_in_users[ $id ] ) && $logged_in_users[ $id ]['last'] > time() - 50 ) {
			return true;
		}

		// Fallback: Check user meta if transient fails.
		$last_seen = get_user_meta( $id, 'last_seen', true );
		return ( $last_seen && $last_seen > time() - 50 );
	}

	/**
	 * Render the "status" column content on the Users list table.
	 *
	 * @param string $value       Existing column value.
	 * @param string $column_name Column being rendered.
	 * @param int    $id          User ID.
	 * @return string
	 */
	public function wpoau_user_columns_content( $value, $column_name, $id ) {
		if ( 'status' === $column_name ) {
			if ( $this->wpoau_is_user_online( $id ) ) {
				return '<span class="online-logged-in">●</span> <br /><small><em>Online Now</em></small>';
			} else {
				$last_seen = get_user_meta( $id, 'last_seen', true );

				if ( ! $last_seen ) {
					$last_seen_text = '<small><em>Never Logged In</em></small>';
					return '<span class="never-dot">●</span> <br />' . $last_seen_text;
				} else {
					// wp_date() (not date()) so the display respects the site's configured timezone rather than the server's.
					$last_seen_text = "<small>Last Seen: <br /><em class='webizito-last-seen' data-timestamp='{$last_seen}'>" . esc_html( wp_date( 'M j, Y @ g:ia', $last_seen ) ) . '</em></small>';
					return '<span class="offline-dot">●</span> <br />' . $last_seen_text;
				}
			}
		}

		return $value;
	}

	/**
	 * Update the current user's last-seen timestamp on every request.
	 */
	public function webi_track_user_activity() {
		if ( is_user_logged_in() ) {
			$user_id = get_current_user_id();
			update_user_meta( $user_id, 'last_seen', time() );
		}
	}

	/**
	 * Add the "status" column to the Users list table.
	 *
	 * @param array $defaults Existing columns.
	 * @return array
	 */
	public function wpoau_user_columns_head( $defaults ) {
		$defaults['status'] = 'User Online Status';
		return $defaults;
	}

	/**
	 * Register the [webi_active_user] shortcode.
	 */
	public function wpoau_active_user_shortcode() {
		add_shortcode( 'webi_active_user', array( $this, 'wpoau_active_user' ) );
	}

	/**
	 * Render the [webi_active_user] shortcode output.
	 *
	 * @return string
	 */
	public function wpoau_active_user() {
		ob_start();
		if ( is_user_logged_in() ) {
			echo '<div class="webi-active-users"> ' . esc_html__( 'Currently Active Users:', 'online-active-users' ) . ' <small>(' . absint( $this->main_class->wpoau_online_users( 'count' ) ) . ')</small></div>';
		}
		return ob_get_clean();
	}

	/**
	 * Display the active users count in the admin bar.
	 */
	public function wpoau_admin_bar_link() {
		global $wp_admin_bar;
		if ( ! is_super_admin() || ! is_admin_bar_showing() ) {
			return;
		}
		$wp_admin_bar->add_menu(
			array(
				'id'    => 'webi_user_link',
				'title' => '<span class="ab-icon online-logged-in">●</span><span class="ab-label">' . sprintf(
				/* translators: %d: number of currently active users. */
					esc_html__( 'Active Users (%d)', 'online-active-users' ),
					absint( $this->main_class->wpoau_online_users( 'count' ) )
				) . '</span>',
				'href'  => esc_url( admin_url( 'users.php?page=wpoau-active-users' ) ),
			)
		);
	}

	/**
	 * Register the "Active Users" dashboard widget.
	 */
	public function wpoau_active_users_metabox() {
		wp_add_dashboard_widget( 'webizito_active_users', 'Active Users', array( $this, 'wpoau_active_user_dashboard' ) );
	}

	/**
	 * Render the "Active Users" dashboard widget content.
	 *
	 * @param mixed $post          Unused. Required by the wp_add_dashboard_widget() callback signature.
	 * @param mixed $callback_args Unused. Required by the wp_add_dashboard_widget() callback signature.
	 */
	public function wpoau_active_user_dashboard( $post, $callback_args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$user_count   = count_users();
		$users_plural = ( 1 === $user_count['total_users'] ) ? __( 'User', 'online-active-users' ) : __( 'Users', 'online-active-users' );

		$active_users = $this->main_class->wpoau_online_users( 'count' );

		echo '<div><a href="' . esc_url( admin_url( 'users.php?page=wpoau-active-users' ) ) . '">' . absint( $user_count['total_users'] ) . ' ' . esc_html( $users_plural ) . '</a> <small>(' . absint( $active_users ) . ' ' . esc_html__( 'currently active', 'online-active-users' ) . ')</small>
              <br />
              <strong><a href="' . esc_url( 'https://wordpress.org/support/plugin/online-active-users/reviews/?rate=5#new-post' ) . '" target="_blank">' . esc_html__( 'Rate our plugin', 'online-active-users' ) . ' &nbsp;<span style="color:#ffb900;font-size: 18px;position:relative;top:0.1em;">★★★★★</span></a></strong></div>';
	}

	/**
	 * Track a transient key in options so it can be cleaned up on uninstall.
	 *
	 * @param string $key Transient key.
	 */
	public function wpoau_track_transient_key( $key ) {
		$stored_keys = get_option( 'wpoau_transient_keys', array() );
		if ( ! in_array( $key, $stored_keys, true ) ) {
			$stored_keys[] = $key;
			update_option( 'wpoau_transient_keys', $stored_keys );
		}
	}

	/**
	 * Determine the current user's IP address.
	 *
	 * @return string
	 */
	public function wpoau_get_user_ip() {
		$ip_keys = array( 'HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_X_CLUSTER_CLIENT_IP', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR' );

		foreach ( $ip_keys as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				// Note: all of these headers except REMOTE_ADDR are client-supplied and can be spoofed;
				// this IP is used for display only and must not be relied on for access control.
				$ip_list = explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) ) );
				$ip      = trim( $ip_list[0] );

				// If IP is ::1 (localhost IPv6), convert to 127.0.0.1 or fetch public IP for dev.
				if ( '::1' === $ip || '127.0.0.1' === $ip ) {
					$response = wp_remote_get( 'https://api.ipify.org?format=json' );
					if ( ! is_wp_error( $response ) ) {
						$data = json_decode( wp_remote_retrieve_body( $response ), true );
						if ( isset( $data['ip'] ) ) {
							return $data['ip'];
						}
					}
				}
				return $ip;
			}
		}

		return 'Unknown';
	}

	/**
	 * Resolve a country name from an IP address, with 24-hour caching.
	 *
	 * @param string $ip IP address.
	 * @return string
	 */
	public function wpoau_get_user_country( $ip ) {
		if ( 'Unknown' === $ip || empty( $ip ) ) {
			return 'Unknown';
		}

		$transient_key = 'wpoau_country_name_' . md5( $ip );
		$cached        = get_transient( $transient_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$response = wp_remote_get( "https://ipwho.is/{$ip}" );

		if ( is_wp_error( $response ) ) {
			return 'Unknown';
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $data ) || ! $data['success'] ) {
			return 'Unknown';
		}

		$country = $data['country'] ?? 'Unknown';
		set_transient( $transient_key, $country, 24 * HOUR_IN_SECONDS );
		$this->wpoau_track_transient_key( $transient_key );

		return $country;
	}

	/**
	 * Resolve a timezone from an IP address, with 24-hour caching.
	 *
	 * @param string $ip IP address.
	 * @return string
	 */
	public function wpoau_get_user_timezone( $ip ) {
		if ( 'Unknown' === $ip ) {
			return 'Unknown';
		}

		$transient_key = 'wpoau_timezone_' . md5( $ip );
		$cached        = get_transient( $transient_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$response = wp_remote_get( "http://ip-api.com/json/{$ip}?fields=timezone" );

		if ( is_wp_error( $response ) ) {
			return 'Unknown';
		}

		$data     = json_decode( wp_remote_retrieve_body( $response ), true );
		$timezone = isset( $data['timezone'] ) ? $data['timezone'] : 'Unknown';

		// Cache for 24 hours.
		set_transient( $transient_key, $timezone, 24 * HOUR_IN_SECONDS );
		$this->wpoau_track_transient_key( $transient_key );

		return $timezone;
	}

	/**
	 * Resolve a lowercase country code from an IP address, with 24-hour caching.
	 *
	 * @param string $ip IP address.
	 * @return string
	 */
	public function wpoau_get_user_country_code( $ip ) {
		if ( 'Unknown' === $ip ) {
			return 'xx';
		}

		$transient_key = 'wpoau_country_' . md5( $ip ); // Prevent long key issues.

		// Try getting from transient.
		$cached_code = get_transient( $transient_key );
		if ( false !== $cached_code ) {
			return $cached_code;
		}

		// Fetch from API.
		$response = wp_remote_get( "http://ip-api.com/json/{$ip}?fields=countryCode" );

		if ( is_wp_error( $response ) ) {
			return 'xx';
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		$code = isset( $data['countryCode'] ) ? strtolower( $data['countryCode'] ) : 'xx';

		// Cache it for 24 hours.
		set_transient( $transient_key, $code, 24 * HOUR_IN_SECONDS );
		$this->wpoau_track_transient_key( $transient_key );

		return $code;
	}
}
