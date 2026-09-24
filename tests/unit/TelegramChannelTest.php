<?php

declare(strict_types=1);

namespace Wpseed\NotifyTelegram\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Wpseed\NotifyTelegram\Channel\TelegramChannel;

/**
 * The parts of the Telegram channel that decide what leaves the site.
 */
final class TelegramChannelTest extends TestCase
{
    public function test_payload_sends_plain_text_without_previews(): void
    {
        self::assertSame(
            [
                'chat_id' => '-1001',
                'text' => 'Hello',
                'disable_web_page_preview' => true,
            ],
            TelegramChannel::payload('-1001', 'Hello')
        );
    }

    public function test_short_text_is_not_touched(): void
    {
        self::assertSame('Hello', TelegramChannel::truncate('Hello'));
    }

    public function test_long_text_is_cut_to_the_telegram_limit(): void
    {
        $truncated = TelegramChannel::truncate(str_repeat('a', TelegramChannel::MAX_LENGTH + 50));

        self::assertSame(TelegramChannel::MAX_LENGTH, mb_strlen($truncated));
        self::assertStringEndsWith('...', $truncated);
    }

    public function test_chat_ids_are_split_on_commas_and_newlines(): void
    {
        self::assertSame(
            ['-1001', '@channel', '-1002'],
            TelegramChannel::parse_list(" -1001, @channel\n-1002 -1001 ")
        );
    }

    public function test_empty_chat_ids_give_an_empty_list(): void
    {
        self::assertSame([], TelegramChannel::parse_list("  \n "));
    }
}
