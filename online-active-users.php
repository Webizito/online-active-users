<?php
/**
 * Plugin Name: Online Active Users
 * Plugin URI: https://wordpress.org/plugins/online-active-users/
 * Description: Monitor and display real-time online users and last seen status on your WordPress site with WP Online Active Users plugin.
 * Tags: online users, active users, online active users, real-time users, user activity
 * Version: 3.4
 * Author: Webizito
 * Author URI: http://webizito.com/
 * License:  GPLv3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: online-active-users
 * Donate link: https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=APRNBJUZHRP7G
 *
 * @package Online_Active_Users
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WPOAU_PLUGIN_DIR' ) ) {
	define( 'WPOAU_PLUGIN_DIR', __DIR__ );
}

if ( ! defined( 'WPOAU_PLUGIN_FILE' ) ) {
	define( 'WPOAU_PLUGIN_FILE', __FILE__ );
}

// Plugin version, used to bust the browser cache for enqueued assets on release.
if ( ! defined( 'WPOAU_VERSION' ) ) {
	define( 'WPOAU_VERSION', '3.4' );
}

require_once WPOAU_PLUGIN_DIR . '/inc/class-wpoau-active-users.php';

if ( ! class_exists( 'Webi_Active_User' ) ) {

	/**
	 * Core plugin class: registers all hooks and shortcodes.
	 */
	class Webi_Active_User {

		/**
		 * Active users handler instance.
		 *
		 * @var Wpoau_Active_Users
		 */
		public $wpoau;

		/**
		 * Register all plugin hooks.
		 */
		public function __construct() {

			$this->wpoau            = new Wpoau_Active_Users( $this );
			$GLOBALS['wpoau_users'] = $this->wpoau;

			register_activation_hook( __FILE__, array( $this->wpoau, 'wpoau_users_status_init' ) );
			add_action( 'init', array( $this->wpoau, 'wpoau_users_status_init' ) );
			add_action( 'init', array( $this->wpoau, 'webi_track_user_activity' ) );
			add_action( 'clear_auth_cookie', array( $this, 'wpoau_user_logout' ) );
			add_action( 'wp_loaded', array( $this, 'wpoau_enqueue_script' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'webi_enqueue_custom_scripts' ) );
			add_action( 'admin_init', array( $this->wpoau, 'wpoau_users_status_init' ) );
			add_action( 'wp_dashboard_setup', array( $this->wpoau, 'wpoau_active_users_metabox' ) );
			add_filter( 'manage_users_columns', array( $this->wpoau, 'wpoau_user_columns_head' ) );
			add_action( 'manage_users_custom_column', array( $this->wpoau, 'wpoau_user_columns_content' ), 10, 10 );
			add_filter( 'views_users', array( $this, 'wpoau_modify_user_view' ) );
			add_action( 'admin_bar_menu', array( $this->wpoau, 'wpoau_admin_bar_link' ), 999 );
			add_filter( 'plugin_row_meta', array( $this, 'wpoau_support_and_faq_links' ), 10, 2 );
			add_action( 'admin_menu', array( $this, 'wpoau_add_admin_submenu' ) );
			add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'wpoau_plugin_by_link' ), 10, 2 );
			add_action( 'admin_notices', array( $this, 'wpoau_display_notice' ) );
			register_deactivation_hook( __FILE__, array( $this, 'wpoau_display_notice' ) );
			register_deactivation_hook( __FILE__, array( $this, 'wpoau_delete_transient' ) );

			$this->wpoau->wpoau_active_user_shortcode();
		}

		/**
		 * Enqueue the plugin's front-end stylesheet.
		 */
		public function wpoau_enqueue_script() {
			wp_enqueue_style( 'style-css', plugin_dir_url( __FILE__ ) . 'assets/css/style.css', array(), WPOAU_VERSION, 'all' );
		}

		/**
		 * Enqueue the admin script that formats "last seen" timestamps.
		 */
		public function webi_enqueue_custom_scripts() {
			// Use the plugin version (not rand()) so the browser can actually cache the script between page loads.
			wp_enqueue_script( 'webi-plugin-script', plugin_dir_url( __FILE__ ) . 'assets/js/custom.js', array( 'jquery' ), WPOAU_VERSION, true );

			wp_add_inline_script(
				'webi-plugin-script',
				"
                jQuery(document).ready(function($) {
                    $('.webizito-last-seen').each(function() {
                        var timestamp = $(this).data('timestamp');
                        if (timestamp) {
                            var date = new Date(timestamp * 1000);
                            $(this).text(date.toLocaleString());
                        }
                    });
                });
            "
			);
		}

		/**
		 * Remove user from the online list when they log out.
		 */
		public function wpoau_user_logout() {
			if ( ! is_user_logged_in() ) {
				return; // Prevent errors if already logged out.
			}

			$user_id = get_current_user_id();
			if ( ! $user_id ) {
				return;
			}

			$logged_in_users = get_transient( 'users_status' );

			if ( isset( $logged_in_users[ $user_id ] ) ) {
				unset( $logged_in_users[ $user_id ] );
				set_transient( 'users_status', $logged_in_users, 60 );
			}

			update_user_meta( $user_id, 'last_seen', time() );
		}

		/**
		 * Get a count of online users, or an array of online user records.
		 *
		 * @param string $return_type Either 'count' or 'array'.
		 * @return int|array|false
		 */
		public function wpoau_online_users( $return_type = 'count' ) {
			$logged_in_users = get_transient( 'users_status' );

			// If no users are online.
			if ( empty( $logged_in_users ) ) {
				return ( 'count' === $return_type ) ? 0 : false;
			}

			$user_online_count = 0;
			$online_users      = array();
			foreach ( $logged_in_users as $user ) {
				if ( ! empty( $user['username'] ) && isset( $user['last'] ) && $user['last'] > time() - 50 ) {
					$online_users[] = $user;
					++$user_online_count;
				}
			}

			return ( 'count' === $return_type ) ? $user_online_count : $online_users;
		}

		/**
		 * Register the "Online Active Users" submenu under Users.
		 */
		public function wpoau_add_admin_submenu() {
			add_users_page(
				'Online Active Users',
				'Online Active Users',
				'list_users',
				'wpoau-active-users',
				array( $this, 'wpoau_active_users_page' )
			);
		}

		/**
		 * Render the "Online Active Users" admin page.
		 */
		public function wpoau_active_users_page() {
			echo '<div class="wrap"><h1>' . esc_html__( 'Online Users Lists', 'online-active-users' ) . '</h1>';

			$users = $this->wpoau_online_users( 'array' );

			if ( empty( $users ) ) {
				echo '<p>' . esc_html__( 'No users are online right now.', 'online-active-users' ) . '</p></div>';
				return;
			}

			require_once WPOAU_PLUGIN_DIR . '/inc/user-list-table.php';
		}

		/**
		 * Add a "User Online" view link to the Users list table views.
		 *
		 * @param array $views Existing views.
		 * @return array
		 */
		public function wpoau_modify_user_view( $views ) {

			$logged_in_users = get_transient( 'users_status' );
			$user            = wp_get_current_user();

			$logged_in_users[ $user->ID ] = array(
				'id'       => $user->ID,
				'username' => $user->user_login,
				'last'     => time(),
			);

			$view = '<a href="' . esc_url( admin_url( 'users.php?page=wpoau-active-users' ) ) . '">' . esc_html__( 'User Online', 'online-active-users' ) . ' <span class="count">(' . absint( $this->wpoau_online_users( 'count' ) ) . ')</span></a>';

			$views['status'] = $view;
			return $views;
		}

		/**
		 * Add Support/Docs/Rate links to the plugin's row meta.
		 *
		 * @param array  $links_array     Existing row meta links.
		 * @param string $plugin_file_name File path of the plugin being processed.
		 * @return array
		 */
		public function wpoau_support_and_faq_links( $links_array, $plugin_file_name ) {
			if ( strpos( $plugin_file_name, basename( __FILE__ ) ) ) {

				// You can still use `array_unshift()` to add links at the beginning.
				$links_array[] = '<a href="' . esc_url( 'https://wordpress.org/support/plugin/online-active-users/' ) . '" target="_blank">' . esc_html__( 'Support', 'online-active-users' ) . '</a>';
				$links_array[] = '<a href="' . esc_url( 'https://webizito.com/wp-online-active-users/' ) . '" target="_blank">' . esc_html__( 'Docs', 'online-active-users' ) . '</a>';
				$links_array[] = '<strong><a href="' . esc_url( 'https://wordpress.org/support/plugin/online-active-users/reviews/?rate=5#new-post' ) . '" target="_blank">' . esc_html__( 'Rate our plugin', 'online-active-users' ) . '  <span style="color:#ffb900;font-size: 18px;position:relative;top:0.1em;">★★★★★</span></a></strong>';
			}
			return $links_array;
		}

		/**
		 * Add Donate/By-author links to the plugin action links.
		 *
		 * @param array $links Existing plugin action links.
		 * @return array
		 */
		public function wpoau_plugin_by_link( $links ) {
			$url     = 'https://webizito.com/';
			$links[] = '<a href="' . esc_url( 'https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=APRNBJUZHRP7G' ) . '" target="_blank"><span style="font-weight: bold;">' . esc_html__( 'Donate', 'online-active-users' ) . '</span></a>';
			$_link   = '<a href="' . esc_url( $url ) . '" target="_blank">' . esc_html__( 'By', 'online-active-users' ) . ' <span>Webizito</span></a>';
			$links[] = $_link;
			return $links;
		}

		/**
		 * Display the "please review/donate" admin notice.
		 */
		public function wpoau_display_notice() {
			echo '<div class="notice notice-success is-dismissible wp-online-active-users-notice" id="wp-online-active-users-notice">';
			echo '<p>';
			printf(
				/* translators: 1: opening review link tag, 2: closing link tag, 3: opening donation link tag. */
				esc_html__( 'Enjoying our WP Online Active Users plugin? Please consider leaving us a review %1$shere%2$s. Or support with a small donation %3$shere%2$s. We would greatly appreciate it!', 'online-active-users' ),
				'<a href="' . esc_url( 'https://wordpress.org/support/plugin/online-active-users/reviews/?rate=5#new-post' ) . '" target="_blank">',
				'</a>',
				'<a href="' . esc_url( 'https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=APRNBJUZHRP7G' ) . '" target="_blank">'
			);
			echo '</p>';
			echo '</div>';
		}

		/**
		 * Delete the plugin's transient on deactivation.
		 */
		public function wpoau_delete_transient() {
			delete_transient( 'users_status' );
		}
	}
}

$wpoau_plugin = new Webi_Active_User();

require_once WPOAU_PLUGIN_DIR . '/class-webi-custom-widget.php';

// Register the custom widget.
add_action(
	'widgets_init',
	function () {
		register_widget( 'Webi_Custom_Widget' );
	}
);
