<?php
/**
 * Lookup of the registered channels.
 *
 * @package NotifyTelegram
 */

declare(strict_types=1);

namespace Wpseed\NotifyTelegram\Channel;

/**
 * Holds every channel the plugin knows about.
 *
 * The registry only looks channels up. Which of them are switched on is a settings question and
 * which are usable is the channel's own answer, so both stay outside this class.
 */
final class ChannelRegistry {

	/**
	 * Channels keyed by identifier.
	 *
	 * @var array<string, Channel>
	 */
	private array $channels = array();

	/**
	 * Constructor.
	 *
	 * @param array<int, Channel> $channels Channels to register, in display order.
	 */
	public function __construct( array $channels = array() ) {
		foreach ( $channels as $channel ) {
			$this->register( $channel );
		}
	}

	/**
	 * Registers a channel, replacing one with the same identifier.
	 *
	 * @param Channel $channel Channel.
	 * @return void
	 */
	public function register( Channel $channel ): void {
		$this->channels[ $channel->id() ] = $channel;
	}

	/**
	 * Removes a channel (used by tests and by code that replaces a channel at runtime).
	 *
	 * @param string $id Identifier.
	 * @return void
	 */
	public function unregister( string $id ): void {
		unset( $this->channels[ $id ] );
	}

	/**
	 * All channels in registration order.
	 *
	 * @return array<string, Channel>
	 */
	public function all(): array {
		return $this->channels;
	}

	/**
	 * Channel by identifier.
	 *
	 * @param string $id Identifier.
	 * @return Channel|null
	 */
	public function get( string $id ): ?Channel {
		return $this->channels[ $id ] ?? null;
	}
}
