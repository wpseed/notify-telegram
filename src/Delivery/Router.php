<?php
/**
 * Decides what an event turns into and where it goes.
 *
 * @package NotifyTelegram
 */

declare(strict_types=1);

namespace Wpseed\NotifyTelegram\Delivery;

use Wpseed\NotifyTelegram\Channel\ChannelRegistry;
use Wpseed\NotifyTelegram\Event\EventRegistry;
use Wpseed\NotifyTelegram\Message;
use Wpseed\NotifyTelegram\Settings\Settings;

/**
 * Turns an event occurrence into messages for the channels that should receive it.
 *
 * Every decision about "should this go out at all" lives here — the master switch, the event
 * toggle, and whether a channel is both enabled and configured — so the sources only report facts
 * and the channels only send.
 */
final class Router {

	/**
	 * Constructor.
	 *
	 * @param Settings        $settings Settings.
	 * @param ChannelRegistry $channels Channels.
	 * @param EventRegistry   $events   Events.
	 * @param Queue           $queue    Delivery queue.
	 * @param Log             $log      Delivery log.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly ChannelRegistry $channels,
		private readonly EventRegistry $events,
		private readonly Queue $queue,
		private readonly Log $log,
	) {
	}

	/**
	 * Routes one occurrence of an event.
	 *
	 * @param string               $event_id Event identifier.
	 * @param array<string, mixed> $context  Placeholder values.
	 * @return void
	 */
	public function dispatch( string $event_id, array $context ): void {
		if ( ! $this->settings->enabled() ) {
			return;
		}

		$event = $this->events->get( $event_id );

		if ( null === $event ) {
			return;
		}

		if ( ! $this->settings->event_enabled( $event_id ) ) {
			return;
		}

		$message  = Message::from_template(
			$event_id,
			$event->template( $this->settings->template( $event_id ) ),
			$context,
			$event->label()
		);
		$channels = $this->active_channels();

		if ( array() === $channels ) {
			$this->log->add( $event_id, '', false, __( 'Skipped: no channel is enabled and configured.', 'notify-telegram' ) );

			return;
		}

		$this->queue->push( $message, $channels );
	}

	/**
	 * Identifiers of the channels that should receive messages.
	 *
	 * @return array<int, string>
	 */
	public function active_channels(): array {
		$active = array();

		foreach ( $this->channels->all() as $channel ) {
			if ( $this->settings->channel_enabled( $channel->id() ) && $channel->is_configured() ) {
				$active[] = $channel->id();
			}
		}

		return $active;
	}
}
