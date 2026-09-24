<?php
/**
 * REST routes the plugin's admin application reads and writes through.
 *
 * @package NotifyTelegram
 */

declare(strict_types=1);

namespace Wpseed\NotifyTelegram\Rest;

use Wpseed\NotifyTelegram\Channel\Channel;
use Wpseed\NotifyTelegram\Channel\ChannelRegistry;
use Wpseed\NotifyTelegram\Delivery\Log;
use Wpseed\NotifyTelegram\Delivery\TestSender;
use Wpseed\NotifyTelegram\Event\Event;
use Wpseed\NotifyTelegram\Event\EventRegistry;
use Wpseed\NotifyTelegram\Message;
use Wpseed\NotifyTelegram\Plugin;
use Wpseed\NotifyTelegram\Settings\Settings;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Everything the screens need, in one place: the current state, saving it, the test button and the log.
 *
 * The payload is built from the objects rather than from a hand-written shape, so a channel's fields and
 * an event's placeholders reach the application without a second definition to keep in sync. Only users
 * with `manage_options` reach any of it, and cookie-authenticated requests must carry the `wp_rest` nonce,
 * which the application sends in the X-WP-Nonce header from the configuration PHP prints next to the bundle.
 */
final class SettingsController {

	/**
	 * REST namespace.
	 */
	public const REST_NAMESPACE = 'notify-telegram/v1';

	/**
	 * Route holding the whole state.
	 */
	public const ROUTE_SETTINGS = '/settings';

	/**
	 * Route sending a test message.
	 */
	public const ROUTE_TEST = '/test';

	/**
	 * Route clearing the delivery log.
	 */
	public const ROUTE_LOG = '/log';

