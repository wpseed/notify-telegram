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
 */
final class TelegramChannel implements Channel {

	/**
	 * Channel identifier.
	 */
	public const ID = 'telegram';

	/**
	 * Telegram's limit for a single message, in characters.
	 */
	public const MAX_LENGTH = 4096;

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
	 * @param Message $message Message.
	 * @return Result
	 */
	public function send( Message $message ): Result {
		$text = self::truncate( $message->text() );

		foreach ( $this->chat_ids() as $chat_id ) {
			$response = wp_remote_post(
				$this->endpoint(),
				array(
					'timeout' => 15,
					'headers' => array( 'Content-Type' => 'application/json' ),
					'body'    => (string) wp_json_encode( self::payload( $chat_id, $text ) ),
				)
			);

			$result = self::read_response( $response );

			if ( ! $result->is_ok() ) {
				return Result::fail(
					sprintf(
						/* translators: 1: chat identifier, 2: failure reason. */
						__( 'chat %1$s: %2$s', 'notify-telegram' ),
						$chat_id,
						$result->error()
					)
				);
			}
		}

		return Result::ok();
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
	 * Cuts the text down to what Telegram accepts.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function truncate( string $text ): string {
		if ( mb_strlen( $text ) <= self::MAX_LENGTH ) {
			return $text;
		}

		return mb_substr( $text, 0, self::MAX_LENGTH - 3 ) . '...';
	}

	/**
	 * Turns Telegram's answer into a result.
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

		if ( is_array( $data ) && isset( $data['ok'] ) && false === $data['ok'] ) {
			$description = isset( $data['description'] ) ? (string) $data['description'] : '';

			return Result::fail(
				'' !== $description ? $description : __( 'Telegram rejected the message.', 'notify-telegram' )
			);
		}

		if ( $status < 200 || $status > 299 ) {
			return Result::fail(
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'HTTP %d', 'notify-telegram' ),
					$status
				)
			);
		}

		return Result::ok();
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
