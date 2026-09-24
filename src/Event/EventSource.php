<?php
/**
 * Contract for a group of events that hooks into WordPress.
 *
 * @package NotifyTelegram
 */

declare(strict_types=1);

namespace Wpseed\NotifyTelegram\Event;

use Wpseed\NotifyTelegram\Delivery\Router;

/**
 * A bundle of related events: it declares them and it watches for them.
 *
 * One source per area of WordPress (users, comments, WooCommerce later) keeps the "what happens"
 * part in one file, and adding an area means adding a source — not touching the plugin boot.
 */
interface EventSource {

	/**
	 * Declares the events and hooks them up.
	 *
	 * @param EventRegistry $events Registry to declare events in.
	 * @param Router        $router Router to hand occurrences to.
	 * @return void
	 */
	public function register( EventRegistry $events, Router $router ): void;
}
