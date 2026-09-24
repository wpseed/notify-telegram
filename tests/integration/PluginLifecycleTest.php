<?php

declare(strict_types=1);

namespace Wpseed\NotifyTelegram\Tests\Integration;

use Wpseed\NotifyTelegram\Delivery\Log;
use Wpseed\NotifyTelegram\Delivery\Queue;
use Wpseed\NotifyTelegram\Plugin;
use Wpseed\NotifyTelegram\Settings\Settings;
use WP_UnitTestCase;

/**
 * Plugin lifecycle: loading, activation and what the plugin registers on init.
 */
final class PluginLifecycleTest extends WP_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();

        delete_option(Settings::OPTION);
        delete_option(Log::OPTION);
    }

    public function test_the_plugin_is_loaded_by_the_test_bootstrap(): void
    {
        self::assertNotNull(Plugin::instance(), 'The plugin must be loaded by the test bootstrap');
    }

    public function test_activation_adds_the_settings_option(): void
    {
        Plugin::instance()?->activate();

        self::assertSame(Settings::defaults(), get_option(Settings::OPTION));
    }

    public function test_activation_does_not_overwrite_stored_settings(): void
    {
        update_option(Settings::OPTION, ['enabled' => false]);

        Plugin::instance()?->activate();

        self::assertSame(['enabled' => false], get_option(Settings::OPTION));
    }

    public function test_the_shipped_events_are_registered(): void
    {
        self::assertSame(
            ['user_registered', 'user_login_failed', 'comment_posted'],
            array_keys(Plugin::instance()->events()->all())
        );
    }

    public function test_the_shipped_channels_are_registered(): void
    {
        self::assertSame(
            ['telegram', 'email', 'webhook'],
            array_keys(Plugin::instance()->channels()->all())
        );
    }

    public function test_the_delivery_hook_is_registered(): void
    {
        $queue = Plugin::instance()->queue();

        self::assertNotFalse(has_action(Queue::HOOK, [$queue, 'deliver']));
    }
}
