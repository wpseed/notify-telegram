<?php
/**
 * Telegram delivery channel.
 *
 * @package NotifyTelegram
 */

declare(strict_types=1);

namespace Wpseed\NotifyTelegram\Channel;

use Wpseed\NotifyTelegram\Message;
use Wpseed\NotifyTelegram\Settings\Settings;

/**
 * Sends messages through the Telegram Bot API.
 *
 * The text goes out as plain text on purpose: HTML and Markdown modes would make every value the
 * site puts into a message (a customer name, a comment excerpt) a parsing hazard, and Telegram
 * answers a broken entity with a 400 instead of the message.
 *
 * A text longer than Telegram accepts is sent as several messages instead of being cut: the end of
 * a notification (the last order line, the tail of a comment) is exactly the part someone reads,
 * and losing it silently is worse than a second message. Every chat is posted to independently —
 * one chat that refuses a message does not silence the others.
 */
final class TelegramChannel implements Channel {

	/**
	 * Channel identifier.
	 */
	public const ID = 'telegram';

	/**
	 * Telegram's limit for a single message, in UTF-16 code units — it counts an emoji as two.
	 */
	public const MAX_LENGTH = 4096;

	/**
	 * Shortest prefix a split accepts when breaking on a newline or a space.
	 *
	 * Without it a text whose only newline sits at the top of the window would be cut into slivers;
	 * below this length a hard cut at the limit is better than a technicality.
	 */
	private const MIN_BREAK = 1024;

