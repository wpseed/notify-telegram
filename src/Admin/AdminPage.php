<?php
/**
 * The plugin's admin screen: a top-level menu with the Events and Settings pages.
 *
 * @package NotifyTelegram
 */

declare(strict_types=1);

namespace Wpseed\NotifyTelegram\Admin;

use Wpseed\NotifyTelegram\Plugin;
use Wpseed\NotifyTelegram\Rest\SettingsController;

/**
 * Registers the menu and boots the React application on both of its pages.
 *
 * The screen itself is rendered in JavaScript: PHP prints the mount element on each page, hands the
 * application its configuration (REST root, nonce, version, the page it is on) and enqueues the built
 * bundle from assets/admin/. Without the bundle the page shows a developer notice instead of nothing.
 *
 * The first submenu reuses the parent slug, which is how WordPress is told to label the first entry of
 * the submenu instead of adding a second one: the menu reads "Events" and "Settings", and the top-level
 * entry itself opens Events.
 */
final class AdminPage {

	/**
	 * Top-level menu slug, which is also the Events page.
	 */
	public const MENU_SLUG = 'notify-telegram';

	/**
	 * Settings page slug.
	 */
	public const SETTINGS_SLUG = 'notify-telegram-settings';

	/**
	 * Script and style handle.
	 */
	public const HANDLE = 'notify-telegram-admin';

	/**
	 * Id of the element the application mounts into.
	 */
	public const MOUNT_ID = 'notify-telegram-admin-root';

	/**
	 * Capability required for the menu and the pages.
	 */
	public const CAPABILITY = 'manage_options';

	/**
	 * Built bundle manifest, relative to the plugin directory.
	 */
	private const MANIFEST = 'assets/admin/.vite/manifest.json';

	/**
	 * Entry key inside that manifest.
	 */
	private const MANIFEST_ENTRY = 'admin-ui/main.jsx';

	/**
	 * Hook suffixes of the screens the bundle belongs on.
	 *
	 * @var array<int, string>
	 */
	private array $screens = array();

	/**
	 * Constructor.
	 *
	 * @param string $file Absolute path to the main plugin file.
	 */
	public function __construct( private readonly string $file ) {
	}

