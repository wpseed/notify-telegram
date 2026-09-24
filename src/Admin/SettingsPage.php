<?php
/**
 * Plugin settings screen (Settings → Notify Telegram).
 *
 * @package NotifyTelegram
 */

declare(strict_types=1);

namespace Wpseed\NotifyTelegram\Admin;

use Wpseed\NotifyTelegram\Channel\Channel;
use Wpseed\NotifyTelegram\Channel\ChannelRegistry;
use Wpseed\NotifyTelegram\Delivery\Log;
use Wpseed\NotifyTelegram\Event\Event;
use Wpseed\NotifyTelegram\Event\EventRegistry;
use Wpseed\NotifyTelegram\Message;
use Wpseed\NotifyTelegram\Settings\Settings;

/**
 * The only screen the plugin needs: what is switched on, how each channel is configured, what each
 * event says, a test button and the last deliveries.
 *
 * The form is built from the objects rather than hard-coded: a channel contributes its own fields
 * and an event contributes its own placeholders, so a new channel or a new event shows up here
 * without touching this class.
 */
final class SettingsPage {

	/**
	 * Page slug.
	 */
	public const MENU_SLUG = 'notify-telegram';

	/**
	 * Settings group used by the Settings API.
	 */
	public const OPTION_GROUP = 'notify_telegram_settings';

	/**
	 * Admin-post action of the test button.
	 */
	public const TEST_ACTION = 'notify_telegram_test';

	/**
	 * Admin-post action of the "clear log" button.
	 */
	public const CLEAR_LOG_ACTION = 'notify_telegram_clear_log';

	/**
	 * Prefix of the transient holding the result of the last test.
	 */
	public const NOTICE_TRANSIENT = 'notify_telegram_test_result_';

	/**
	 * Capability required to see and change everything here.
	 */
	public const CAPABILITY = 'manage_options';

	/**
	 * Constructor.
	 *
	 * @param Settings        $settings Settings.
	 * @param ChannelRegistry $channels Channels.
	 * @param EventRegistry   $events   Events.
	 * @param Log             $log      Delivery log.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly ChannelRegistry $channels,
		private readonly EventRegistry $events,
		private readonly Log $log,
	) {
	}

	/**
	 * Registers the admin hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_setting' ) );
		add_action( 'admin_post_' . self::TEST_ACTION, array( $this, 'handle_test' ) );
		add_action( 'admin_post_' . self::CLEAR_LOG_ACTION, array( $this, 'handle_clear_log' ) );
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
			self::CAPABILITY,
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
			Settings::OPTION,
			array(
				'type'              => 'array',
				'description'       => __( 'Notify Telegram settings: channels, events and message templates.', 'notify-telegram' ),
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => Settings::defaults(),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Cleans submitted settings and rejects a template that could not be rendered.
	 *
	 * A rejected template keeps the value that was stored before instead of silently falling back
	 * to the default: the site owner sees the error and the message that already worked keeps
	 * working.
	 *
	 * @param mixed $value Submitted value.
	 * @return array<string, mixed>
	 */
	public function sanitize( mixed $value ): array {
		$clean = Settings::sanitize( is_array( $value ) ? wp_unslash( $value ) : array() );

		foreach ( $clean['templates'] as $event_id => $template ) {
			$event = $this->events->get( (string) $event_id );

			if ( null === $event || '' === (string) $template ) {
				continue;
			}

			$error = Message::validate_template( (string) $template, $event->placeholder_names() );

			if ( null === $error ) {
				continue;
			}

			add_settings_error(
				Settings::OPTION,
				'notify_telegram_template_' . $event_id,
				sprintf(
					/* translators: 1: event title, 2: validation error. */
					__( '%1$s: %2$s', 'notify-telegram' ),
					$event->label(),
					$error
				)
			);

			$clean['templates'][ $event_id ] = $this->settings->template( (string) $event_id );
		}

		return $clean;
	}

	/**
	 * Sends one test message through every configured channel.
	 *
	 * The toggles are ignored on purpose: this button exists to check credentials.
	 *
	 * @return array<int, array{channel: string, ok: bool, message: string}>
	 */
	public function run_test(): array {
		$message = Message::plain(
			'test',
			sprintf(
				/* translators: %s: site name. */
				__( 'Test message from %s.', 'notify-telegram' ),
				wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES )
			),
			__( 'Notify Telegram test', 'notify-telegram' )
		);

		$results = array();

