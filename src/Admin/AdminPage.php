<?php
/**
 * The plugin's React admin screen.
 *
 * @package NotifyTelegram
 */

declare(strict_types=1);

namespace Wpseed\NotifyTelegram\Admin;

use Wpseed\NotifyTelegram\Greeting;
use Wpseed\NotifyTelegram\Plugin;
use Wpseed\NotifyTelegram\Rest\SettingsController;

/**
 * Registers a top-level admin page and boots the React application on it.
 *
 * The screen itself is rendered in JavaScript: PHP prints the mount element, hands the application
 * its configuration (REST root, nonce, defaults) and enqueues the built bundle from assets/admin/.
 * The bundle is built with `npm run build`; without it the screen shows a developer notice instead of
 * a blank page.
 */
final class AdminPage {

	/**
	 * Menu slug of the screen.
	 */
	public const MENU_SLUG = 'notify-telegram-admin';

	/**
	 * Script and style handle.
	 */
	public const HANDLE = 'notify-telegram-admin';

	/**
	 * Id of the element the React application mounts into.
	 */
	public const MOUNT_ID = 'notify-telegram-admin-root';

	/**
	 * Built bundle manifest, relative to the plugin directory.
	 */
	private const MANIFEST = 'assets/admin/.vite/manifest.json';

	/**
	 * Entry key inside that manifest.
	 */
	private const MANIFEST_ENTRY = 'admin-ui/main.jsx';

	/**
	 * Hook suffix of the screen, as returned by add_menu_page().
	 *
	 * @var string
	 */
	private string $hook_suffix = '';

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
	 * Adds the top-level menu entry.
	 *
	 * @return void
	 */
	public function add_menu(): void {
		$hook = add_menu_page(
			__( 'Notify Telegram', 'notify-telegram' ),
			__( 'Notify Telegram', 'notify-telegram' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render' ),
			'dashicons-format-chat',
			81
		);

		$this->hook_suffix = is_string( $hook ) ? $hook : '';
	}

	/**
	 * Enqueues the built bundle — on the plugin's own screen only.
	 *
	 * @param string $hook_suffix Current admin screen.
	 * @return void
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( '' === $this->hook_suffix || $hook_suffix !== $this->hook_suffix ) {
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
			'window.starterPluginAdmin = ' . wp_json_encode( $this->config() ) . ';',
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
			'apiRoot'    => esc_url_raw( rest_url( SettingsController::REST_NAMESPACE ) ),
			'nonce'      => wp_create_nonce( 'wp_rest' ),
			'version'    => Plugin::VERSION,
			'shortcode'  => Plugin::SHORTCODE,
			'sampleName' => 'John',
			'defaults'   => array( 'template' => Greeting::DEFAULT_TEMPLATE ),
		);
	}

	/**
	 * Entry of the built bundle, or null when the assets have not been built yet.
	 *
	 * @return array{file: string, css: list<string>}|null
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
	 * Prints the mount element of the React application.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php echo esc_html__( 'Notify Telegram', 'notify-telegram' ); ?></h1>
			<div id="<?php echo esc_attr( self::MOUNT_ID ); ?>">
				<noscript>
					<?php echo esc_html__( 'This screen needs JavaScript to work.', 'notify-telegram' ); ?>
				</noscript>
			</div>
		</div>
		<?php
	}
}