	/**
	 * Constructor.
	 *
	 * @param Settings        $settings Settings.
	 * @param ChannelRegistry $channels Channels.
	 * @param EventRegistry   $events   Events.
	 * @param Log             $log      Delivery log.
	 * @param TestSender      $tester   Test message sender.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly ChannelRegistry $channels,
		private readonly EventRegistry $events,
		private readonly Log $log,
		private readonly TestSender $tester,
	) {
	}

	/**
	 * Registers the REST hook.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers the routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_SETTINGS,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => array( $this, 'can_manage_options' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_settings' ),
					'permission_callback' => array( $this, 'can_manage_options' ),
				),
				'schema' => array( $this, 'get_schema' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_TEST,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'send_test' ),
				'permission_callback' => array( $this, 'can_manage_options' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_LOG,
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'clear_log' ),
				'permission_callback' => array( $this, 'can_manage_options' ),
			)
		);
	}

	/**
	 * Whether the current user may read and change the settings.
	 *
	 * @return bool
	 */
	public function can_manage_options(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Returns the whole state.
	 *
	 * @return WP_REST_Response
	 */
	public function get_settings(): WP_REST_Response {
		return rest_ensure_response( $this->payload() );
	}

	/**
	 * Stores the submitted state.
	 *
	 * An unusable message template fails the whole request with 400 instead of being dropped silently:
	 * the application shows the message next to the field and nothing is half-saved.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_settings( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$submitted = $request->get_json_params();
		$submitted = is_array( $submitted ) ? $submitted : $request->get_params();

		$invalid = $this->invalid_template( is_array( $submitted ) ? $submitted : array() );

		if ( null !== $invalid ) {
			return new WP_Error( 'notify_telegram_template', $invalid, array( 'status' => 400 ) );
		}

		$this->settings->save( is_array( $submitted ) ? $submitted : array() );

		return rest_ensure_response( $this->payload() );
	}

	/**
	 * Sends a test message and returns the results with the updated log.
	 *
	 * @return WP_REST_Response
	 */
	public function send_test(): WP_REST_Response {
		$results = $this->tester->send();

		return rest_ensure_response(
			array(
				'results' => $results,
				'log'     => $this->log_payload(),
			)
		);
	}

	/**
	 * Clears the delivery log.
	 *
	 * @return WP_REST_Response
	 */
	public function clear_log(): WP_REST_Response {
		$this->log->clear();

		return rest_ensure_response( array( 'log' => array() ) );
	}

	/**
	 * The state of the plugin, ready for the application.
	 *
	 * @return array<string, mixed>
	 */
	public function payload(): array {
		return array(
			'version'  => Plugin::VERSION,
			'enabled'  => $this->settings->enabled(),
			'channels' => $this->channel_payload(),
			'events'   => $this->event_payload(),
			'log'      => $this->log_payload(),
		);
	}

	/**
	 * Channels with their fields, values included.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function channel_payload(): array {
		$payload = array();

		foreach ( $this->channels->all() as $channel ) {
			$fields = array();

			foreach ( $channel->fields() as $name => $definition ) {
				$fields[] = array(
					'name'        => (string) $name,
					'label'       => $definition['label'] ?? (string) $name,
					'type'        => $definition['type'] ?? 'text',
					'placeholder' => $definition['placeholder'] ?? '',
					'description' => $definition['description'] ?? '',
					'value'       => $this->settings->channel_value( $channel->id(), (string) $name ),
				);
			}

			$payload[] = array(
				'id'         => $channel->id(),
				'label'      => $channel->label(),
				'configured' => $channel->is_configured(),
				'enabled'    => $this->settings->channel_enabled( $channel->id() ),
				'fields'     => $fields,
			);
		}

		return $payload;
	}

	/**
	 * Events with their toggles, their message and their placeholders.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function event_payload(): array {
		$payload = array();

		foreach ( $this->events->all() as $event ) {
			$placeholders = array();

			foreach ( $event->placeholders() as $name => $description ) {
				$placeholders[] = array(
					'name'        => (string) $name,
					'description' => (string) $description,
				);
			}

			$payload[] = array(
				'id'               => $event->id(),
				'label'            => $event->label(),
				'enabled'          => $this->settings->event_enabled( $event->id() ),
				'template'         => $this->settings->template( $event->id() ),
				'default_template' => $event->default_template(),
				'placeholders'     => $placeholders,
			);
		}

		return $payload;
	}

	/**
	 * The delivery log with labels and a formatted time.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function log_payload(): array {
		$format  = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		$format  = is_string( $format ) && '' !== $format ? $format : 'Y-m-d H:i';
		$payload = array();

		foreach ( $this->log->entries() as $entry ) {
			$payload[] = array(
				'time'          => $entry['time'],
				'date'          => (string) wp_date( $format, $entry['time'] ),
				'event'         => $entry['event'],
				'event_label'   => $this->event_label( $entry['event'] ),
				'channel'       => $entry['channel'],
				'channel_label' => $this->channel_label( $entry['channel'] ),
				'ok'            => $entry['ok'],
				'message'       => $entry['message'],
			);
		}

		return $payload;
	}

	/**
	 * Returns the first unusable message template, or null when every one of them is fine.
	 *
	 * @param array<string, mixed> $submitted Submitted state.
	 * @return string|null
	 */
	private function invalid_template( array $submitted ): ?string {
		$templates = is_array( $submitted['templates'] ?? null ) ? $submitted['templates'] : array();

		foreach ( $templates as $event_id => $template ) {
			if ( ! is_string( $template ) || '' === trim( $template ) ) {
				continue;
			}

			$event = $this->events->get( (string) $event_id );

			if ( null === $event ) {
				continue;
			}

			$error = Message::validate_template( $template, $event->placeholder_names() );

			if ( null !== $error ) {
				return sprintf(
					/* translators: 1: event title, 2: validation error. */
					__( '%1$s: %2$s', 'notify-telegram' ),
					$event->label(),
					$error
				);
			}
		}

		return null;
	}

	/**
	 * Label of an event identifier.
	 *
	 * @param string $event_id Event identifier.
	 * @return string
	 */
	private function event_label( string $event_id ): string {
		return $this->event_label_of( $this->events->get( $event_id ), $event_id );
	}

	/**
	 * Label of a channel identifier.
	 *
	 * @param string $channel_id Channel identifier.
	 * @return string
	 */
	private function channel_label( string $channel_id ): string {
		$channel = $this->channels->get( $channel_id );

		return $channel instanceof Channel ? $channel->label() : $channel_id;
	}

	/**
	 * Label of an event object, falling back to the identifier.
	 *
	 * @param Event|null $event      Event.
	 * @param string     $event_id   Event identifier.
	 * @return string
	 */
	private function event_label_of( ?Event $event, string $event_id ): string {
		return null === $event ? $event_id : $event->label();
	}

	/**
	 * Schema of the route.
	 *
	 * @return array<string, mixed>
	 */
	public function get_schema(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'notify_telegram_settings',
			'type'       => 'object',
			'properties' => array(
				'enabled'   => array(
					'description' => __( 'Whether any notification is sent at all.', 'notify-telegram' ),
					'type'        => 'boolean',
					'context'     => array( 'view', 'edit' ),
				),
				'channels'  => array(
					'description' => __( 'Channel values, keyed by channel identifier.', 'notify-telegram' ),
					'type'        => 'object',
					'context'     => array( 'view', 'edit' ),
				),
				'events'    => array(
					'description' => __( 'Event toggles, keyed by event identifier.', 'notify-telegram' ),
					'type'        => 'object',
					'context'     => array( 'view', 'edit' ),
				),
				'templates' => array(
					'description' => __( 'Message templates, keyed by event identifier.', 'notify-telegram' ),
					'type'        => 'object',
					'context'     => array( 'view', 'edit' ),
				),
			),
		);
	}
}
