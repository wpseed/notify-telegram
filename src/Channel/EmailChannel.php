<?php
/**
 * Email delivery channel.
 *
 * @package NotifyTelegram
 */

declare(strict_types=1);

namespace Wpseed\NotifyTelegram\Channel;

use Wpseed\NotifyTelegram\Message;
use Wpseed\NotifyTelegram\Settings\Settings;

/**
 * Sends messages with wp_mail().
 *
 * This channel is not an attempt to compete with an SMTP plugin — it is the "also send me an
 * email" option for sites that already have working mail, and it reports wp_mail()'s answer as it
 * is, including the common case of a site whose mail never leaves the server.
 */
final class EmailChannel implements Channel {

	/**
	 * Channel identifier.
	 */
	public const ID = 'email';

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( private readonly Settings $settings ) {
	}

	/**
	 * Channel identifier.
	 *
	 * @return string
	 */
	public function id(): string {
		return self::ID;
	}

	/**
	 * Channel name.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Email', 'notify-telegram' );
	}

	/**
	 * Form fields of this channel.
	 *
	 * @return array<string, array{label: string, type: string, description?: string, placeholder?: string}>
	 */
	public function fields(): array {
		return array(
			'recipients' => array(
				'label'       => __( 'Recipients', 'notify-telegram' ),
				'type'        => 'textarea',
				'description' => __( 'One address per line. Deliverability depends on the site mail setup (an SMTP plugin, or the server).', 'notify-telegram' ),
			),
			'subject'    => array(
				'label'       => __( 'Subject', 'notify-telegram' ),
				'type'        => 'text',
				'placeholder' => __( '[Site] Event name', 'notify-telegram' ),
				'description' => __( 'Leave empty to use the site name and the event title.', 'notify-telegram' ),
			),
		);
	}

	/**
	 * Whether at least one valid address is configured.
	 *
	 * @return bool
	 */
	public function is_configured(): bool {
		return array() !== $this->recipients();
	}

	/**
	 * Sends the message.
	 *
	 * @param Message $message Message.
	 * @return Result
	 */
	public function send( Message $message ): Result {
		$sent = wp_mail( $this->recipients(), $this->subject( $message ), $message->text() );

		if ( ! $sent ) {
			return Result::fail( __( 'wp_mail() returned false: check the mail configuration of the site.', 'notify-telegram' ) );
		}

		return Result::ok();
	}

	/**
	 * Subject line: the configured one, or the site name plus the event title.
	 *
	 * @param Message $message Message.
	 * @return string
	 */
	public function subject( Message $message ): string {
		$configured = trim( $this->settings->channel_value( self::ID, 'subject' ) );

		if ( '' !== $configured ) {
			return $configured;
		}

		return sprintf(
			/* translators: 1: site name, 2: event title or message subject. */
			__( '[%1$s] %2$s', 'notify-telegram' ),
			wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
			'' !== $message->subject() ? $message->subject() : $message->event_id()
		);
	}

	/**
	 * Configured recipients that look like addresses.
	 *
	 * @return array<int, string>
	 */
	public function recipients(): array {
		$addresses = array();

		foreach ( self::parse_recipients( $this->settings->channel_value( self::ID, 'recipients' ) ) as $candidate ) {
			if ( is_email( $candidate ) ) {
				$addresses[] = $candidate;
			}
		}

		return $addresses;
	}

	/**
	 * Splits a textarea value into candidate addresses (no validation — see recipients()).
	 *
	 * @param string $value Raw value.
	 * @return array<int, string>
	 */
	public static function parse_recipients( string $value ): array {
		$items = preg_split( '/[\s,;]+/', $value );

		if ( ! is_array( $items ) ) {
			return array();
		}

		return array_values( array_unique( array_filter( array_map( 'trim', $items ), static fn ( string $item ): bool => '' !== $item ) ) );
	}
}