	/**
	 * Registers the admin hooks.
	 *
	 * The hooks are registered unconditionally — admin_menu and admin_enqueue_scripts only fire in an
	 * admin request anyway, and an is_admin() guard would make them impossible to trigger in tests.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Adds the top-level menu and its two pages.
	 *
	 * @return void
	 */
	public function add_menu(): void {
		$top_level = add_menu_page(
			__( 'Events', 'notify-telegram' ),
			__( 'Notify Telegram', 'notify-telegram' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render' ),
			'dashicons-format-chat',
			81
		);

		// The parent slug is reused for the first item on purpose. Core adds a link back to the parent
		// only when the submenu is still empty *and* the slug differs, so without this line the first
		// entry would be labelled "Notify Telegram" and point at Events; with it the menu reads exactly
		// "Events" and "Settings".
		$events = add_submenu_page(
			self::MENU_SLUG,
			__( 'Events', 'notify-telegram' ),
			__( 'Events', 'notify-telegram' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render' )
		);

		$settings = add_submenu_page(
			self::MENU_SLUG,
			__( 'Settings', 'notify-telegram' ),
			__( 'Settings', 'notify-telegram' ),
			self::CAPABILITY,
			self::SETTINGS_SLUG,
			array( $this, 'render' )
		);

		// The Events entry reuses the parent slug, so core names its hook exactly like the top-level
		// page; the duplicate is dropped here rather than enqueued twice.
		$this->screens = array_values(
			array_unique( array_filter( array( $top_level, $events, $settings ), 'is_string' ) )
		);
	}

	/**
	 * Enqueues the built bundle — on the plugin's own pages only.
	 *
	 * @param string $hook_suffix Current admin screen.
	 * @return void
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( ! in_array( $hook_suffix, $this->screens, true ) ) {
			return;
		}

		$manifest = $this->manifest();

		if ( null === $manifest ) {
			add_action( 'admin_notices', array( $this, 'render_missing_assets_notice' ) );

			return;
		}

		$base_url = plugin_dir_url( $this->file ) . 'assets/admin/';

		foreach ( $manifest['css'] as $index => $stylesheet ) {
			wp_enqueue_style( self::HANDLE . '-style-' . $index, $base_url . $stylesheet, array(), Plugin::VERSION );
		}

		wp_enqueue_script( self::HANDLE, $base_url . $manifest['file'], array(), Plugin::VERSION, true );

		// wp_add_inline_script() keeps the types of the values; wp_localize_script() would turn
		// everything into strings.
		wp_add_inline_script(
			self::HANDLE,
			'window.notifyTelegramAdmin = ' . wp_json_encode( $this->config( $hook_suffix ) ) . ';',
			'before'
		);
	}

	/**
	 * Configuration handed to the React application.
	 *
	 * @param string $hook_suffix Current admin screen.
	 * @return array<string, mixed>
	 */
	public function config( string $hook_suffix ): array {
		return array(
			'apiRoot'      => esc_url_raw( rest_url( SettingsController::REST_NAMESPACE ) ),
			'nonce'        => wp_create_nonce( 'wp_rest' ),
			'version'      => Plugin::VERSION,
			'page'         => $hook_suffix === $this->settings_screen() ? 'settings' : 'events',
			// The application keeps the address bar in step with the open page, so a reload comes back
			// to it and the WordPress menu highlights it.
			'eventsSlug'   => self::MENU_SLUG,
			'settingsSlug' => self::SETTINGS_SLUG,
		);
	}

	/**
	 * Hook suffix of the Settings page.
	 *
	 * Asked of core rather than taken from the screen list by position: the Events entry reuses the
	 * parent slug, so core names its hook exactly like the top-level page and the list holds no simple
	 * second entry.
	 *
	 * @return string
	 */
	private function settings_screen(): string {
		return (string) get_plugin_page_hookname( self::SETTINGS_SLUG, self::MENU_SLUG );
	}

	/**
	 * Entry of the built bundle, or null when the assets have not been built yet.
	 *
	 * @return array{file: string, css: array<int, string>}|null
	 */
	private function manifest(): ?array {
		$path = plugin_dir_path( $this->file ) . self::MANIFEST;

		if ( ! is_readable( $path ) ) {
			return null;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions -- a local build artefact, not a remote request.
		$decoded = json_decode( (string) file_get_contents( $path ), true );

		$entry = is_array( $decoded ) ? ( $decoded[ self::MANIFEST_ENTRY ] ?? null ) : null;

		if ( ! is_array( $entry ) || ! isset( $entry['file'] ) || ! is_string( $entry['file'] ) ) {
			return null;
		}

		$css = $entry['css'] ?? array();

		return array(
			'file' => $entry['file'],
			'css'  => is_array( $css ) ? array_values( array_filter( $css, 'is_string' ) ) : array(),
		);
	}

	/**
	 * Tells the developer that the bundle has not been built.
	 *
	 * @return void
	 */
	public function render_missing_assets_notice(): void {
		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html__(
				'Notify Telegram: the admin bundle is missing. Run "npm install && npm run build" in the plugin directory.',
				'notify-telegram'
			)
		);
	}

	/**
	 * Prints the mount element of the application.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php echo esc_html__( 'Notify Telegram', 'notify-telegram' ); ?></h1>
			<hr class="wp-header-end" />
			<p class="description">
				<?php
				printf(
					/* translators: %s: plugin version number. */
					esc_html__( 'Notifications for WordPress events, delivered to the channels you configure. Version %s.', 'notify-telegram' ),
					esc_html( Plugin::VERSION )
				);
				?>
			</p>
			<div id="<?php echo esc_attr( self::MOUNT_ID ); ?>">
				<noscript>
					<?php echo esc_html__( 'This screen needs JavaScript to work.', 'notify-telegram' ); ?>
				</noscript>
			</div>
		</div>
		<?php
	}
}
