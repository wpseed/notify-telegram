<?php
/**
 * Definition of a notification event.
 *
 * @package NotifyTelegram
 */

declare(strict_types=1);

namespace Wpseed\NotifyTelegram\Event;

/**
 * What an event is: an identifier, a title, the placeholders it fills and the default text.
 *
 * The event declares the contract, the source fills it with values and settings may replace the
 * default text — which is why the placeholders travel with the event: they are what both the
 * settings screen and the template validator check against.
 */
final class Event {

	/**
	 * Constructor.
	 *
	 * @param string                $id               Event identifier.
	 * @param string                $label              Human readable title.
	 * @param array<string, string> $placeholders     Placeholder name => description.
	 * @param string                $default_template Default text.
	 */
	public function __construct(
		private readonly string $id,
		private readonly string $label,
		private readonly array $placeholders,
		private readonly string $default_template,
	) {
	}

	/**
	 * Event identifier.
	 *
	 * @return string
	 */
	public function id(): string {
		return $this->id;
	}

	/**
	 * Human readable title.
	 *
	 * @return string
	 */
	public function label(): string {
		return $this->label;
	}

	/**
	 * Placeholders the event fills, keyed by name.
	 *
	 * @return array<string, string>
	 */
	public function placeholders(): array {
		return $this->placeholders;
	}

	/**
	 * Placeholder names only.
	 *
	 * @return array<int, string>
	 */
	public function placeholder_names(): array {
		return array_keys( $this->placeholders );
	}

	/**
	 * Default text of the event.
	 *
	 * @return string
	 */
	public function default_template(): string {
		return $this->default_template;
	}

	/**
	 * Text to use, given a possible override from the settings.
	 *
	 * @param string $override Saved template (empty when the default is in use).
	 * @return string
	 */
	public function template( string $override ): string {
		return '' !== trim( $override ) ? $override : $this->default_template;
	}
}
