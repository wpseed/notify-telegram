<?php
/**
 * Greeting logic free of WordPress dependencies.
 *
 * @package NotifyTelegram
 */

declare(strict_types=1);

namespace Wpseed\NotifyTelegram;

/**
 * Builds the greeting string.
 *
 * The class deliberately avoids WordPress functions so it can be covered by fast
 * unit tests (see tests/unit).
 */
final class Greeting {

	/**
	 * Default greeting template.
	 */
	public const DEFAULT_TEMPLATE = 'Hello, %s!';

	/**
	 * Placeholder name used when none is given.
	 */
	public const FALLBACK_NAME = 'world';

	/**
	 * Substitutes the name into a template.
	 *
	 * @param string $name     Name to substitute.
	 * @param string $template Template containing the %s placeholder.
	 * @return string
	 */
	public static function format( string $name, string $template = self::DEFAULT_TEMPLATE ): string {
		$template = trim( $template );

		if ( '' === $template ) {
			$template = self::DEFAULT_TEMPLATE;
		}

		if ( ! str_contains( $template, '%s' ) ) {
			$template .= ' %s';
		}

		$name = trim( $name );
		$name = '' !== $name ? $name : self::FALLBACK_NAME;

		try {
			return sprintf( $template, $name );
		} catch ( \Throwable ) {
			// A template stored outside the plugin's own screens (WP-CLI, a migration, an import) can
			// still be a broken sprintf() format; a greeting must not take the whole site down.
			return sprintf( self::DEFAULT_TEMPLATE, $name );
		}
	}
}
