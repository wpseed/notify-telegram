<?php
/**
 * Delivery channel contract.
 *
 * @package NotifyTelegram
 */

declare(strict_types=1);

namespace Wpseed\NotifyTelegram\Channel;

use Wpseed\NotifyTelegram\Message;

/**
 * One way of getting a message off the site (Telegram, email, a webhook).
 *
 * A channel owns three things: how it is configured, whether that configuration is complete, and
 * how it sends. Everything else — which events are on, when to retry, what to log — belongs to the
 * router and the queue, so a new channel is one class and one filter entry.
 */
interface Channel {

	/**
	 * Stable identifier, used in settings, logs and the queue.
	 *
	 * @return string
	 */
	public function id(): string;

	/**
	 * Human readable name.
	 *
	 * @return string
	 */
	public function label(): string;

	/**
	 * Form fields the settings screen renders for this channel.
	 *
	 * @return array<string, array{label: string, type: string, description?: string, placeholder?: string}>
	 */
	public function fields(): array;

	/**
	 * Whether the channel has everything it needs to send.
	 *
	 * An unconfigured channel is skipped instead of failing: an empty field is what a fresh
	 * install looks like, not an error to report on every event.
	 *
	 * @return bool
	 */
	public function is_configured(): bool;

	/**
	 * Sends the message.
	 *
	 * @param Message $message Message.
	 * @return Result
	 */
	public function send( Message $message ): Result;
}
