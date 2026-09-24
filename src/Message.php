<?php
/**
 * The text that leaves the site, built from an event template.
 *
 * @package NotifyTelegram
 */

declare(strict_types=1);

namespace Wpseed\NotifyTelegram;

/**
 * A notification ready to be handed to a channel.
 *
 * Rendering lives here rather than in a channel: every channel has to see exactly the same text,
 * and a placeholder that is missing a value must fail the same way everywhere.
 */
final class Message {

	/**
	 * Constructor.
	 *
	 * @param string               $event_id Event identifier.
	 * @param string               $text     Rendered text.
	 * @param string               $subject  Subject line (used by channels that have one).
	 * @param array<string, mixed> $context  Values the text was built from.
	 */
	private function __construct(
		private readonly string $event_id,
		private readonly string $text,
		private readonly string $subject,
		private readonly array $context,
	) {
	}

	/**
	 * Builds a message from a template and the event context.
	 *
	 * @param string               $event_id Event identifier.
	 * @param string               $template Template with %placeholders%.
	 * @param array<string, mixed> $context  Values keyed by placeholder name.
	 * @param string               $subject  Subject line.
	 * @return self
	 */
	public static function from_template( string $event_id, string $template, array $context, string $subject = '' ): self {
		return new self( $event_id, self::render( $template, $context ), $subject, $context );
	}

	/**
	 * Builds a message from ready text (the settings screen uses this for its test message).
	 *
	 * @param string               $event_id Event identifier.
	 * @param string               $text     Text.
	 * @param string               $subject  Subject line.
	 * @param array<string, mixed> $context  Context.
	 * @return self
	 */
	public static function plain( string $event_id, string $text, string $subject = '', array $context = array() ): self {
		return new self( $event_id, $text, $subject, $context );
	}

	/**
	 * Replaces %placeholder% with the matching context value.
	 *
	 * A placeholder without a value stays in the text as written: a visible %typo% in a Telegram
	 * chat is a better report than a silently empty sentence.
	 *
	 * @param string               $template Template.
	 * @param array<string, mixed> $context  Values.
	 * @return string
	 */
	public static function render( string $template, array $context ): string {
		$replacements = array();

		foreach ( $context as $key => $value ) {
			if ( is_scalar( $value ) ) {
				$replacements[ '%' . $key . '%' ] = (string) $value;
			}
		}

		return strtr( $template, $replacements );
	}

	/**
	 * Placeholders written in a template, without duplicates, in the order they appear.
	 *
	 * @param string $template Template.
	 * @return array<int, string>
	 */
	public static function placeholders_in( string $template ): array {
		$found = preg_match_all( '/%([a-z0-9_]+)%/', $template, $matches );

		if ( ! is_int( $found ) || 0 === $found ) {
			return array();
		}

		return array_values( array_unique( $matches[1] ) );
	}

	/**
	 * Checks a template against the placeholders an event declares.
	 *
	 * @param string             $template Template.
	 * @param array<int, string> $allowed  Placeholder names the event provides.
	 * @return string|null Error message, or null when the template is usable.
	 */
	public static function validate_template( string $template, array $allowed ): ?string {
		if ( '' === trim( $template ) ) {
			return __( 'The message cannot be empty.', 'notify-telegram' );
		}

		$unknown = array_diff( self::placeholders_in( $template ), $allowed );

		if ( array() !== $unknown ) {
			return sprintf(
				/* translators: %s: comma separated list of placeholder names. */
				__( 'Unknown placeholder: %s', 'notify-telegram' ),
				implode( ', ', $unknown )
			);
		}

		$remainder = preg_replace( '/%[a-z0-9_]+%/', '', $template );

		if ( is_string( $remainder ) && str_contains( $remainder, '%' ) ) {
			return __( 'A percent sign is only allowed as part of a %placeholder%.', 'notify-telegram' );
		}

		return null;
	}

	/**
	 * Event this message belongs to.
	 *
	 * @return string
	 */
	public function event_id(): string {
		return $this->event_id;
	}

	/**
	 * Rendered text.
	 *
	 * @return string
	 */
	public function text(): string {
		return $this->text;
	}

	/**
	 * Subject line.
	 *
	 * @return string
	 */
	public function subject(): string {
		return $this->subject;
	}

	/**
	 * Context the text was built from.
	 *
	 * @return array<string, mixed>
	 */
	public function context(): array {
		return $this->context;
	}

	/**
	 * Representation that can be stored in a scheduled event.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'event'   => $this->event_id,
			'text'    => $this->text,
			'subject' => $this->subject,
			'context' => $this->context,
		);
	}

	/**
	 * Rebuilds a message from a scheduled event payload.
	 *
	 * @param array<string, mixed> $data Payload.
	 * @return self
	 */
	public static function from_array( array $data ): self {
		$context = isset( $data['context'] ) && is_array( $data['context'] ) ? $data['context'] : array();

		return new self(
			isset( $data['event'] ) ? (string) $data['event'] : '',
			isset( $data['text'] ) ? (string) $data['text'] : '',
			isset( $data['subject'] ) ? (string) $data['subject'] : '',
			$context
		);
	}
}
