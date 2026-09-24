<?php

declare(strict_types=1);

namespace Wpseed\NotifyTelegram\Tests\Integration;

use Wpseed\NotifyTelegram\Admin\SettingsPage;
use Wpseed\NotifyTelegram\Greeting;
use Wpseed\NotifyTelegram\Plugin;
use WP_UnitTestCase;

/**
 * Integration tests for the plugin lifecycle: activation, settings, registration of
 * the admin page.
 */
final class PluginLifecycleTest extends WP_UnitTestCase
{
    public function test_activation_adds_the_default_template_option(): void
    {
        delete_option(Plugin::OPTION_TEMPLATE);

        $plugin = Plugin::instance();
        self::assertNotNull($plugin, 'The plugin must be loaded by the test bootstrap');

        $plugin->activate();

        self::assertSame(Greeting::DEFAULT_TEMPLATE, get_option(Plugin::OPTION_TEMPLATE));
    }

    public function test_activation_does_not_overwrite_existing_option(): void
    {
        update_option(Plugin::OPTION_TEMPLATE, 'Hi, %s!');

        Plugin::instance()?->activate();

        self::assertSame('Hi, %s!', get_option(Plugin::OPTION_TEMPLATE));
    }

    public function test_greeting_uses_the_stored_option(): void
    {
        update_option(Plugin::OPTION_TEMPLATE, 'Howdy, %s!');

        self::assertSame('Howdy, Jane!', Plugin::instance()?->greeting('Jane'));
    }

    public function test_blank_option_falls_back_to_default_template(): void
    {
        update_option(Plugin::OPTION_TEMPLATE, '   ');

        self::assertSame(Greeting::format('John'), Plugin::instance()?->greeting('John'));
    }

    public function test_settings_page_is_registered_under_settings_menu(): void
    {
        // add_submenu_page() returns false when the current user lacks the capability,
        // so the test has to log in as an administrator explicitly.
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $GLOBALS['submenu'] = [];

        (new SettingsPage())->register();
        do_action('admin_menu');

        // Submenu items are stored under numeric keys with the slug in element [2],
        // so we look the slug up by value — the way WordPress' own tests do it.
        self::assertContains(SettingsPage::MENU_SLUG, self::settingsSubmenuSlugs());
    }

    public function test_settings_page_is_not_registered_for_subscribers(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));
        $GLOBALS['submenu'] = [];

        (new SettingsPage())->register();
        do_action('admin_menu');

        self::assertNotContains(SettingsPage::MENU_SLUG, self::settingsSubmenuSlugs());
    }

    /**
     * @return list<string>
     */
    private static function settingsSubmenuSlugs(): array
    {
        $items = $GLOBALS['submenu']['options-general.php'] ?? [];

        return array_values(array_filter(array_map(
            static fn (array $item): string => (string) ($item[2] ?? ''),
            is_array($items) ? $items : []
        )));
    }

    public function test_sanitize_callback_replaces_blank_input(): void
    {
        $page = new SettingsPage();

        self::assertSame(Greeting::DEFAULT_TEMPLATE, $page->sanitize('  '));
        self::assertSame('Hi, %s!', $page->sanitize(' Hi, %s! '));
    }
}
