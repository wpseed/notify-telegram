<?php
/**
 * Plugin settings: the master switch, the toggles and the field values.
 *
 * @package NotifyTelegram
 */

declare(strict_types=1);

namespace Wpseed\NotifyTelegram\Settings;

/**
 * Reads and writes the single option the plugin stores its configuration in.
 *
 * Toggles default to "on" and are only overridden when a value was actually saved: a new event or
 * a new channel then works out of the box, and a channel that is on but has no credentials yet is
 * caught by the channel itself (is_configured) rather than by a second switch here.
 */
final class Settings {

	/**
	 * Option name.
	 */
	public const OPTION = 'notify_telegram_settings';

	/**
	 * Stored settings, loaded once per request.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $cache = null;

	/**
	 * Every setting, with the stored values overriding the defaults.
	 *
	 * @return array<string, mixed>
	 */
	public function all(): array {
		if ( null === $this->cache ) {
			$stored = get_option( self::OPTION, array() );

			$this->cache = self::normalize( is_array( $stored ) ? $stored : array() );
		}

		return $this->cache;
	}

	/**
	 * Empty settings, i.e. everything at its default.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'enabled'   => true,
			'channels'  => array(),
			'events'    => array(),
			'templates' => array(),
		);
	}

	/**
	 * Whether the plugin sends anything at all.
	 *
	 * @return bool
	 */
	public function enabled(): bool {
		return (bool) $this->all()['enabled'];
	}

	/**
	 * Whether a channel is switched on.
	 *
	 * @param string $id Channel identifier.
	 * @return bool
	 */
	public function channel_enabled( string $id ): bool {
		$channels = $this->all()['channels'];

		return ! is_array( $channels[ $id ] ?? null ) || ! isset( $channels[ $id ]['enabled'] )
			? true
			: (bool) $channels[ $id ]['enabled'];
	}

	/**
	 * Stored values of one channel.
	 *
	 * @param string $id Channel identifier.
	 * @return array<string, mixed>
	 */
	public function channel( string $id ): array {
		$channels = $this->all()['channels'];
		$values   = $channels[ $id ] ?? array();

		return is_array( $values ) ? $values : array();
	}

	/**
	 * One field of one channel as a string.
	 *
	 * @param string $id    Channel identifier.
	 * @param string $field Field name.
	 * @return string
	 */
	public function channel_value( string $id, string $field ): string {
		$value = $this->channel( $id )[ $field ] ?? '';

		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Whether an event is switched on.
	 *
	 * @param string $id Event identifier.
	 * @return bool
	 */
	public function event_enabled( string $id ): bool {
		$events = $this->all()['events'];

		return ! isset( $events[ $id ] ) ? true : (bool) $events[ $id ];
	}

	/**
	 * Template saved for an event (empty string when the event default is used).
	 *
	 * @param string $event_id Event identifier.
	 * @return string
	 */
	public function template( string $event_id ): string {
		$templates = $this->all()['templates'];
		$template  = $templates[ $event_id ] ?? '';

		return is_string( $template ) ? $template : '';
	}

	/**
	 * Sanitizes and stores submitted settings.
	 *
	 * @param array<string, mixed> $raw Submitted values.
	 * @return void
	 */
	public function save( array $raw ): void {
		update_option( self::OPTION, self::sanitize( $raw ), false );

		$this->cache = null;
	}

	/**
	 * Cleans submitted values: text fields are stripped of markup, toggles become booleans.
	 *
	 * @param array<string, mixed> $raw Submitted values.
	 * @return array<string, mixed>
	 */
	public static function sanitize( array $raw ): array {
		$clean = array(
			'enabled'   => ! empty( $raw['enabled'] ),
			'channels'  => array(),
			'events'    => array(),
			'templates' => array(),
		);

		foreach ( (array) ( $raw['channels'] ?? array() ) as $id => $values ) {
			if ( ! is_array( $values ) ) {
				continue;
			}

			$clean['channels'][ (string) $id ] = self::sanitize_channel( $values );
		}

		foreach ( (array) ( $raw['events'] ?? array() ) as $id => $value ) {
			$clean['events'][ (string) $id ] = ! empty( $value );
		}

		foreach ( (array) ( $raw['templates'] ?? array() ) as $id => $value ) {
			if ( is_scalar( $value ) ) {
				$clean['templates'][ (string) $id ] = trim( sanitize_textarea_field( (string) $value ) );
			}
		}

		return $clean;
	}

	/**
	 * Normalizes a stored array without touching WordPress: used on read, and unit tested.
	 *
	 * @param array<string, mixed> $raw Stored values.
	 * @return array<string, mixed>
	 */
	public static function normalize( array $raw ): array {
		$normalized = array(
			'enabled'   => ! isset( $raw['enabled'] ) || ! empty( $raw['enabled'] ),
			'channels'  => array(),
			'events'    => array(),
			'templates' => array(),
		);

		foreach ( (array) ( $raw['channels'] ?? array() ) as $id => $values ) {
			if ( ! is_array( $values ) ) {
				continue;
			}

			$clean = array();

			// Anything that is not a scalar is dropped: an option edited by hand must not turn
			// into the string "Array" on its way to a field.
			foreach ( $values as $field => $value ) {
				if ( is_bool( $value ) ) {
					$clean[ (string) $field ] = $value;
				} elseif ( is_scalar( $value ) ) {
					$clean[ (string) $field ] = trim( (string) $value );
				}
			}

			$normalized['channels'][ (string) $id ] = $clean;
		}

		foreach ( (array) ( $raw['events'] ?? array() ) as $id => $value ) {
			$normalized['events'][ (string) $id ] = ! empty( $value );
		}

		foreach ( (array) ( $raw['templates'] ?? array() ) as $id => $value ) {
			if ( is_scalar( $value ) ) {
				$normalized['templates'][ (string) $id ] = trim( (string) $value );
			}
		}

		return $normalized;
	}

	/**
	 * Cleans one channel's values, keeping the boolean "enabled" flag a boolean.
	 *
	 * @param array<string, mixed> $values Submitted values.
	 * @return array<string, mixed>
	 */
	private static function sanitize_channel( array $values ): array {
		$clean = array();

		foreach ( $values as $field => $value ) {
			if ( 'enabled' === $field ) {
				$clean[ (string) $field ] = ! empty( $value );

				continue;
			}

			if ( is_scalar( $value ) ) {
				$clean[ (string) $field ] = trim( sanitize_textarea_field( (string) $value ) );
			}
		}

		return $clean;
	}
}
