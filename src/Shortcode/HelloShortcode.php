<?php
/**
 * Plugin shortcode.
 *
 * @package NotifyTelegram
 */

declare(strict_types=1);

namespace Wpseed\NotifyTelegram\Shortcode;

use Wpseed\NotifyTelegram\Greeting;
use Wpseed\NotifyTelegram\Plugin;

/**
 * Shortcode [notify_telegram_hello name="John" template="Hi, %s!"].
 *
 * The callback declares the attributes only: WordPress also passes $content and $tag,
 * but declaring unused parameters is optional (and phpcs flags them).
 */
final class HelloShortcode {

	/**
	 * Constructor.
	 *
	 * @param string $tag Shortcode tag.
	 */
	public function __construct( private readonly string $tag ) {
	}

	/**
	 * Registers the shortcode with WordPress.
	 *
	 * @return void
	 */
	public function register(): void {
		add_shortcode( $this->tag, array( $this, 'render' ) );
	}

	/**
	 * Renders the shortcode.
	 *
	 * @param array<string, mixed>|string $atts Shortcode attributes.
	 * @return string
	 */
	public function render( array|string $atts = array() ): string {
		$atts = shortcode_atts(
			array(
				'name'     => '',
				'template' => '',
			),
			is_array( $atts ) ? $atts : array(),
			$this->tag
		);

		$name     = (string) $atts['name'];
		$template = trim( (string) $atts['template'] );

		if ( '' !== $template ) {
			$greeting = Greeting::format( $name, $template );
		} else {
			$plugin   = Plugin::instance();
			$greeting = null !== $plugin ? $plugin->greeting( $name ) : Greeting::format( $name );
		}

		return sprintf( '<span class="notify-telegram-hello">%s</span>', esc_html( $greeting ) );
	}
}
