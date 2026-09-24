<?php
/**
 * Plugin entry point: singleton, wiring, activation.
 *
 * @package NotifyTelegram
 */

declare(strict_types=1);

namespace Wpseed\NotifyTelegram;

use Wpseed\NotifyTelegram\Admin\SettingsPage;
use Wpseed\NotifyTelegram\Channel\Channel;
use Wpseed\NotifyTelegram\Channel\ChannelRegistry;
use Wpseed\NotifyTelegram\Channel\EmailChannel;
use Wpseed\NotifyTelegram\Channel\TelegramChannel;
use Wpseed\NotifyTelegram\Channel\WebhookChannel;
use Wpseed\NotifyTelegram\Delivery\Log;
use Wpseed\NotifyTelegram\Delivery\Queue;
use Wpseed\NotifyTelegram\Delivery\Router;
use Wpseed\NotifyTelegram\Event\CommentEvents;
use Wpseed\NotifyTelegram\Event\EventRegistry;
use Wpseed\NotifyTelegram\Event\EventSource;
use Wpseed\NotifyTelegram\Event\UserEvents;
use Wpseed\NotifyTelegram\Settings\Settings;

/**
 * Main plugin class: builds the objects and hands them their hooks.
 *
 * Everything the plugin knows is reachable through the accessors below, which is what the tests
 * use to add a channel of their own, fire an event and read the log.
 */
final class Plugin {

	/**
	 * Plugin version.
	 */
	public const VERSION = '0.2.0';

	/**
	 * Text domain.
	 */
	public const TEXTDOMAIN = 'notify-telegram';

	/**
	 * Filter: the list of channels.
	 */
	public const FILTER_CHANNELS = 'notify_telegram_channels';

	/**
	 * Filter: the list of event sources.
	 */
	public const FILTER_SOURCES = 'notify_telegram_event_sources';

	/**
	 * Loaded plugin instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Settings.
	 *
	 * @var Settings|null
	 */
	private ?Settings $settings = null;

	/**
	 * Channels.
	 *
	 * @var ChannelRegistry|null
	 */
	private ?ChannelRegistry $channels = null;

	/**
	 * Events.
	 *
	 * @var EventRegistry|null
	 */
	private ?EventRegistry $events = null;

	/**
	 * Delivery log.
	 *
	 * @var Log|null
	 */
	private ?Log $log = null;

	/**
	 * Delivery queue.
	 *
	 * @var Queue|null
	 */
	private ?Queue $queue = null;

	/**
	 * Router.
	 *
	 * @var Router|null
	 */
	private ?Router $router = null;

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
	 * Settings object.
	 *
	 * @return Settings
	 */
	public function settings(): Settings {
		return $this->settings ?? new Settings();
	}

	/**
	 * Channel registry.
	 *
	 * @return ChannelRegistry
	 */
	public function channels(): ChannelRegistry {
		return $this->channels ?? new ChannelRegistry();
	}

	/**
	 * Event registry.
	 *
	 * @return EventRegistry
	 */
	public function events(): EventRegistry {
		return $this->events ?? new EventRegistry();
	}

	/**
	 * Delivery log.
	 *
	 * @return Log
	 */
	public function log(): Log {
		return $this->log ?? new Log();
	}

	/**
	 * Delivery queue.
	 *
	 * @return Queue
	 */
	public function queue(): Queue {
		return $this->queue ?? new Queue( $this->channels(), $this->log() );
	}

	/**
	 * Router.
	 *
	 * @return Router
	 */
	public function router(): Router {
		return $this->router ?? new Router( $this->settings(), $this->channels(), $this->events(), $this->queue(), $this->log() );
	}

	/**
	 * Registers the WordPress hooks.
	 *
	 * The hooks are registered unconditionally: admin_menu, admin_init and the delivery cron hook
	 * only fire in their own context anyway, so an is_admin() guard would be noise — and, more
	 * importantly for tests, it would make these hooks impossible to trigger by hand.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		$this->settings = new Settings();
		$this->log      = new Log();
		$this->channels = new ChannelRegistry( $this->create_channels() );
		$this->events   = new EventRegistry();
		$this->queue    = new Queue( $this->channels, $this->log );
		$this->router   = new Router( $this->settings, $this->channels, $this->events, $this->queue, $this->log );

		$this->queue->register();

		// The sources are declared on init: an event's title and its default message are translated,
		// and loading a text domain before init is an error since WordPress 6.7.
		add_action( 'init', array( $this, 'register_sources' ) );

		( new SettingsPage( $this->settings, $this->channels, $this->events, $this->log ) )->register();

		register_activation_hook( $this->file, array( $this, 'activate' ) );
	}

	/**
	 * Called by WordPress when the plugin is activated.
	 *
	 * @return void
	 */
	public function activate(): void {
		if ( false === get_option( Settings::OPTION ) ) {
			add_option( Settings::OPTION, Settings::defaults() );
		}
	}

	/**
	 * Channels the plugin ships with, plus whatever other code adds.
	 *
	 * @return array<int, Channel>
	 */
	private function create_channels(): array {
		$channels = array(
			new TelegramChannel( $this->settings ),
			new EmailChannel( $this->settings ),
			new WebhookChannel( $this->settings ),
		);

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- the prefixed hook name lives in a constant.
		$filtered = (array) apply_filters( self::FILTER_CHANNELS, $channels, $this->settings );

		return array_values( array_filter( $filtered, static fn ( mixed $channel ): bool => $channel instanceof Channel ) );
	}

	/**
	 * Declares the events and hooks them to WordPress.
	 *
	 * @return void
	 */
	public function register_sources(): void {
		$sources = array(
			new UserEvents(),
			new CommentEvents(),
		);

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- the prefixed hook name lives in a constant.
		$filtered = (array) apply_filters( self::FILTER_SOURCES, $sources, $this->events, $this->router );

		foreach ( $filtered as $source ) {
			if ( $source instanceof EventSource ) {
				$source->register( $this->events, $this->router );
			}
		}
	}
}
