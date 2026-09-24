<?php
/**
 * The "send a test message" action.
 *
 * @package NotifyTelegram
 */

declare(strict_types=1);

namespace Wpseed\NotifyTelegram\Delivery;

use Wpseed\NotifyTelegram\Channel\ChannelRegistry;
use Wpseed\NotifyTelegram\Message;

/**
 * Sends one message through every configured channel and reports what happened.
 *
 * The toggles are ignored on purpose: this exists to check credentials, so a channel that is switched
 * off but filled in still gets the message. Both the REST route and the tests go through here, which is
 * why it lives next to the log it writes to rather than inside an admin screen.
 */
final class TestSender {

	/**
	 * Event identifier used for test messages in the log.
	 */
	public const EVENT = 'test';

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
	 * Sends the test message.
	 *
	 * @return array<int, array{channel: string, ok: bool, message: string}>
	 */
	public function send(): array {
		$message = Message::plain(
			self::EVENT,
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

			$this->log->add( self::EVENT, $channel->id(), $result->is_ok(), $summary );
		}

		return $results;
	}
}
