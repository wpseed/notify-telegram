<?php
/**
 * Delivery queue: sends out of the request, retries what failed.
 *
 * @package NotifyTelegram
 */

declare(strict_types=1);

namespace Wpseed\NotifyTelegram\Delivery;

use Wpseed\NotifyTelegram\Channel\ChannelRegistry;
use Wpseed\NotifyTelegram\Message;

/**
 * Hands each message to one scheduled event per channel.
 *
 * Sending inside the request that triggered the event would put a 15 second HTTP timeout in the
 * middle of a registration, a comment or a checkout — so delivery is a scheduled event, and a
 * failed one is retried with a growing pause before it is written to the log as final.
 */
final class Queue {

	/**
	 * Cron hook of a single delivery.
	 */
	public const HOOK = 'notify_telegram_deliver';

	/**
	 * Delivery attempts per channel, including the first one.
	 */
	public const MAX_ATTEMPTS = 3;

	/**
	 * Base pause between attempts, in seconds; multiplied by the attempt number.
	 */
	public const RETRY_DELAY = 60;

	/**
	 * Constructor.
	 *
	 * @param ChannelRegistry $channels Channels.
	 * @param Log             $log      Delivery log.
	 */
	public function __construct(
		private readonly ChannelRegistry $channels,
		private readonly Log $log,
	) {
	}

	/**
	 * Registers the cron hook.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( self::HOOK, array( $this, 'deliver' ), 10, 1 );
	}

	/**
	 * Queues a message for a list of channels.
	 *
	 * @param Message            $message     Message.
	 * @param array<int, string> $channel_ids Channel identifiers.
	 * @return void
	 */
	public function push( Message $message, array $channel_ids ): void {
		foreach ( $channel_ids as $channel_id ) {
			$payload = array(
				'message' => $message->to_array(),
				'channel' => $channel_id,
				'attempt' => 1,
			);

			if ( ! $this->is_async() ) {
				$this->deliver( $payload );

				continue;
			}

			$this->schedule( $payload, 0 );
		}
	}

	/**
	 * Delivers one queued message, and schedules a retry if it failed.
	 *
	 * @param array<string, mixed> $payload Queued payload.
	 * @return void
	 */
	public function deliver( array $payload ): void {
		$channel_id = isset( $payload['channel'] ) ? (string) $payload['channel'] : '';
		$attempt    = max( 1, (int) ( $payload['attempt'] ?? 1 ) );
		$message    = Message::from_array( is_array( $payload['message'] ?? null ) ? $payload['message'] : array() );
		$channel    = $this->channels->get( $channel_id );

		if ( null === $channel ) {
			$this->log->add( $message->event_id(), $channel_id, false, __( 'Unknown channel.', 'notify-telegram' ) );

			return;
		}

		$result = $channel->send( $message );

		if ( $result->is_ok() ) {
			$this->log->add( $message->event_id(), $channel_id, true, __( 'Delivered.', 'notify-telegram' ) );

			return;
		}

		if ( $attempt < self::MAX_ATTEMPTS ) {
			$this->schedule(
				array(
					'message' => $message->to_array(),
					'channel' => $channel_id,
					'attempt' => $attempt + 1,
				),
				self::RETRY_DELAY * $attempt
			);

			return;
		}

		$this->log->add(
			$message->event_id(),
			$channel_id,
			false,
			sprintf(
				/* translators: 1: number of attempts, 2: failure reason. */
				__( 'Gave up after %1$d attempts: %2$s', 'notify-telegram' ),
				$attempt,
				$result->error()
			)
		);
	}

	/**
	 * Whether delivery is deferred to cron (the settings screen and the tests switch it off).
	 *
	 * @return bool
	 */
	private function is_async(): bool {
		return (bool) apply_filters( 'notify_telegram_send_async', true );
	}

	/**
	 * Schedules one delivery attempt.
	 *
	 * The payload carries a unique token because wp_schedule_single_event() drops a second
	 * identical event within ten minutes: two identical messages (the same comment text twice, a
	 * repeated test message) would otherwise be silently reduced to one.
	 *
	 * @param array<string, mixed> $payload Queued payload.
	 * @param int                  $delay   Seconds from now.
	 * @return void
	 */
	private function schedule( array $payload, int $delay ): void {
		$payload['token'] = uniqid( '', true );

		wp_schedule_single_event( time() + $delay, self::HOOK, array( $payload ) );
	}
}
