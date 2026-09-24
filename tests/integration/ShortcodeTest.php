<?php

declare(strict_types=1);

namespace Wpseed\NotifyTelegram\Tests\Integration;

use Wpseed\NotifyTelegram\Plugin;
use WP_UnitTestCase;

/**
 * Integration tests: WordPress is really loaded (wp-phpunit); covers the shortcode
 * and the filters.
 */
final class ShortcodeTest extends WP_UnitTestCase
{
    public function test_shortcode_is_registered_by_the_plugin(): void
    {
        self::assertTrue(shortcode_exists(Plugin::SHORTCODE));
    }

    public function test_shortcode_renders_the_greeting(): void
    {
        $html = do_shortcode('[notify_telegram_hello name="John"]');

        self::assertStringContainsString('notify-telegram-hello', $html);
        self::assertStringContainsString('John', $html);
    }

    public function test_shortcode_template_attribute_overrides_the_option(): void
    {
        update_option(Plugin::OPTION_TEMPLATE, 'Hello, %s!');

        $html = do_shortcode('[notify_telegram_hello name="Jane" template="Hi, %s!"]');

        self::assertStringContainsString('Hi, Jane!', $html);
    }

    public function test_template_filter_changes_the_output(): void
    {
        add_filter(Plugin::FILTER_TEMPLATE, static fn (): string => 'Howdy, %s!');

        self::assertStringContainsString('Howdy, John!', do_shortcode('[notify_telegram_hello name="John"]'));
    }

    public function test_output_is_escaped(): void
    {
        $html = do_shortcode('[notify_telegram_hello name="<script>alert(1)</script>"]');

        self::assertStringNotContainsString('<script>', $html);
    }
}
