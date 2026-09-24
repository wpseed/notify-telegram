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

    public function test_a_short_text_is_one_message(): void
    {
        self::assertSame(['Hello'], TelegramChannel::split('Hello'));
    }

    public function test_an_empty_text_is_still_one_message(): void
    {
        self::assertSame([''], TelegramChannel::split(''));
    }

    public function test_long_text_is_split_at_line_boundaries_without_losing_anything(): void
    {
        $line = str_repeat('x', 99) . "\n";
        $text = rtrim(str_repeat($line, 80), "\n");

        $parts = TelegramChannel::split($text);

        self::assertGreaterThan(1, count($parts));

        foreach ($parts as $index => $part) {
            self::assertLessThanOrEqual(
                TelegramChannel::MAX_LENGTH,
                TelegramChannel::length($part),
                'part ' . $index . ' is longer than Telegram accepts'
            );
            self::assertStringEndsWith(str_repeat('x', 99), $part, 'part ' . $index . ' is cut inside a line');
        }

        self::assertSame(self::without_whitespace($text), self::without_whitespace(implode('', $parts)));
    }

    public function test_text_without_any_break_is_cut_at_the_limit_without_losing_anything(): void
    {
        $text = str_repeat('a', TelegramChannel::MAX_LENGTH * 2 + 10);

        $parts = TelegramChannel::split($text);

        self::assertCount(3, $parts);

        foreach ($parts as $index => $part) {
            self::assertLessThanOrEqual(TelegramChannel::MAX_LENGTH, TelegramChannel::length($part));
            self::assertSame($index === 2 ? 10 : TelegramChannel::MAX_LENGTH, mb_strlen($part));
        }

        self::assertSame($text, implode('', $parts));
    }

    public function test_an_emoji_counts_as_two_units(): void
    {
        self::assertSame(2, TelegramChannel::length('Hi'));
        self::assertSame(2, TelegramChannel::length('🙂'));
        self::assertSame(1, TelegramChannel::length('ы'));
    }

    public function test_emoji_are_not_split_in_half(): void
    {
        $text = str_repeat('🙂', 3000);

        $parts = TelegramChannel::split($text);

        self::assertGreaterThan(1, count($parts), '6000 units do not fit into one message');

        foreach ($parts as $index => $part) {
            self::assertLessThanOrEqual(TelegramChannel::MAX_LENGTH, TelegramChannel::length($part));
            self::assertTrue(mb_check_encoding($part, 'UTF-8'), 'part ' . $index . ' is not valid UTF-8');
            self::assertSame(
                str_repeat('🙂', mb_strlen($part)),
                $part,
                'part ' . $index . ' holds something other than whole emoji'
            );
        }

        self::assertSame($text, implode('', $parts));
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

    /**
     * The text with every whitespace character removed, which is how a split is checked for loss.
     */
    private static function without_whitespace(string $text): string
    {
        return (string) preg_replace('/\s+/', '', $text);
    }
}
