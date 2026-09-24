<?php
/**
 * Outcome of a single delivery attempt.
 *
 * @package NotifyTelegram
 */

declare(strict_types=1);

namespace Wpseed\NotifyTelegram\Channel;

/**
 * Whether a channel managed to send, and why not when it did not.
 *
 * An error message is always part of the result: it is what the log shows and what makes "it did
 * not arrive" answerable without a debugger.
 *
 * A failure also says how it should be retried. `retry_after` is the pause the other side asked
 * for (Telegram answers a rate limit with `parameters.retry_after`), and `permanent` marks a
 * failure that a second attempt cannot fix — a blocked bot, a rejected token, a payload the API
 * refuses. The queue reads both, so a hopeless delivery is not repeated three times a minute
 * apart.
 */
final class Result {

	/**
	 * Constructor.
	 *
	 * @param bool   $ok          Whether the send succeeded.
	 * @param string $error       Failure reason.
	 * @param int    $retry_after Seconds to wait before the next attempt, 0 when unspecified.
	 * @param bool   $permanent   Whether retrying cannot help.
	 */
	private function __construct(
		private readonly bool $ok,
		private readonly string $error = '',
		private readonly int $retry_after = 0,
		private readonly bool $permanent = false,
	) {
	}

	/**
	 * Successful result.
	 *
	 * @return self
	 */
	public static function ok(): self {
		return new self( true );
	}

	/**
	 * Failed result.
	 *
	 * @param string $error       Failure reason.
	 * @param int    $retry_after Seconds to wait before the next attempt, 0 when unspecified.
	 * @param bool   $permanent   Whether retrying cannot help.
	 * @return self
	 */
	public static function fail( string $error, int $retry_after = 0, bool $permanent = false ): self {
		return new self( false, $error, max( 0, $retry_after ), $permanent );
	}

	/**
	 * The failure of several targets as one result.
	 *
	 * A channel that posts to more than one place (several Telegram chats, several webhook URLs)
	 * collects the failures and reports them together: one broken target must not hide the state
	 * of the others. The strictest retry rule wins — the channel is retried at all only when at
	 * least one failure is worth retrying, and then after the longest pause that was asked for.
	 *
	 * @param array<int, self> $failures Failures collected from the targets.
	 * @return self
	 */
	public static function combine( array $failures ): self {
		if ( array() === $failures ) {
			return self::ok();
		}

		$messages    = array();
		$retry_after = 0;
		$permanent   = true;

		foreach ( $failures as $failure ) {
			$messages[] = $failure->error();

			if ( ! $failure->is_permanent() ) {
				$permanent = false;
			}

			$retry_after = max( $retry_after, $failure->retry_after() );
		}

		return new self( false, implode( '; ', $messages ), $retry_after, $permanent );
	}

	/**
	 * Whether the send succeeded.
	 *
	 * @return bool
	 */
	public function is_ok(): bool {
		return $this->ok;
	}

	/**
	 * Failure reason (empty on success).
	 *
	 * @return string
	 */
	public function error(): string {
		return $this->error;
	}

	/**
	 * Seconds the other side asked to wait before the next attempt (0 when it did not say).
	 *
	 * @return int
	 */
	public function retry_after(): int {
		return $this->retry_after;
	}

	/**
	 * Whether a retry cannot help.
	 *
	 * @return bool
	 */
	public function is_permanent(): bool {
		return $this->permanent;
	}
}
