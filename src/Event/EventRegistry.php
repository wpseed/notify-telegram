<?php
/**
 * Lookup of the registered events.
 *
 * @package NotifyTelegram
 */

declare(strict_types=1);

namespace Wpseed\NotifyTelegram\Event;

/**
 * Holds every event the plugin listens to.
 *
 * Sources register here, the settings screen lists what it finds and the router refuses anything
 * that was never registered — so a typo in an event identifier cannot quietly send a strange
 * message.
 */
final class EventRegistry {

	/**
	 * Events keyed by identifier.
	 *
	 * @var array<string, Event>
	 */
	private array $events = array();

	/**
	 * Registers an event.
	 *
	 * @param Event $event Event.
	 * @return void
	 */
	public function register( Event $event ): void {
		$this->events[ $event->id() ] = $event;
	}

	/**
	 * All events in registration order.
	 *
	 * @return array<string, Event>
	 */
	public function all(): array {
		return $this->events;
	}

	/**
	 * Event by identifier.
	 *
	 * @param string $id Identifier.
	 * @return Event|null
	 */
	public function get( string $id ): ?Event {
		return $this->events[ $id ] ?? null;
	}
}
