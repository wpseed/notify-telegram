<?php
/**
 * Plugin settings page (Settings → Notify Telegram).
 *
 * @package NotifyTelegram
 */

declare(strict_types=1);

namespace Wpseed\NotifyTelegram\Admin;

use Wpseed\NotifyTelegram\Greeting;
use Wpseed\NotifyTelegram\Plugin;

/**
 * Settings page built on the Settings API.
 */
final class SettingsPage {

	/**
	 * Page slug.
	 */
	public const MENU_SLUG = 'notify-telegram';

	/**
	 * Settings group.
	 */
	public const OPTION_GROUP = 'notify_telegram_settings';

	/**
	 * Registers the admin hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_setting' ) );
	}

	/**
	 * Adds the page to the Settings menu.
	 *
	 * @return void
	 */
	public function add_menu(): void {
		add_options_page(
			__( 'Notify Telegram', 'notify-telegram' ),
			__( 'Notify Telegram', 'notify-telegram' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Registers the option through the Settings API.
	 *
	 * @return void
	 */
	public function register_setting(): void {
		register_setting(
			self::OPTION_GROUP,
			Plugin::OPTION_TEMPLATE,
			array(
				'type'              => 'string',
				/* translators: %s: placeholder for the name inside the greeting template. */
				'description'       => __( 'Greeting template, %s is replaced with the name', 'notify-telegram' ),
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => Greeting::DEFAULT_TEMPLATE,
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Sanitizes the submitted value: a blank string falls back to the default template.
	 *
	 * @param mixed $value Value coming from the form.
	 * @return string
	 */
	public function sanitize( mixed $value ): string {
		$value = is_string( $value ) ? trim( $value ) : '';

		return '' === $value ? Greeting::DEFAULT_TEMPLATE : $value;
	}

	/**
	 * Renders the settings page.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$option = Plugin::OPTION_TEMPLATE;
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Notify Telegram', 'notify-telegram' ); ?></h1>
			<form action="options.php" method="post">
				<?php settings_fields( self::OPTION_GROUP ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="<?php echo esc_attr( $option ); ?>">
								<?php echo esc_html__( 'Greeting template', 'notify-telegram' ); ?>
							</label>
						</th>
						<td>
							<input
								type="text"
								class="regular-text"
								id="<?php echo esc_attr( $option ); ?>"
								name="<?php echo esc_attr( $option ); ?>"
								value="<?php echo esc_attr( (string) get_option( $option, Greeting::DEFAULT_TEMPLATE ) ); ?>"
							/>
							<p class="description">
								<?php
								/* translators: %s: placeholder for the name inside the greeting template. */
								echo esc_html__( 'Use %s as the placeholder for the name.', 'notify-telegram' );
								?>
							</p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
			<p>
				<?php echo esc_html__( 'To check the output, insert the [notify_telegram_hello name="John"] shortcode into any post.', 'notify-telegram' ); ?>
			</p>
		</div>
		<?php
	}
}
