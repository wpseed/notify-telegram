<?php
/**
 * Webhook delivery channel.
 *
 * @package NotifyTelegram
 */

declare(strict_types=1);

namespace Wpseed\NotifyTelegram\Channel;

use Wpseed\NotifyTelegram\Message;
use Wpseed\NotifyTelegram\Settings\Settings;

/**
 * Posts a JSON payload to one or more URLs.
 *
 * This is the channel that makes the plugin useful beyond Telegram without a new class per
 * service: Slack, Discord, Mattermost, n8n, Make and Zapier all take an incoming webhook, so the
 * same code covers them and only the URL differs.
 */
final class WebhookChannel implements Channel {

	/**
	 * Channel identifier.
	 */
	public const ID = 'webhook';

	/**
	 * Header carrying the signature when a secret is configured.
	 */
	public const SIGNATURE_HEADER = 'X-Notify-Signature';

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
		return __( 'Webhook', 'notify-telegram' );
	}

	/**
	 * Form fields of this channel.
	 *
	 * @return array<string, array{label: string, type: string, description?: string, placeholder?: string}>
	 */
	public function fields(): array {
		return array(
			'urls'   => array(
				'label'       => __( 'Webhook URLs', 'notify-telegram' ),
				'type'        => 'textarea',
				'placeholder' => 'https://hooks.slack.com/services/...',
				'description' => __( 'One URL per line: Slack, Discord, Mattermost, n8n, Zapier. The payload is JSON.', 'notify-telegram' ),
			),
			'secret' => array(
				'label'       => __( 'Signing secret', 'notify-telegram' ),
				'type'        => 'text',
				'description' => __( 'Optional. When set, each request carries an HMAC-SHA256 signature of its body in the X-Notify-Signature header.', 'notify-telegram' ),
			),
		);
	}

	/**
	 * Whether at least one URL is configured.
	 *
	 * @return bool
	 */
	public function is_configured(): bool {
		return array() !== $this->urls();
	}

	/**
	 * Sends the message to every configured URL.
	 *
	 * A failing endpoint does not stop the others: they are independent, and a Slack hook that was
	 * deleted must not silence the one that still works. The failures are collected and reported
	 * together.
	 *
	 * @param Message $message Message.
	 * @return Result
	 */
	public function send( Message $message ): Result {
		$body   = (string) wp_json_encode( self::payload( $message, home_url( '/' ), time() ) );
		$secret = trim( $this->settings->channel_value( self::ID, 'secret' ) );

		$headers = array( 'Content-Type' => 'application/json' );

		if ( '' !== $secret ) {
			$headers[ self::SIGNATURE_HEADER ] = 'sha256=' . hash_hmac( 'sha256', $body, $secret );
		}

		$failures = array();

		foreach ( $this->urls() as $url ) {
			$response = wp_remote_post(
				$url,
				array(
					'timeout' => 15,
					'headers' => $headers,
					'body'    => $body,
				)
			);

			$result = self::read_response( $response );

			if ( $result->is_ok() ) {
				continue;
			}

			$failures[] = Result::fail(
				$url . ': ' . $result->error(),
				$result->retry_after(),
				$result->is_permanent()
			);
		}

		return Result::combine( $failures );
	}

	/**
	 * JSON body of a webhook call.
	 *
	 * @param Message $message   Message.
	 * @param string  $site_url  Site URL.
	 * @param int     $timestamp Unix timestamp of the delivery.
	 * @return array<string, mixed>
	 */
	public static function payload( Message $message, string $site_url, int $timestamp ): array {
		return array(
			'event'     => $message->event_id(),
			'subject'   => $message->subject(),
			'text'      => $message->text(),
			'site'      => $site_url,
			'timestamp' => $timestamp,
			'context'   => $message->context(),
		);
	}

	/**
	 * Turns the answer of the endpoint into a result.
	 *
	 * A 4xx answer is final — a URL that moved, a hook that was revoked, a payload the endpoint
	 * refuses stays refused — while a 5xx answer and a transport error are worth another attempt.
	 * A 429 can tell the queue when to come back through the `Retry-After` header.
	 *
	 * @param mixed $response Answer of wp_remote_post().
	 * @return Result
	 */
	public static function read_response( mixed $response ): Result {
		if ( is_wp_error( $response ) ) {
			return Result::fail( $response->get_error_message() );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		if ( $status >= 200 && $status <= 299 ) {
			return Result::ok();
		}

		$body = trim( (string) wp_remote_retrieve_body( $response ) );

		if ( '' !== $body ) {
			$body = ': ' . mb_substr( $body, 0, 200 );
		}

		return Result::fail(
			sprintf(
				/* translators: 1: HTTP status code, 2: first characters of the response body. */
				__( 'HTTP %1$d%2$s', 'notify-telegram' ),
				$status,
				$body
			),
			max( 0, (int) wp_remote_retrieve_header( $response, 'retry-after' ) ),
			$status >= 400 && $status <= 499
		);
	}

	/**
	 * Configured URLs.
	 *
	 * @return array<int, string>
	 */
	public function urls(): array {
		$urls = array();

		foreach ( self::parse_list( $this->settings->channel_value( self::ID, 'urls' ) ) as $url ) {
			if ( wp_http_validate_url( $url ) ) {
				$urls[] = $url;
			}
		}

		return $urls;
	}

	/**
	 * Splits a textarea value into a clean list of candidates.
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
}
