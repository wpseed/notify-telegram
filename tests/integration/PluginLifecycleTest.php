<?php

declare(strict_types=1);

namespace Wpseed\NotifyTelegram\Tests\Integration;

use Wpseed\NotifyTelegram\Admin\SettingsPage;
use Wpseed\NotifyTelegram\Delivery\Log;
use Wpseed\NotifyTelegram\Plugin;
use Wpseed\NotifyTelegram\Settings\Settings;
use Wpseed\NotifyTelegram\Tests\Fakes\RecordingChannel;
use WP_UnitTestCase;

/**
 * Plugin lifecycle: loading, activation, the settings screen and its test button.
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

    public function test_settings_page_is_registered_under_the_settings_menu(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $GLOBALS['submenu'] = [];

        do_action('admin_menu');

        self::assertContains(SettingsPage::MENU_SLUG, self::submenu_slugs());
    }

    public function test_settings_page_is_not_registered_for_subscribers(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));
        $GLOBALS['submenu'] = [];

        do_action('admin_menu');

        self::assertNotContains(SettingsPage::MENU_SLUG, self::submenu_slugs());
    }

    public function test_a_template_with_an_unknown_placeholder_is_rejected(): void
    {
        $clean = self::page()->sanitize(['templates' => ['user_registered' => 'Hi %nmae%']]);

        self::assertNotSame('Hi %nmae%', $clean['templates']['user_registered']);
    }

    public function test_a_valid_template_is_kept(): void
    {
        $clean = self::page()->sanitize(['templates' => ['user_registered' => 'Hi %user_login%']]);

        self::assertSame('Hi %user_login%', $clean['templates']['user_registered']);
    }

    public function test_sanitize_strips_markup_from_channel_fields(): void
    {
        $clean = self::page()->sanitize([
            'channels' => ['telegram' => ['token' => '<b>123:abc</b>', 'enabled' => '1']],
        ]);

        self::assertSame('123:abc', $clean['channels']['telegram']['token']);
        self::assertTrue($clean['channels']['telegram']['enabled']);
    }

    public function test_the_test_button_reports_when_no_channel_is_configured(): void
    {
        self::assertSame([], self::page()->run_test());
    }

    public function test_the_test_button_sends_through_a_configured_channel(): void
    {
        $plugin = Plugin::instance();
        $recorder = new RecordingChannel('recorder');

        try {
            $plugin->channels()->register($recorder);

            $results = self::page()->run_test();

            self::assertCount(1, $results);
            self::assertTrue($results[0]['ok']);
            self::assertStringContainsString('Test message from', $recorder->last_text());
            self::assertSame('test', $plugin->log()->entries()[0]['event']);
        } finally {
            $plugin->channels()->unregister('recorder');
        }
    }

    /**
     * Settings screen built on the objects the plugin booted with.
     */
    private static function page(): SettingsPage
    {
        $plugin = Plugin::instance();

        return new SettingsPage($plugin->settings(), $plugin->channels(), $plugin->events(), $plugin->log());
    }

    /**
     * @return list<string>
     */
    private static function submenu_slugs(): array
    {
        $items = $GLOBALS['submenu']['options-general.php'] ?? [];

        return array_values(array_filter(array_map(
            static fn (array $item): string => (string) ($item[2] ?? ''),
            is_array($items) ? $items : []
        )));
    }
}