		foreach ( $this->channels->all() as $channel ) {
			if ( ! $channel->is_configured() ) {
				continue;
			}

			$result    = $channel->send( $message );
			$summary   = $result->is_ok() ? __( 'Delivered.', 'notify-telegram' ) : $result->error();
			$results[] = array(
				'channel' => $channel->label(),
				'ok'      => $result->is_ok(),
				'message' => $summary,
			);

			$this->log->add( 'test', $channel->id(), $result->is_ok(), $summary );
		}

		return $results;
	}

	/**
	 * Handles the test button.
	 *
	 * @return void
	 */
	public function handle_test(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to send test messages.', 'notify-telegram' ) );
		}

		check_admin_referer( self::TEST_ACTION );

		set_transient( self::notice_key(), $this->run_test(), MINUTE_IN_SECONDS );

		wp_safe_redirect( $this->page_url() );
		exit;
	}

	/**
	 * Handles the "clear log" button.
	 *
	 * @return void
	 */
	public function handle_clear_log(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to clear the log.', 'notify-telegram' ) );
		}

		check_admin_referer( self::CLEAR_LOG_ACTION );

		$this->log->clear();

		wp_safe_redirect( $this->page_url() );
		exit;
	}

	/**
	 * Renders the page.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Notify Telegram', 'notify-telegram' ); ?></h1>
			<?php
			settings_errors();
			$this->render_test_notice();
			?>
			<form action="options.php" method="post">
				<?php
				settings_fields( self::OPTION_GROUP );
				$this->render_status();
				$this->render_channels();
				$this->render_events();
				submit_button();
				?>
			</form>
			<hr />
			<?php
			$this->render_test_button();
			$this->render_log();
			?>
		</div>
		<?php
	}

	/**
	 * Renders the master switch.
	 *
	 * @return void
	 */
	private function render_status(): void {
		?>
		<h2><?php echo esc_html__( 'Status', 'notify-telegram' ); ?></h2>
		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Notifications', 'notify-telegram' ); ?></th>
					<td>
						<label>
							<input
								type="checkbox"
								name="<?php echo esc_attr( $this->field_name( 'enabled' ) ); ?>"
								value="1"
								<?php checked( $this->settings->enabled() ); ?>
							/>
							<?php echo esc_html__( 'Send a message when one of the events below happens.', 'notify-telegram' ); ?>
						</label>
						<p class="description">
							<?php echo esc_html__( 'Messages are delivered by WP-Cron, so the site needs traffic for the queue to move.', 'notify-telegram' ); ?>
						</p>
					</td>
				</tr>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Renders every channel with its own fields.
	 *
	 * @return void
	 */
	private function render_channels(): void {
		?>
		<h2><?php echo esc_html__( 'Channels', 'notify-telegram' ); ?></h2>
		<p class="description">
			<?php echo esc_html__( 'A channel with an empty field is skipped, not reported as an error.', 'notify-telegram' ); ?>
		</p>
		<?php
		foreach ( $this->channels->all() as $channel ) {
			$this->render_channel( $channel );
		}
	}

	/**
	 * Renders one channel.
	 *
	 * @param Channel $channel Channel.
	 * @return void
	 */
	private function render_channel( Channel $channel ): void {
		?>
		<h3>
			<?php echo esc_html( $channel->label() ); ?>
			<span class="description">
				<?php echo $channel->is_configured() ? esc_html__( '(configured)', 'notify-telegram' ) : esc_html__( '(not configured yet)', 'notify-telegram' ); ?>
			</span>
		</h3>
		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Enabled', 'notify-telegram' ); ?></th>
					<td>
						<label>
							<input
								type="checkbox"
								name="<?php echo esc_attr( $this->field_name( 'channels', $channel->id(), 'enabled' ) ); ?>"
								value="1"
								<?php checked( $this->settings->channel_enabled( $channel->id() ) ); ?>
							/>
							<?php echo esc_html__( 'Use this channel.', 'notify-telegram' ); ?>
						</label>
					</td>
				</tr>
				<?php
				foreach ( $channel->fields() as $field => $definition ) {
					$this->render_field( $channel, (string) $field, $definition );
				}
				?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Renders one field of a channel.
	 *
	 * @param Channel                                                                        $channel    Channel.
	 * @param string                                                                         $field      Field name.
	 * @param array{label: string, type: string, description?: string, placeholder?: string} $definition Field definition.
	 * @return void
	 */
	private function render_field( Channel $channel, string $field, array $definition ): void {
		$id    = $this->field_id( $channel->id(), $field );
		$name  = $this->field_name( 'channels', $channel->id(), $field );
		$value = $this->settings->channel_value( $channel->id(), $field );
		?>
		<tr>
			<th scope="row">
				<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $definition['label'] ); ?></label>
			</th>
			<td>
				<?php if ( 'textarea' === $definition['type'] ) : ?>
					<textarea
						class="large-text code"
						rows="3"
						id="<?php echo esc_attr( $id ); ?>"
						name="<?php echo esc_attr( $name ); ?>"
						placeholder="<?php echo esc_attr( $definition['placeholder'] ?? '' ); ?>"
					><?php echo esc_textarea( $value ); ?></textarea>
				<?php else : ?>
					<input
						type="text"
						class="regular-text code"
						id="<?php echo esc_attr( $id ); ?>"
						name="<?php echo esc_attr( $name ); ?>"
						value="<?php echo esc_attr( $value ); ?>"
						placeholder="<?php echo esc_attr( $definition['placeholder'] ?? '' ); ?>"
					/>
				<?php endif; ?>
				<?php if ( isset( $definition['description'] ) && '' !== $definition['description'] ) : ?>
					<p class="description"><?php echo esc_html( $definition['description'] ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Renders every event with its toggle and its message.
	 *
	 * @return void
	 */
	private function render_events(): void {
		?>
		<h2><?php echo esc_html__( 'Events', 'notify-telegram' ); ?></h2>
		<p class="description">
			<?php echo esc_html__( 'Leave a message empty to use the default text of the event.', 'notify-telegram' ); ?>
		</p>
		<?php
		foreach ( $this->events->all() as $event ) {
			$this->render_event( $event );
		}
	}

	/**
	 * Renders one event.
	 *
	 * @param Event $event Event.
	 * @return void
	 */
	private function render_event( Event $event ): void {
		$id = $this->field_id( $event->id(), 'template' );
		?>
		<h3><?php echo esc_html( $event->label() ); ?></h3>
		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Enabled', 'notify-telegram' ); ?></th>
					<td>
						<label>
							<input
								type="checkbox"
								name="<?php echo esc_attr( $this->field_name( 'events', $event->id() ) ); ?>"
								value="1"
								<?php checked( $this->settings->event_enabled( $event->id() ) ); ?>
							/>
							<?php echo esc_html__( 'Notify me about this event.', 'notify-telegram' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html__( 'Message', 'notify-telegram' ); ?></label>
					</th>
					<td>
						<textarea
							class="large-text code"
							rows="3"
							id="<?php echo esc_attr( $id ); ?>"
							name="<?php echo esc_attr( $this->field_name( 'templates', $event->id() ) ); ?>"
							placeholder="<?php echo esc_attr( $event->default_template() ); ?>"
						><?php echo esc_textarea( $this->settings->template( $event->id() ) ); ?></textarea>
						<p class="description">
							<?php
							printf(
								/* translators: %s: default message text. */
								esc_html__( 'Default: %s', 'notify-telegram' ),
								'<code>' . esc_html( $event->default_template() ) . '</code>'
							);
							?>
						</p>
						<p class="description"><?php echo esc_html( $this->placeholders_hint( $event ) ); ?></p>
					</td>
				</tr>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Renders the test button.
	 *
	 * @return void
	 */
	private function render_test_button(): void {
		?>
		<h2><?php echo esc_html__( 'Test', 'notify-telegram' ); ?></h2>
		<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::TEST_ACTION ); ?>" />
			<?php wp_nonce_field( self::TEST_ACTION ); ?>
			<p class="description">
				<?php echo esc_html__( 'Sends one message through every configured channel, whatever the toggles say.', 'notify-telegram' ); ?>
			</p>
			<?php submit_button( __( 'Send test message', 'notify-telegram' ), 'secondary', 'submit', false ); ?>
		</form>
		<?php
	}

	/**
	 * Renders the delivery log.
	 *
	 * @return void
	 */
	private function render_log(): void {
		$entries = $this->log->entries();
		?>
		<h2><?php echo esc_html__( 'Recent deliveries', 'notify-telegram' ); ?></h2>
		<?php if ( array() === $entries ) : ?>
			<p class="description"><?php echo esc_html__( 'Nothing has been delivered yet.', 'notify-telegram' ); ?></p>
		<?php else : ?>
			<table class="widefat striped">
				<thead>
					<tr>
						<th scope="col"><?php echo esc_html__( 'Time', 'notify-telegram' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Event', 'notify-telegram' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Channel', 'notify-telegram' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Result', 'notify-telegram' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $entries as $entry ) : ?>
						<tr>
							<td><?php echo esc_html( $this->format_time( $entry['time'] ) ); ?></td>
							<td><?php echo esc_html( $this->event_label( $entry['event'] ) ); ?></td>
							<td><?php echo esc_html( $this->channel_label( $entry['channel'] ) ); ?></td>
							<td>
								<?php echo $entry['ok'] ? esc_html__( 'Delivered', 'notify-telegram' ) : esc_html__( 'Failed', 'notify-telegram' ); ?>
								&mdash;
								<?php echo esc_html( $entry['message'] ); ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::CLEAR_LOG_ACTION ); ?>" />
				<?php wp_nonce_field( self::CLEAR_LOG_ACTION ); ?>
				<p>
					<?php submit_button( __( 'Clear log', 'notify-telegram' ), 'secondary', 'submit', false ); ?>
				</p>
			</form>
		<?php endif; ?>
		<?php
	}

	/**
	 * Shows the result of the last test, once.
	 *
	 * @return void
	 */
	private function render_test_notice(): void {
		$key     = self::notice_key();
		$results = get_transient( $key );

		if ( ! is_array( $results ) ) {
			return;
		}

		delete_transient( $key );

		if ( array() === $results ) {
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				esc_html__( 'No channel is configured yet, so there was nothing to send through.', 'notify-telegram' )
			);

			return;
		}

		foreach ( $results as $result ) {
			$ok      = ! empty( $result['ok'] );
			$channel = isset( $result['channel'] ) ? (string) $result['channel'] : '';
			$message = isset( $result['message'] ) ? (string) $result['message'] : '';

			printf(
				'<div class="notice %1$s"><p>%2$s</p></div>',
				$ok ? 'notice-success' : 'notice-error',
				esc_html(
					sprintf(
						/* translators: 1: channel name, 2: result text. */
						__( '%1$s: %2$s', 'notify-telegram' ),
						$channel,
						$message
					)
				)
			);
		}
	}

	/**
	 * Hint listing the placeholders an event fills.
	 *
	 * @param Event $event Event.
	 * @return string
	 */
	private function placeholders_hint( Event $event ): string {
		$hints = array();

		foreach ( $event->placeholders() as $name => $description ) {
			$hints[] = '%' . $name . '% (' . $description . ')';
		}

		return sprintf(
			/* translators: %s: comma separated list of placeholders. */
			__( 'Placeholders: %s', 'notify-telegram' ),
			implode( ', ', $hints )
		);
	}

	/**
	 * Label of an event identifier, falling back to the identifier itself.
	 *
	 * @param string $event_id Event identifier.
	 * @return string
	 */
	private function event_label( string $event_id ): string {
		return $this->events->get( $event_id )?->label() ?? $event_id;
	}

	/**
	 * Label of a channel identifier, falling back to the identifier itself.
	 *
	 * @param string $channel_id Channel identifier.
	 * @return string
	 */
	private function channel_label( string $channel_id ): string {
		return $this->channels->get( $channel_id )?->label() ?? $channel_id;
	}

	/**
	 * Formats a stored timestamp in the site's timezone and format.
	 *
	 * @param int $timestamp Unix timestamp.
	 * @return string
	 */
	private function format_time( int $timestamp ): string {
		$format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

		return (string) wp_date( is_string( $format ) ? $format : 'Y-m-d H:i', $timestamp );
	}

	/**
	 * Name of a settings field.
	 *
	 * @param string ...$path Path inside the option array.
	 * @return string
	 */
	private function field_name( string ...$path ): string {
		return Settings::OPTION . '[' . implode( '][', $path ) . ']';
	}

	/**
	 * HTML id of a field.
	 *
	 * @param string ...$path Path inside the option array.
	 * @return string
	 */
	private function field_id( string ...$path ): string {
		return Settings::OPTION . '-' . implode( '-', $path );
	}

	/**
	 * Transient key of the test result for the current user.
	 *
	 * @return string
	 */
	private function notice_key(): string {
		return self::NOTICE_TRANSIENT . get_current_user_id();
	}

	/**
	 * URL of this page.
	 *
	 * @return string
	 */
	private function page_url(): string {
		return add_query_arg( 'page', self::MENU_SLUG, admin_url( 'options-general.php' ) );
	}
}
