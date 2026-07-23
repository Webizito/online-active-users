<?php
/**
 * Widget: displays the current online active users count.
 *
 * @package Online_Active_Users
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Webi_Custom_Widget' ) ) {

	/**
	 * Front-end widget showing the live online active users count.
	 */
	class Webi_Custom_Widget extends WP_Widget {

		/**
		 * Register the widget with WordPress.
		 */
		public function __construct() {
			parent::__construct(
				'webi_custom_widget',
				__( 'WP Online Active User', 'online-active-users' ),
				array( 'description' => __( 'Display Online Active Users.', 'online-active-users' ) )
			);
		}

		/**
		 * Front-end display.
		 *
		 * @param array $args     Widget display arguments.
		 * @param array $instance Saved widget settings.
		 */
		public function widget( $args, $instance ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- before_widget/after_widget are trusted markup from the theme's registered sidebar.
			echo $args['before_widget'];

			// Title.
			if ( ! empty( $instance['title'] ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- before_title/after_title are trusted theme markup; the title itself is escaped via wp_kses_post().
				echo $args['before_title'] . wp_kses_post( apply_filters( 'widget_title', $instance['title'] ) ) . $args['after_title'];
			}

			// Widget content.
			if ( class_exists( 'Webi_Active_User' ) ) {
				$webi_plugin        = new Webi_Active_User();
				$active_users_count = $webi_plugin->wpoau_online_users( 'count' );
				echo '<div class="webi-widget-content">';
				echo '<p>' . esc_html__( 'Online Active Users:', 'online-active-users' ) . ' <strong>' . absint( $active_users_count ) . '</strong></p>';
				echo '</div>';
			} else {
				echo '<p>' . esc_html__( 'WP Active User plugin not found.', 'online-active-users' ) . '</p>';
			}

			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- before_widget/after_widget are trusted markup from the theme's registered sidebar.
			echo $args['after_widget'];
		}

		/**
		 * Back-end widget settings form.
		 *
		 * @param array $instance Saved widget settings.
		 */
		public function form( $instance ) {
			$title = ! empty( $instance['title'] ) ? $instance['title'] : __( 'Active Users', 'online-active-users' );
			?>
			<p>
				<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php esc_attr_e( 'Title:', 'online-active-users' ); ?></label>
				<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" type="text" value="<?php echo esc_attr( $title ); ?>">
			</p>
			<?php
		}

		/**
		 * Sanitize and save widget settings.
		 *
		 * @param array $new_instance New settings.
		 * @param array $old_instance Previous settings. Unused, but required by the WP_Widget::update() signature.
		 * @return array
		 */
		public function update( $new_instance, $old_instance ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
			$instance          = array();
			$instance['title'] = ( ! empty( $new_instance['title'] ) ) ? wp_strip_all_tags( $new_instance['title'] ) : '';
			return $instance;
		}
	}
}
