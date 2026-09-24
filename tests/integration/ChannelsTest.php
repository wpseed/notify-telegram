<?php

declare(strict_types=1);

namespace Wpseed\NotifyTelegram\Tests\Integration;

use Wpseed\NotifyTelegram\Channel\EmailChannel;
use Wpseed\NotifyTelegram\Channel\TelegramChannel;
use Wpseed\NotifyTelegram\Channel\WebhookChannel;
use Wpseed\NotifyTelegram\Message;
use Wpseed\NotifyTelegram\Settings\Settings;
use WP_Error;
use WP_UnitTestCase;

/**
 * The three channels, with WordPress' HTTP and mail functions stubbed out.
 */
final class ChannelsTest extends WP_UnitTestCase
{
    /**
     * Requests the stubbed HTTP layer saw.
     *
     * @var list<array{url: string, args: array<string, mixed>}>
     */
    private array $requests = [];

    public function set_up(): void
    {
        parent::set_up();

        delete_option(Settings::OPTION);
        $this->requests = [];
    }

    public function test_telegram_send_posts_the_message_to_every_chat(): void
    {
        $this->stub_http(['body' => '{"ok":true,"result":{"message_id":1}}']);

        $result = self::telegram("-1001\n-1002")->send(Message::plain('demo', 'Hello'));

        self::assertTrue($result->is_ok());
        self::assertCount(2, $this->requests);
        self::assertStringContainsString('https://api.telegram.org/bot123:abc/sendMessage', $this->requests[0]['url']);
        self::assertSame(
            ['chat_id' => '-1001', 'text' => 'Hello', 'disable_web_page_preview' => true],
            json_decode((string) $this->requests[0]['args']['body'], true)
        );
    }

    public function test_telegram_reports_a_rejected_message(): void
    {
        $this->stub_http([
            'body' => '{"ok":false,"description":"Bad Request: chat not found"}',
            'response' => ['code' => 400, 'message' => 'Bad Request'],
        ]);

        $result = self::telegram('-1001')->send(Message::plain('demo', 'Hello'));

        self::assertFalse($result->is_ok());
        self::assertStringContainsString('chat not found', $result->error());
        self::assertStringContainsString('-1001', $result->error());
    }

    public function test_telegram_reports_a_transport_error(): void
    {
        add_filter('pre_http_request', static fn (): WP_Error => new WP_Error('http_request_failed', 'cURL error 6'), 10, 3);

        $result = self::telegram('-1001')->send(Message::plain('demo', 'Hello'));

        self::assertFalse($result->is_ok());
        self::assertStringContainsString('cURL error 6', $result->error());
    }

    public function test_email_send_uses_wp_mail_with_the_valid_addresses_only(): void
    {
        $captured = null;
        add_filter('pre_wp_mail', static function ($preempt, array $atts) use (&$captured): bool {
            $captured = $atts;

            return true;
        }, 10, 2);

        update_option(Settings::OPTION, [
            'channels' => ['email' => ['recipients' => "owner@example.com\nnot-an-address, second@example.com"]],
        ]);

        $result = (new EmailChannel(new Settings()))->send(Message::plain('demo', 'Hello', 'Demo'));

        self::assertTrue($result->is_ok());
        self::assertSame(['owner@example.com', 'second@example.com'], $captured['to']);
        self::assertStringContainsString('Demo', (string) $captured['subject']);
    }

    public function test_email_reports_a_failed_wp_mail(): void
    {
        add_filter('pre_wp_mail', '__return_false');

        update_option(Settings::OPTION, [
            'channels' => ['email' => ['recipients' => 'owner@example.com']],
        ]);

        $result = (new EmailChannel(new Settings()))->send(Message::plain('demo', 'Hello'));

        self::assertFalse($result->is_ok());
        self::assertStringContainsString('wp_mail', $result->error());
    }

    public function test_webhook_send_posts_json_and_signs_the_body(): void
    {
        $this->stub_http([]);

        update_option(Settings::OPTION, [
            'channels' => ['webhook' => ['urls' => 'https://example.com/hook', 'secret' => 's3cret']],
        ]);

        $result = (new WebhookChannel(new Settings()))->send(Message::plain('demo', 'Hello', 'Demo'));

        self::assertTrue($result->is_ok());

        $body = (string) $this->requests[0]['args']['body'];
        $payload = json_decode($body, true);

        self::assertSame('demo', $payload['event']);
        self::assertSame('Demo', $payload['subject']);
        self::assertSame('Hello', $payload['text']);
        self::assertSame(
            'sha256=' . hash_hmac('sha256', $body, 's3cret'),
            $this->requests[0]['args']['headers'][WebhookChannel::SIGNATURE_HEADER]
        );
    }

    public function test_webhook_reports_a_failing_endpoint(): void
    {
        $this->stub_http(['body' => 'go away', 'response' => ['code' => 500, 'message' => 'Server Error']]);

        update_option(Settings::OPTION, [
            'channels' => ['webhook' => ['urls' => 'https://example.com/hook']],
        ]);

        $result = (new WebhookChannel(new Settings()))->send(Message::plain('demo', 'Hello'));

        self::assertFalse($result->is_ok());
        self::assertStringContainsString('HTTP 500', $result->error());
        self::assertStringContainsString('go away', $result->error());
    }

    public function test_webhook_ignores_a_value_that_is_not_a_url(): void
    {
        update_option(Settings::OPTION, [
            'channels' => ['webhook' => ['urls' => 'not a url']],
        ]);

        $channel = new WebhookChannel(new Settings());

        self::assertFalse($channel->is_configured());
        self::assertSame([], $channel->urls());
    }

    /**
     * Telegram channel reading a stored token and chat list.
     */
    private static function telegram(string $chat_ids): TelegramChannel
    {
        update_option(Settings::OPTION, [
            'channels' => ['telegram' => ['token' => '123:abc', 'chat_ids' => $chat_ids]],
        ]);

        $channel = new TelegramChannel(new Settings());

        self::assertTrue($channel->is_configured());

        return $channel;
    }

    /**
     * Answers every HTTP request with the given response.
     *
     * @param array<string, mixed> $response Parts overriding a 200 with an empty body.
     */
    private function stub_http(array $response): void
    {
        add_filter('pre_http_request', function ($preempt, $args, $url) use ($response) {
            $this->requests[] = ['url' => (string) $url, 'args' => (array) $args];

            return array_merge(
                [
                    'headers' => [],
                    'body' => '',
                    'cookies' => [],
                    'filename' => null,
                    'response' => ['code' => 200, 'message' => 'OK'],
                ],
                $response
            );
        }, 10, 3);
    }
}
