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
 */
final class Result {

	/**
	 * Constructor.
	 *
	 * @param bool   $ok    Whether the send succeeded.
	 * @param string $error Failure reason.
	 */
	private function __construct(
		private readonly bool $ok,
		private readonly string $error = '',
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
	 * @param string $error Failure reason.
	 * @return self
	 */
	public static function fail( string $error ): self {
		return new self( false, $error );
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
}
