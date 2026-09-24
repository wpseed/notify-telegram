<?php

declare(strict_types=1);

namespace Wpseed\NotifyTelegram\Tests\Fakes;

use Wpseed\NotifyTelegram\Channel\Channel;
use Wpseed\NotifyTelegram\Channel\Result;
use Wpseed\NotifyTelegram\Message;

/**
 * Channel that records what it was asked to send.
 *
 * The tests register it through the channel registry, so the router, the queue and the event
 * sources can be exercised end to end without a network call.
 */
final class RecordingChannel implements Channel
{
    /**
     * @var list<Message>
     */
    public array $sent = [];

    public function __construct(
        private readonly string $channel_id = 'recorder',
        private readonly bool $configured = true,
        private readonly bool $fails = false,
        private readonly bool $permanent = false,
        private readonly int $retry_after = 0,
    ) {
    }

    public function id(): string
    {
        return $this->channel_id;
    }

    public function label(): string
    {
        return ucfirst($this->channel_id);
    }

    /**
     * @return array<string, array{label: string, type: string}>
     */
    public function fields(): array
    {
        return [];
    }

    public function is_configured(): bool
    {
        return $this->configured;
    }

    public function send(Message $message): Result
    {
        $this->sent[] = $message;

        return $this->fails ? Result::fail('recorder failed', $this->retry_after, $this->permanent) : Result::ok();
    }

    public function last_text(): string
    {
        return $this->sent === [] ? '' : $this->sent[count($this->sent) - 1]->text();
    }
}
