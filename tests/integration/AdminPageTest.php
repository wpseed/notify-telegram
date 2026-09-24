<?php

declare(strict_types=1);

namespace Wpseed\NotifyTelegram\Tests\Integration;

use Wpseed\NotifyTelegram\Admin\AdminPage;
use Wpseed\NotifyTelegram\Plugin;
use WP_UnitTestCase;

/**
 * The plugin menu: one top-level entry, no submenu, and the tabs inside the page it opens.
 */
final class AdminPageTest extends WP_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        wp_dequeue_script(AdminPage::HANDLE);

        // A tab requested by one test must not leak into the next one.
        unset($_GET[AdminPage::TAB_ARG]);

        $GLOBALS['menu'] = [];
        $GLOBALS['submenu'] = [];

        do_action('admin_menu');
    }

    public function test_the_top_level_menu_is_registered(): void
    {
        self::assertContains(AdminPage::MENU_SLUG, self::menu_slugs());
    }

    public function test_the_menu_entry_is_labelled_notify_telegram(): void
    {
        self::assertSame('Notify Telegram', self::menu_label(AdminPage::MENU_SLUG));
    }

    public function test_the_menu_icon_is_a_base64_svg_carrying_its_own_palette(): void
    {
        $icon = self::menu_icon(AdminPage::MENU_SLUG);
        $prefix = 'data:image/svg+xml;base64,';

        // Core renders exactly this form as a `.wp-menu-image.svg` background image and never recolours
        // it, which is what keeps the icon's own colours in every admin colour scheme.
        self::assertSame($prefix, substr($icon, 0, strlen($prefix)));

        $svg = (string) base64_decode(substr($icon, strlen($prefix)), true);

        self::assertStringContainsString('<svg', $svg);

        preg_match_all('/fill="([^"]+)"/', $svg, $matches);

        // Three shapes, two colours of our own: core keeps whatever the SVG declares, which is the
        // whole point of shipping an icon as a data URI instead of a dashicon.
        self::assertSame(['#229ED9', '#ffffff', '#ffffff'], $matches[1]);
    }

    public function test_the_entry_sits_directly_under_the_dashboard(): void
    {
        self::assertSame(AdminPage::MENU_POSITION, self::menu_position(AdminPage::MENU_SLUG));
    }

    public function test_the_menu_has_no_submenu_items(): void
    {
        // The point of the single entry: WordPress prints the submenu list only when the parent slug
        // holds entries (wp-admin/menu-header.php), so an empty one is what keeps the sidebar free of
        // an unfolded list.
        self::assertArrayNotHasKey(AdminPage::MENU_SLUG, $GLOBALS['submenu']);
    }

    public function test_the_screen_is_reachable_as_a_page(): void
    {
        self::assertArrayHasKey(self::hook_suffix(), $GLOBALS['_registered_pages']);
    }

    public function test_the_page_is_silent_for_a_user_without_the_capability(): void
    {
        // Core drops the entry from the sidebar in wp-admin/menu.php, which filters $menu by each
        // item's capability; what this class owes on top of that is a screen that prints nothing.
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));

        $page = new AdminPage(Plugin::instance()->file());

        ob_start();
        $page->render();
        $html = (string) ob_get_clean();

        self::assertSame('', $html);
    }

    public function test_the_page_prints_the_mount_element(): void
    {
        $page = new AdminPage(Plugin::instance()->file());

        ob_start();
        $page->render();
        $html = (string) ob_get_clean();

        self::assertStringContainsString('id="' . AdminPage::MOUNT_ID . '"', $html);
        self::assertStringContainsString('notify-telegram-admin', $html);
    }

    public function test_the_screen_opens_the_first_tab_by_default(): void
    {
        self::assertSame('events', self::config()['tab']);
    }

    public function test_the_tab_comes_from_the_request(): void
    {
        $_GET[AdminPage::TAB_ARG] = 'settings';

        self::assertSame('settings', self::config()['tab']);
    }

    public function test_an_unknown_tab_falls_back_to_the_first_one(): void
    {
        $_GET[AdminPage::TAB_ARG] = 'not-a-tab';

        self::assertSame('events', self::config()['tab']);
    }

    public function test_the_configuration_carries_the_rest_root_and_the_nonce(): void
    {
        $config = self::config();

        self::assertStringContainsString('notify-telegram/v1', (string) $config['apiRoot']);
        self::assertNotSame('', (string) $config['nonce']);
        self::assertSame(Plugin::VERSION, $config['version']);
    }

    public function test_the_bundle_is_enqueued_on_the_plugin_screen(): void
    {
        self::skipWithoutBundle();

        do_action('admin_enqueue_scripts', self::hook_suffix());

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
     * The configuration the screen is booted with.
     *
     * @return array<string, mixed>
     */
    private static function config(): array
    {
        return (new AdminPage(Plugin::instance()->file()))->config();
    }

    /**
     * Hook suffix WordPress builds for the plugin's top-level page.
     */
    private static function hook_suffix(): string
    {
        return (string) get_plugin_page_hookname(AdminPage::MENU_SLUG, '');
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
     * Slugs of the registered top-level menu entries.
     *
     * @return list<string>
     */
    private static function menu_slugs(): array
    {
        return array_values(array_map(
            static fn (array $item): string => (string) ($item[2] ?? ''),
            is_array($GLOBALS['menu']) ? $GLOBALS['menu'] : []
        ));
    }

    /**
     * Label of one top-level menu entry.
     */
    private static function menu_label(string $slug): string
    {
        foreach (is_array($GLOBALS['menu']) ? $GLOBALS['menu'] : [] as $item) {
            if (($item[2] ?? '') === $slug) {
                return (string) ($item[0] ?? '');
            }
        }

        return '';
    }

    /**
     * Icon of one top-level menu entry: a dashicon class or a data URI.
     */
    private static function menu_icon(string $slug): string
    {
        foreach (is_array($GLOBALS['menu']) ? $GLOBALS['menu'] : [] as $item) {
            if (($item[2] ?? '') === $slug) {
                return (string) ($item[6] ?? '');
            }
        }

        return '';
    }

    /**
     * Key the entry was registered under, which is the position in the menu.
     */
    private static function menu_position(string $slug): int|float|null
    {
        foreach (is_array($GLOBALS['menu']) ? $GLOBALS['menu'] : [] as $key => $item) {
            if (($item[2] ?? '') === $slug) {
                return is_int($key) || is_float($key) ? $key : (int) $key;
            }
        }

        return null;
    }
}
