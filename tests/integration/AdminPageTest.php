<?php

declare(strict_types=1);

namespace Wpseed\NotifyTelegram\Tests\Integration;

use Wpseed\NotifyTelegram\Admin\AdminPage;
use Wpseed\NotifyTelegram\Greeting;
use Wpseed\NotifyTelegram\Plugin;
use WP_UnitTestCase;

/**
 * Integration tests for the plugin's React admin screen: the menu entry, the bundle it enqueues and
 * the element the application mounts into.
 *
 * The tests drive the real wiring: the plugin registers the hooks, so they fire admin_menu — exactly
 * what WordPress does in an admin request — instead of calling the page object by hand.
 */
final class AdminPageTest extends WP_UnitTestCase
{
    private const MANIFEST = __DIR__ . '/../../assets/admin/.vite/manifest.json';

    private const SCREEN_HOOK = 'toplevel_page_' . AdminPage::MENU_SLUG;

    private function signInAs(string $role): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => $role]));
    }

    /**
     * The screen is instantiated with the path of the main plugin file, exactly like the plugin does.
     */
    private function page(): AdminPage
    {
        $plugin = Plugin::instance();
        self::assertNotNull($plugin, 'The plugin must be loaded by the test bootstrap');

        return new AdminPage($plugin->file());
    }

    /**
     * The admin menu globals are null outside an admin request. WordPress 7.1 enqueues its command
     * palette on admin_enqueue_scripts and walks both arrays, so a test that fires that hook has to
     * provide both — with only $menu set, core throws "array_key_exists(): ... null given".
     */
    private function resetAdminMenu(): void
    {
        $GLOBALS['menu'] = [];
        $GLOBALS['submenu'] = [];
    }

    public function test_the_menu_entry_is_registered_for_administrators(): void
    {
        $this->signInAs('administrator');
        $this->resetAdminMenu();

        do_action('admin_menu');

        self::assertContains(AdminPage::MENU_SLUG, self::menuSlugs());
    }

    public function test_the_page_callback_is_attached_for_administrators(): void
    {
        $this->signInAs('administrator');
        $this->resetAdminMenu();

        do_action('admin_menu');

        self::assertTrue(has_action(self::SCREEN_HOOK));
    }

    public function test_the_page_callback_is_not_attached_for_subscribers(): void
    {
        // add_menu_page() registers the menu entry for every user — WordPress hides it while rendering
        // when the capability does not match. The capability decides whether the callback is attached,
        // and that is what actually keeps the screen away from everybody else.
        $this->signInAs('subscriber');
        $this->resetAdminMenu();

        do_action('admin_menu');

        self::assertFalse(has_action(self::SCREEN_HOOK));
    }

    public function test_the_bundle_is_enqueued_on_the_plugin_screen(): void
    {
        if (!is_readable(self::MANIFEST)) {
            self::markTestSkipped('The admin bundle is not built: run npm install && npm run build first.');
        }

        $this->signInAs('administrator');
        $this->resetAdminMenu();
        wp_dequeue_script(AdminPage::HANDLE);

        do_action('admin_menu');
        do_action('admin_enqueue_scripts', self::SCREEN_HOOK);

        self::assertTrue(wp_script_is(AdminPage::HANDLE, 'enqueued'));

        // The application reads its REST root, nonce and defaults from this inline configuration.
        self::assertStringContainsString('starterPluginAdmin', self::inlineData());

        $config = self::inlineConfig();
        self::assertStringContainsString('notify-telegram/v1', (string) $config['apiRoot']);
        self::assertNotEmpty($config['nonce']);
    }

    public function test_the_bundle_is_not_enqueued_on_other_screens(): void
    {
        $this->signInAs('administrator');
        $this->resetAdminMenu();
        wp_dequeue_script(AdminPage::HANDLE);

        do_action('admin_menu');
        do_action('admin_enqueue_scripts', 'edit.php');

        self::assertFalse(
            wp_script_is(AdminPage::HANDLE, 'enqueued'),
            'The bundle must not be loaded on screens that do not host the application'
        );
    }

    public function test_the_screen_prints_the_mount_element(): void
    {
        $this->signInAs('administrator');

        ob_start();
        $this->page()->render();
        $html = (string) ob_get_clean();

        self::assertStringContainsString('id="' . AdminPage::MOUNT_ID . '"', $html);
    }

    public function test_the_screen_prints_nothing_for_users_without_the_capability(): void
    {
        $this->signInAs('subscriber');

        ob_start();
        $this->page()->render();
        $html = (string) ob_get_clean();

        self::assertSame('', $html);
    }

    public function test_the_configuration_carries_the_rest_root_nonce_and_defaults(): void
    {
        $this->signInAs('administrator');

        $config = $this->page()->config();

        self::assertStringContainsString('notify-telegram/v1', (string) $config['apiRoot']);
        self::assertNotEmpty($config['nonce']);
        self::assertSame(Plugin::SHORTCODE, $config['shortcode']);
        self::assertSame(Greeting::DEFAULT_TEMPLATE, $config['defaults']['template']);
    }

    /**
     * Slugs of the top-level menu items, read the way WordPress' own tests read them.
     *
     * @return list<string>
     */
    private static function menuSlugs(): array
    {
        $items = $GLOBALS['menu'] ?? [];

        return array_values(array_filter(array_map(
            static fn (array $item): string => (string) ($item[2] ?? ''),
            is_array($items) ? $items : []
        )));
    }

    /**
     * The inline script WordPress prints before the bundle.
     */
    private static function inlineData(): string
    {
        $data = wp_scripts()->get_data(AdminPage::HANDLE, 'before');

        return is_array($data) ? implode("\n", $data) : (string) $data;
    }

    /**
     * The same script, decoded the way the browser will read it.
     *
     * wp_add_inline_script() prints JSON, which escapes slashes and appends a semicolon, so tests must
     * parse it rather than look for URL fragments in the raw text.
     *
     * @return array<string, mixed>
     */
    private static function inlineConfig(): array
    {
        $data = self::inlineData();
        $json = rtrim(substr($data, (int) strpos($data, '{')), ";\n\r\t ");

        $decoded = json_decode($json, true);
        self::assertIsArray($decoded, 'The inline script must print a JSON object');

        return $decoded;
    }
}
