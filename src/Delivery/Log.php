<?php
/**
 * Recent delivery attempts.
 *
 * @package NotifyTelegram
 */

declare(strict_types=1);

namespace Wpseed\NotifyTelegram\Delivery;

/**
 * Keeps the last deliveries in an option so "did it send?" has an answer.
 *
 * A notification plugin that only fails silently is worse than none, so every attempt — delivered,
 * skipped or given up on — leaves one line here, and the settings screen shows them.
 */
final class Log {

	/**
	 * Option name.
	 */
	public const OPTION = 'notify_telegram_log';

	/**
	 * How many entries are kept.
	 */
	public const LIMIT = 20;

	/**
	 * Adds an entry, newest first.
	 *
	 * @param string $event_id   Event identifier.
	 * @param string $channel_id Channel identifier (empty when no channel was involved).
	 * @param bool   $ok         Whether the delivery succeeded.
	 * @param string $message    Result or failure reason.
	 * @return void
	 */
	public function add( string $event_id, string $channel_id, bool $ok, string $message ): void {
		$entries = $this->entries();

		array_unshift(
			$entries,
			array(
				'time'    => time(),
				'event'   => $event_id,
				'channel' => $channel_id,
				'ok'      => $ok,
				'message' => $message,
			)
		);

		update_option( self::OPTION, array_slice( $entries, 0, self::LIMIT ), false );
	}

	/**
	 * Stored entries, newest first.
	 *
	 * @return array<int, array{time: int, event: string, channel: string, ok: bool, message: string}>
	 */
	public function entries(): array {
		$stored = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) ) {
			return array();
		}

		$entries = array();

		foreach ( $stored as $entry ) {
			if ( is_array( $entry ) ) {
				$entries[] = array(
					'time'    => (int) ( $entry['time'] ?? 0 ),
					'event'   => (string) ( $entry['event'] ?? '' ),
					'channel' => (string) ( $entry['channel'] ?? '' ),
					'ok'      => ! empty( $entry['ok'] ),
					'message' => (string) ( $entry['message'] ?? '' ),
				);
			}
		}

		return $entries;
	}

	/**
	 * Forgets everything.
	 *
	 * @return void
	 */
	public function clear(): void {
		delete_option( self::OPTION );
	}
}
