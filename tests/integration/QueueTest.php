<?php

declare(strict_types=1);

namespace Wpseed\NotifyTelegram\Tests\Integration;

use Wpseed\NotifyTelegram\Channel\ChannelRegistry;
use Wpseed\NotifyTelegram\Delivery\Log;
use Wpseed\NotifyTelegram\Delivery\Queue;
use Wpseed\NotifyTelegram\Message;
use Wpseed\NotifyTelegram\Settings\Settings;
use Wpseed\NotifyTelegram\Tests\Fakes\RecordingChannel;
use WP_UnitTestCase;

/**
 * The queue: deferred delivery, retries and the log.
 */
final class QueueTest extends WP_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();

        delete_option(Settings::OPTION);
        delete_option(Log::OPTION);

        wp_clear_scheduled_hook(Queue::HOOK);
    }

    public function test_a_push_schedules_one_event_per_channel(): void
    {
        $recorder = new RecordingChannel('recorder');
        $queue = new Queue(new ChannelRegistry([$recorder]), new Log());

        $queue->push(Message::plain('demo', 'Hello'), ['recorder']);

        self::assertSame(1, self::scheduled_count());
        self::assertSame([], $recorder->sent, 'nothing may be sent inside the request');
    }

    public function test_two_identical_messages_are_scheduled_twice(): void
    {
        $queue = new Queue(new ChannelRegistry([new RecordingChannel('recorder')]), new Log());

        $queue->push(Message::plain('demo', 'Same text'), ['recorder']);
        $queue->push(Message::plain('demo', 'Same text'), ['recorder']);

        self::assertSame(2, self::scheduled_count(), 'identical messages must not collapse into one');
    }

    public function test_delivery_is_immediate_when_async_is_switched_off(): void
    {
        add_filter('notify_telegram_send_async', '__return_false');

        $recorder = new RecordingChannel('recorder');
        $queue = new Queue(new ChannelRegistry([$recorder]), new Log());

        $queue->push(Message::plain('demo', 'Hello'), ['recorder']);

        remove_filter('notify_telegram_send_async', '__return_false');

        self::assertSame(1, count($recorder->sent));
        self::assertSame('Hello', $recorder->last_text());
    }

    public function test_a_delivered_message_is_logged(): void
    {
        $log = new Log();
        $queue = new Queue(new ChannelRegistry([new RecordingChannel('recorder')]), $log);

        $queue->deliver(self::payload('recorder'));

        self::assertTrue($log->entries()[0]['ok']);
        self::assertSame('demo', $log->entries()[0]['event']);
    }

    public function test_a_failed_delivery_is_retried(): void
    {
        $log = new Log();
        $queue = new Queue(new ChannelRegistry([new RecordingChannel('failing', true, true)]), $log);

        $queue->deliver(self::payload('failing'));

        self::assertSame(1, self::scheduled_count());
        self::assertSame([], $log->entries(), 'a retry is not reported as a failure yet');
    }

    public function test_the_last_attempt_gives_up_and_is_logged(): void
    {
        $log = new Log();
        $queue = new Queue(new ChannelRegistry([new RecordingChannel('failing', true, true)]), $log);

        $queue->deliver(self::payload('failing', Queue::MAX_ATTEMPTS));

        self::assertSame(0, self::scheduled_count());
        self::assertFalse($log->entries()[0]['ok']);
        self::assertStringContainsString('3 attempts', $log->entries()[0]['message']);
        self::assertStringContainsString('recorder failed', $log->entries()[0]['message']);
    }

    public function test_a_message_for_an_unknown_channel_is_logged(): void
    {
        $log = new Log();
        $queue = new Queue(new ChannelRegistry(), $log);

        $queue->deliver(self::payload('missing'));

        self::assertFalse($log->entries()[0]['ok']);
        self::assertStringContainsString('Unknown channel', $log->entries()[0]['message']);
    }

    public function test_the_log_keeps_the_newest_entries_only(): void
    {
        $log = new Log();

        for ($i = 0; $i < Log::LIMIT + 5; $i++) {
            $log->add('demo', 'recorder', true, 'number ' . $i);
        }

        $entries = $log->entries();

        self::assertCount(Log::LIMIT, $entries);
        self::assertSame('number ' . (Log::LIMIT + 4), $entries[0]['message']);
    }

    public function test_the_log_can_be_cleared(): void
    {
        $log = new Log();
        $log->add('demo', 'recorder', true, 'hello');

        $log->clear();

        self::assertSame([], $log->entries());
    }

    /**
     * Queued payload for a delivery attempt.
     *
     * @return array<string, mixed>
     */
    private static function payload(string $channel_id, int $attempt = 1): array
    {
        return [
            'message' => Message::plain('demo', 'Hello')->to_array(),
            'channel' => $channel_id,
            'attempt' => $attempt,
        ];
    }

    /**
     * How many deliveries are waiting in the cron array.
     */
    private static function scheduled_count(): int
    {
        $count = 0;

        foreach ((array) _get_cron_array() as $hooks) {
            foreach ((array) $hooks as $hook => $events) {
                if (Queue::HOOK === $hook) {
                    $count += count((array) $events);
                }
            }
        }

        return $count;
    }
}
