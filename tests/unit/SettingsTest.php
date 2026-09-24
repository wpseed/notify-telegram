<?php

declare(strict_types=1);

namespace Wpseed\NotifyTelegram\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Wpseed\NotifyTelegram\Settings\Settings;

/**
 * Normalizing stored settings (the read path, which must survive a hand-edited option).
 */
final class SettingsTest extends TestCase
{
    public function test_normalize_fills_in_the_defaults(): void
    {
        self::assertSame(Settings::defaults(), Settings::normalize([]));
    }

    public function test_master_switch_defaults_to_on_and_follows_a_stored_false(): void
    {
        self::assertTrue(Settings::normalize([])['enabled']);
        self::assertFalse(Settings::normalize(['enabled' => false])['enabled']);
        self::assertFalse(Settings::normalize(['enabled' => 0])['enabled']);
    }

    public function test_normalize_trims_string_values_and_keeps_toggles_boolean(): void
    {
        $normalized = Settings::normalize([
            'channels' => [
                'telegram' => [
                    'enabled' => false,
                    'token' => "  123:abc\n",
                    'chat_ids' => ' -1001 ',
                    'junk' => ['nested'],
                ],
            ],
        ]);

        self::assertSame(
            ['enabled' => false, 'token' => '123:abc', 'chat_ids' => '-1001'],
            $normalized['channels']['telegram']
        );
    }

    public function test_normalize_drops_a_channel_that_is_not_an_array(): void
    {
        $normalized = Settings::normalize(['channels' => ['telegram' => 'nope']]);

        self::assertSame([], $normalized['channels']);
    }

    public function test_normalize_casts_event_toggles(): void
    {
        $normalized = Settings::normalize(['events' => ['user_registered' => '1', 'comment_posted' => '']]);

        self::assertTrue($normalized['events']['user_registered']);
        self::assertFalse($normalized['events']['comment_posted']);
    }

    public function test_normalize_keeps_template_overrides_as_strings(): void
    {
        $normalized = Settings::normalize(['templates' => ['user_registered' => ' Hi %user_login% ']]);

        self::assertSame('Hi %user_login%', $normalized['templates']['user_registered']);
    }
}
