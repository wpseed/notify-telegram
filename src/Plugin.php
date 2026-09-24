<?php
/**
 * Plugin entry point: singleton, hooks, settings, activation.
 *
 * @package NotifyTelegram
 */

declare(strict_types=1);

namespace Wpseed\NotifyTelegram;

use Wpseed\NotifyTelegram\Admin\AdminPage;
use Wpseed\NotifyTelegram\Admin\SettingsPage;
use Wpseed\NotifyTelegram\Rest\SettingsController;
use Wpseed\NotifyTelegram\Shortcode\HelloShortcode;

/**
 * Main plugin class.
 */
final class Plugin {

	/**
	 * Plugin version.
	 */
	public const VERSION = '0.1.0';

	/**
	 * Shortcode tag.
	 */
	public const SHORTCODE = 'notify_telegram_hello';

	/**
	 * Option holding the greeting template.
	 */
	public const OPTION_TEMPLATE = 'notify_telegram_template';

	/**
	 * Filter that overrides the greeting template.
	 */
	public const FILTER_TEMPLATE = 'notify_telegram_template';

	/**
	 * Text domain.
	 */
	public const TEXTDOMAIN = 'notify-telegram';

	/**
	 * Loaded plugin instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Constructor.
	 *
	 * @param string $file Absolute path to the main plugin file.
	 */
	private function __construct( private readonly string $file ) {
	}

	/**
	 * Creates the single instance and registers the hooks.
	 *
	 * @param string $file Absolute path to the main plugin file.
	 * @return self
	 */
	public static function boot( string $file ): self {
		if ( null === self::$instance ) {
			self::$instance = new self( $file );
			self::$instance->register_hooks();
		}

		return self::$instance;
	}

	/**
	 * Already loaded instance (null when the plugin has not been loaded).
	 *
	 * @return self|null
	 */
	public static function instance(): ?self {
		return self::$instance;
	}

	/**
	 * Absolute path to the main plugin file.
	 *
	 * @return string
	 */
	public function file(): string {
		return $this->file;
	}

	/**
	 * Registers the WordPress hooks.
	 *
	 * The hooks are registered unconditionally: admin_menu and admin_init only fire in
	 * an admin context, so an is_admin() guard is unnecessary — and, more importantly
	 * for tests, it would make these hooks impossible to trigger by hand.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		$shortcode = new HelloShortcode( self::SHORTCODE );
		$settings  = new SettingsPage();
		$admin     = new AdminPage( $this->file );
		$rest      = new SettingsController();

		add_action( 'init', array( $shortcode, 'register' ) );
		$settings->register();
		$admin->register();
		$rest->register();

		register_activation_hook( $this->file, array( $this, 'activate' ) );
	}

	/**
	 * Called by WordPress when the plugin is activated.
	 *
	 * @return void
	 */
	public function activate(): void {
		if ( false === get_option( self::OPTION_TEMPLATE ) ) {
			add_option( self::OPTION_TEMPLATE, Greeting::DEFAULT_TEMPLATE );
		}
	}

	/**
	 * Greeting template from the settings, passed through the filter.
	 *
	 * @return string
	 */
	public function template(): string {
		$template = get_option( self::OPTION_TEMPLATE, Greeting::DEFAULT_TEMPLATE );
		$template = is_string( $template ) && '' !== trim( $template ) ? $template : Greeting::DEFAULT_TEMPLATE;

		// The hook name lives in a constant: the sniff cannot resolve its value, although the prefix is in it.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound
		return (string) apply_filters( self::FILTER_TEMPLATE, $template );
	}

	/**
	 * Ready greeting for the given name.
	 *
	 * @param string $name Name.
	 * @return string
	 */
	public function greeting( string $name = '' ): string {
		return Greeting::format( $name, $this->template() );
	}
}
