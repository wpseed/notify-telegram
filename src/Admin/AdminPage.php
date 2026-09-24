<?php
/**
 * The plugin's admin screen: one menu entry, with both pages inside it.
 *
 * @package NotifyTelegram
 */

declare(strict_types=1);

namespace Wpseed\NotifyTelegram\Admin;

use Wpseed\NotifyTelegram\Plugin;
use Wpseed\NotifyTelegram\Rest\SettingsController;

/**
 * Registers the menu and boots the React application on it.
 *
 * There is deliberately no submenu. WordPress prints the submenu list only when `$submenu` holds
 * entries for the parent slug (`wp-admin/menu-header.php`), so a lone `add_menu_page()` leaves the menu
 * with one plain link and nothing to unfold on hover. Events and Settings are therefore tabs inside the
 * page, and a tab stays addressable: the application keeps the `tab` query argument in step with the
 * open tab, so a reload and a shared link both come back to the same one.
 *
 * The screen itself is rendered in JavaScript: PHP prints the mount element, hands the application its
 * configuration (REST root, nonce, version, the tab to open) and enqueues the built bundle from
 * assets/admin/. Without the bundle the page shows a developer notice instead of nothing.
 */
final class AdminPage {

	/**
	 * Top-level menu slug.
	 */
	public const MENU_SLUG = 'notify-telegram';

	/**
	 * Tabs of the screen, in the order they are rendered.
	 *
	 * @var array<int, string>
	 */
	public const TABS = array( 'events', 'settings' );

	/**
	 * Query argument that opens a tab.
	 */
	public const TAB_ARG = 'tab';

	/**
	 * Position of the entry in the admin menu.
	 *
	 * WordPress puts its own sections at 2 (Dashboard), 4 (a separator), 5 (Posts), 10 (Media),
	 * 20 (Pages), 25 (Comments), 59 (a separator), 60 (Appearance), 65 (Plugins), 70 (Users),
	 * 75 (Tools), 80 (Settings) and 99 (a separator), and a plugin that passes no position at all
	 * (null) is appended to the last group. 75.9 keeps this entry in its own slot under Tools and
	 * above Settings, and above everything else that already claimed 76; move the number to move the
	 * entry — 3 is directly under Dashboard, 59.9 just above Appearance.
	 *
	 * A position another plugin has taken costs nothing: core checks the key first and, when it is
	 * taken, adds a small fraction to the later registration (`wp-admin/includes/plugin.php`), so both
	 * entries stay in the menu. Positions are kept as string keys for exactly that reason, which is
	 * why this constant is a float.
	 */
	public const MENU_POSITION = 75.9;

	/**
	 * Menu icon: the core bell, not an image of our own.
	 *
	 * A dashicon, so the entry is styled by the admin colour scheme the way every other entry in the
	 * sidebar is: core paints it through `div.wp-menu-image:before` (and restyles it on hover and when
	 * the screen is open), which an icon of our own could only imitate.
	 */
	private const ICON = 'dashicons-bell';

	/**
	 * Script and style handle.
	 */
	public const HANDLE = 'notify-telegram-admin';

	/**
	 * Id of the element the application mounts into.
	 */
	public const MOUNT_ID = 'notify-telegram-admin-root';

	/**
	 * Capability required for the menu and the page.
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
	 * Adds the plugin's single menu entry.
	 *
	 * @return void
	 */
	public function add_menu(): void {
		$top_level = add_menu_page(
			__( 'Notify Telegram', 'notify-telegram' ),
			__( 'Notify Telegram', 'notify-telegram' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render' ),
			self::ICON,
			self::MENU_POSITION
		);

		// No add_submenu_page() call on purpose. Adding one — even one that reuses the parent slug —
		// is exactly what gives the entry a submenu list in the sidebar, and the two pages belong
		// inside the page as tabs, so the menu keeps a single plain link.
		$this->screens = is_string( $top_level ) ? array( $top_level ) : array();
	}

	/**
	 * Enqueues the built bundle — on the plugin's own screen only.
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
			'window.notifyTelegramAdmin = ' . wp_json_encode( $this->config() ) . ';',
			'before'
		);
	}

	/**
	 * Configuration handed to the React application.
	 *
	 * @return array<string, mixed>
	 */
	public function config(): array {
		return array(
			'apiRoot' => esc_url_raw( rest_url( SettingsController::REST_NAMESPACE ) ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
			'version' => Plugin::VERSION,
			'tab'     => $this->initial_tab(),
		);
	}

	/**
	 * Tab the screen opens on, taken from the request.
	 *
	 * Only the two known tabs pass: a stale link or a hand-typed value falls back to the first one
	 * instead of rendering a screen with nothing selected.
	 *
	 * @return string
	 */
	public function initial_tab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a view switch, not an action.
		$requested = isset( $_GET[ self::TAB_ARG ] ) ? sanitize_key( wp_unslash( (string) $_GET[ self::TAB_ARG ] ) ) : '';

		return in_array( $requested, self::TABS, true ) ? $requested : self::TABS[0];
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