	/**
	 * Longest pause honoured when Telegram asks to wait, in seconds.
	 */
	private const MAX_RETRY_AFTER = 3600;

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
		return __( 'Telegram', 'notify-telegram' );
	}

	/**
	 * Form fields of this channel.
	 *
	 * @return array<string, array{label: string, type: string, description?: string, placeholder?: string}>
	 */
	public function fields(): array {
		return array(
			'token'    => array(
				'label'       => __( 'Bot token', 'notify-telegram' ),
				'type'        => 'text',
				'placeholder' => '123456789:AA...',
				'description' => __( 'From @BotFather: /newbot, then copy the token it prints.', 'notify-telegram' ),
			),
			'chat_ids' => array(
				'label'       => __( 'Chat IDs', 'notify-telegram' ),
				'type'        => 'textarea',
				'description' => __( 'One chat, group or channel per line. Send /start to the bot first, or the chat cannot receive messages.', 'notify-telegram' ),
			),
		);
	}

	/**
	 * Whether a token and at least one chat are configured.
	 *
	 * The token format is not enforced here: a wrong token has to reach the API to get Telegram's
	 * own explanation ("Unauthorized"), which is far more useful in the log than silence.
	 *
	 * @return bool
	 */
	public function is_configured(): bool {
		return '' !== $this->token() && array() !== $this->chat_ids();
	}

	/**
	 * Sends the message to every configured chat.
	 *
	 * Every chat is posted to even when an earlier one fails, and a text longer than the limit
	 * becomes several messages. The failures are reported together; the parts of a chat that
	 * already failed are not continued, because a message that starts in the middle reads as
	 * corruption, not as a notification.
	 *
	 * @param Message $message Message.
	 * @return Result
	 */
	public function send( Message $message ): Result {
		$parts    = self::split( $message->text() );
		$failures = array();

		foreach ( $this->chat_ids() as $chat_id ) {
			foreach ( $parts as $part ) {
				$result = $this->send_part( $chat_id, $part );

				if ( $result->is_ok() ) {
					continue;
				}

				$failures[] = Result::fail(
					sprintf(
						/* translators: 1: chat identifier, 2: failure reason. */
						__( 'chat %1$s: %2$s', 'notify-telegram' ),
						$chat_id,
						$result->error()
					),
					$result->retry_after(),
					$result->is_permanent()
				);

				break;
			}
		}

		return Result::combine( $failures );
	}

	/**
	 * Sends one part of a message to one chat.
	 *
	 * @param string $chat_id Chat identifier.
	 * @param string $text    Text of this part.
	 * @return Result
	 */
	private function send_part( string $chat_id, string $text ): Result {
		return self::read_response(
			wp_remote_post(
				$this->endpoint(),
				array(
					'timeout' => 15,
					'headers' => array( 'Content-Type' => 'application/json' ),
					'body'    => (string) wp_json_encode( self::payload( $chat_id, $text ) ),
				)
			)
		);
	}

	/**
	 * Body of the sendMessage call.
	 *
	 * @param string $chat_id Chat identifier.
	 * @param string $text    Message text.
	 * @return array<string, mixed>
	 */
	public static function payload( string $chat_id, string $text ): array {
		return array(
			'chat_id'                  => $chat_id,
			'text'                     => $text,
			'disable_web_page_preview' => true,
		);
	}

	/**
	 * Splits the text into the messages Telegram will accept.
	 *
	 * The break is looked for in the last portion of each piece — the last newline first, then the
	 * last space — so a line or at least a word survives whole; a text with no break at all is cut
	 * at the limit. Nothing is dropped: the pieces carry the same content, only the whitespace the
	 * break sat on goes away.
	 *
	 * @param string $text Text.
	 * @return array<int, string> One entry per message, at least one.
	 */
	public static function split( string $text ): array {
		if ( self::length( $text ) <= self::MAX_LENGTH ) {
			return array( $text );
		}

		$parts = array();
		$rest  = $text;

		while ( '' !== $rest ) {
			$chunk = self::window( $rest );

			if ( mb_strlen( $chunk ) >= mb_strlen( $rest ) ) {
				$parts[] = $rest;

				break;
			}

			$break = self::last_break( $chunk );

			if ( 0 === $break ) {
				$parts[] = $chunk;
				$rest    = ltrim( mb_substr( $rest, mb_strlen( $chunk ) ), " 	\n\r" );

				continue;
			}

			$parts[] = rtrim( mb_substr( $chunk, 0, $break ) );
			$rest    = ltrim( mb_substr( $rest, $break ), " 	\n\r" );
		}

		return $parts;
	}

	/**
	 * Length of a text the way Telegram counts it.
	 *
	 * The API counts UTF-16 code units, so an emoji costs two: a message of 4096 code points can
	 * already be too long. Measuring in code points here would let the API answer those with a 400.
	 *
	 * @param string $text Text.
	 * @return int
	 */
	public static function length( string $text ): int {
		return intdiv( strlen( mb_convert_encoding( $text, 'UTF-16LE', 'UTF-8' ) ), 2 );
	}

	/**
	 * Longest prefix of the text that fits into a single message.
	 *
	 * @param string $text Text.
	 * @return string The text itself when it already fits.
	 */
	private static function window( string $text ): string {
		if ( self::length( $text ) <= self::MAX_LENGTH ) {
			return $text;
		}

		$utf16 = mb_convert_encoding( $text, 'UTF-16LE', 'UTF-8' );
		$head  = substr( $utf16, 0, self::MAX_LENGTH * 2 );

		// A cut between the halves of a surrogate pair leaves an unencodable character behind.
		$last = ord( $head[ self::MAX_LENGTH * 2 - 2 ] ) | ( ord( $head[ self::MAX_LENGTH * 2 - 1 ] ) << 8 );

		if ( $last >= 0xD800 && $last <= 0xDBFF ) {
			$head = substr( $head, 0, -2 );
		}

		return (string) mb_convert_encoding( $head, 'UTF-8', 'UTF-16LE' );
	}

	/**
	 * Where to cut a window so the break does not land in the middle of a line or a word.
	 *
	 * @param string $window Window.
	 * @return int Code point offset to cut at, 0 when nowhere sensible is late enough.
	 */
	private static function last_break( string $window ): int {
		foreach ( array( "\n", ' ' ) as $needle ) {
			$position = mb_strrpos( $window, $needle );

			if ( false !== $position && $position >= self::MIN_BREAK ) {
				return (int) $position;
			}
		}

		return 0;
	}

	/**
	 * Turns Telegram's answer into a result.
	 *
	 * A rate limit (429) carries the pause Telegram wants in `parameters.retry_after`, and it is
	 * passed on so the queue waits that long instead of guessing. The other 4xx answers — a blocked
	 * bot, a token that was revoked, a chat that does not exist — cannot be fixed by a retry, so
	 * they are marked as final: three attempts a minute apart only fill the log. A 5xx answer and a
	 * transport error stay retryable: those are Telegram's or the network's bad minute.
	 *
	 * @param mixed $response Answer of wp_remote_post().
	 * @return Result
	 */
	public static function read_response( mixed $response ): Result {
		if ( is_wp_error( $response ) ) {
			return Result::fail( $response->get_error_message() );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$data   = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$reason = is_array( $data ) && isset( $data['description'] ) ? (string) $data['description'] : '';

		$rejected = is_array( $data ) && isset( $data['ok'] ) && false === $data['ok'];

		if ( ! $rejected && $status >= 200 && $status <= 299 ) {
			return Result::ok();
		}

		if ( 429 === $status ) {
			$wait = self::wait_hint( $data, $response );

			if ( $wait > 0 ) {
				return Result::fail(
					sprintf(
						/* translators: 1: reason reported by Telegram, 2: seconds to wait. */
						__( '%1$s (rate limited, retry in %2$d s)', 'notify-telegram' ),
						'' !== $reason ? $reason : __( 'Too Many Requests', 'notify-telegram' ),
						$wait
					),
					$wait
				);
			}
		}

		if ( '' === $reason ) {
			$reason = sprintf(
				/* translators: %d: HTTP status code. */
				__( 'HTTP %d', 'notify-telegram' ),
				$status
			);
		}

		return Result::fail( $reason, 0, $status >= 400 && $status <= 499 );
	}

	/**
	 * How long Telegram asked to wait, in seconds.
	 *
	 * The Bot API puts it into the body (`parameters.retry_after`) and the HTTP layer of a proxy
	 * into the `Retry-After` header, so both are read. A value beyond an hour is capped: a paused
	 * notification is still better than a lost one, but a queue that sleeps for a day is not.
	 *
	 * @param mixed $data     Decoded response body.
	 * @param mixed $response Response.
	 * @return int Seconds, 0 when Telegram did not say.
	 */
	private static function wait_hint( mixed $data, mixed $response ): int {
		$wait = 0;

		if ( is_array( $data ) && isset( $data['parameters']['retry_after'] ) ) {
			$wait = (int) $data['parameters']['retry_after'];
		}

		if ( $wait <= 0 ) {
			$wait = (int) wp_remote_retrieve_header( $response, 'retry-after' );
		}

		return max( 0, min( self::MAX_RETRY_AFTER, $wait ) );
	}

	/**
	 * Send URL for the configured bot.
	 *
	 * @return string
	 */
	public function endpoint(): string {
		return 'https://api.telegram.org/bot' . $this->token() . '/sendMessage';
	}

	/**
	 * Chat identifiers from the settings.
	 *
	 * @return array<int, string>
	 */
	public function chat_ids(): array {
		return self::parse_list( $this->settings->channel_value( self::ID, 'chat_ids' ) );
	}

	/**
	 * Splits a textarea value into a clean list.
	 *
	 * @param string $value Raw value.
	 * @return array<int, string>
	 */
	public static function parse_list( string $value ): array {
		$items = preg_split( '/[\s,]+/', $value );

		if ( ! is_array( $items ) ) {
			return array();
		}

		return array_values( array_unique( array_filter( array_map( 'trim', $items ), static fn ( string $item ): bool => '' !== $item ) ) );
	}

	/**
	 * Bot token from the settings.
	 *
	 * @return string
	 */
	private function token(): string {
		return trim( $this->settings->channel_value( self::ID, 'token' ) );
	}
}
