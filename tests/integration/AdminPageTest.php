<?php

declare(strict_types=1);

namespace Wpseed\NotifyTelegram\Tests\Integration;

use Wpseed\NotifyTelegram\Admin\AdminPage;
use Wpseed\NotifyTelegram\Plugin;
use WP_UnitTestCase;

/**
 * The plugin menu: one top-level entry with the Events and Settings pages, and the bundle on both.
 */
final class AdminPageTest extends WP_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        wp_dequeue_script(AdminPage::HANDLE);

        $GLOBALS['menu'] = [];
        $GLOBALS['submenu'] = [];

        do_action('admin_menu');
    }

    public function test_the_top_level_menu_is_registered(): void
    {
        $slugs = array_map(
            static fn (array $item): string => (string) ($item[2] ?? ''),
            is_array($GLOBALS['menu']) ? $GLOBALS['menu'] : []
        );

        self::assertContains(AdminPage::MENU_SLUG, $slugs);
    }

    public function test_the_menu_has_exactly_the_events_and_settings_pages(): void
    {
        self::assertSame(
            [AdminPage::MENU_SLUG, AdminPage::SETTINGS_SLUG],
            self::submenu_slugs()
        );
    }

    public function test_the_two_pages_are_labelled_events_and_settings(): void
    {
        $labels = array_map(
            static fn (array $item): string => (string) ($item[0] ?? ''),
            is_array($GLOBALS['submenu'][AdminPage::MENU_SLUG] ?? null)
                ? $GLOBALS['submenu'][AdminPage::MENU_SLUG]
                : []
        );

        self::assertSame(['Events', 'Settings'], $labels);
    }

    public function test_a_subscriber_gets_no_menu(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));
        $GLOBALS['menu'] = [];
        $GLOBALS['submenu'] = [];

        do_action('admin_menu');

        self::assertSame([], self::submenu_slugs());
    }

    public function test_both_pages_print_the_mount_element(): void
    {
        $page = new AdminPage(Plugin::instance()->file());

        ob_start();
        $page->render();
        $html = (string) ob_get_clean();

        self::assertStringContainsString('id="' . AdminPage::MOUNT_ID . '"', $html);
        self::assertStringContainsString('notify-telegram-admin', $html);
    }

    public function test_the_configuration_tells_the_application_which_page_it_is_on(): void
    {
        $page = new AdminPage(Plugin::instance()->file());
        $page->add_menu();

        $events = $page->config(self::hook_suffix(AdminPage::MENU_SLUG, ''));
        $settings = $page->config(self::hook_suffix(AdminPage::SETTINGS_SLUG, AdminPage::MENU_SLUG));

        self::assertSame('events', $events['page']);
        self::assertSame('settings', $settings['page']);
        self::assertSame(AdminPage::MENU_SLUG, $events['eventsSlug']);
        self::assertSame(AdminPage::SETTINGS_SLUG, $settings['settingsSlug']);
        self::assertStringContainsString('notify-telegram/v1', (string) $events['apiRoot']);
        self::assertNotSame('', (string) $events['nonce']);
    }

    public function test_the_bundle_is_enqueued_on_the_plugin_pages(): void
    {
        self::skipWithoutBundle();

        do_action('admin_enqueue_scripts', self::hook_suffix(AdminPage::MENU_SLUG, ''));

        self::assertTrue(wp_script_is(AdminPage::HANDLE, 'enqueued'));
    }

    public function test_the_bundle_is_not_enqueued_on_other_screens(): void
    {
        do_action('admin_enqueue_scripts', 'edit.php');

        self::assertFalse(wp_script_is(AdminPage::HANDLE, 'enqueued'));
    }

    public function test_a_missing_bundle_shows_a_notice_instead_of_an_empty_page(): void
    {
        $page = new AdminPage(Plugin::instance()->file());

        ob_start();
        $page->render_missing_assets_notice();
        $html = (string) ob_get_clean();

        self::assertStringContainsString('npm run build', $html);
    }

    /**
     * Hook suffix WordPress builds for a page.
     */
    private static function hook_suffix(string $slug, string $parent): string
    {
        return (string) get_plugin_page_hookname($slug, $parent);
    }

    /**
     * The tests need the built bundle; a fresh clone has none until "npm run build" runs.
     */
    private static function skipWithoutBundle(): void
    {
        if (! is_readable(dirname(__DIR__, 2) . '/assets/admin/.vite/manifest.json')) {
            self::markTestSkipped('The admin bundle is not built (run "npm run build").');
        }
    }

    /**
     * @return list<string>
     */
    private static function submenu_slugs(): array
    {
        $items = $GLOBALS['submenu'][AdminPage::MENU_SLUG] ?? [];

        return array_values(array_filter(array_map(
            static fn (array $item): string => (string) ($item[2] ?? ''),
            is_array($items) ? $items : []
        )));
    }
}
