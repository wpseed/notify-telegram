<?php

/**
 * Bootstrap for the plugin integration tests.
 *
 * Loads the official WordPress test suite (wp-phpunit) and the plugin itself the way
 * WordPress does on startup (the muplugins_loaded filter).
 *
 * Environment variables that override the defaults:
 *   WP_TESTS_DIR              path to wp-phpunit (default: vendor/wp-phpunit/wp-phpunit)
 *   WP_TESTS_CONFIG_FILE_PATH path to wp-tests-config.php (default: tests/wp-tests-config.php)
 *   WP_CORE_DIR               path to the WordPress core (default: the lab's web/wp)
 */

declare(strict_types=1);

$tests_dir = getenv('WP_TESTS_DIR');
$tests_dir = $tests_dir !== false ? $tests_dir : '';

if ($tests_dir === '') {
    $candidates = [
        __DIR__ . '/../vendor/wp-phpunit/wp-phpunit',             // composer install inside the plugin
        __DIR__ . '/../../../../../vendor/wp-phpunit/wp-phpunit',  // Bedrock's root vendor
    ];

    foreach ($candidates as $candidate) {
        if (is_readable($candidate . '/includes/functions.php')) {
            $tests_dir = realpath($candidate);
            break;
        }
    }
}

if ($tests_dir === '' || ! is_readable($tests_dir . '/includes/bootstrap.php')) {
    fwrite(
        STDERR,
        "WordPress test suite not found.\n" .
        "Run `composer install` in the plugin directory or point WP_TESTS_DIR at it.\n"
    );
    exit(1);
}

// The WordPress test suite looks up its config through a constant (not an environment
// variable) and defaults to a path inside vendor/wp-phpunit, so we set ours explicitly.
if (! defined('WP_TESTS_CONFIG_FILE_PATH')) {
    define('WP_TESTS_CONFIG_FILE_PATH', __DIR__ . '/wp-tests-config.php');
}

require_once $tests_dir . '/includes/functions.php';

/**
 * The plugin has to be loaded before WordPress boots, exactly like a mu-plugin.
 */
tests_add_filter('muplugins_loaded', static function (): void {
    require dirname(__DIR__) . '/notify-telegram.php';
});

require $tests_dir . '/includes/bootstrap.php';
