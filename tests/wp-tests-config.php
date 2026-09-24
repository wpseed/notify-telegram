<?php

/**
 * WordPress test installation config for wp-phpunit.
 *
 * The defaults match the lab (a Bedrock install with MySQL in Docker); every value can
 * be overridden with an environment variable.
 */

declare(strict_types=1);

// --- Database (a separate schema; tables are recreated on every run) ---
define('DB_NAME', getenv('WP_TESTS_DB_NAME') ?: 'wordpress_tests');
define('DB_USER', getenv('WP_TESTS_DB_USER') ?: 'root');
define('DB_PASSWORD', getenv('WP_TESTS_DB_PASSWORD') ?: 'root');
define('DB_HOST', getenv('WP_TESTS_DB_HOST') ?: '127.0.0.1:3306');
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');

$table_prefix = 'wptests_';

// --- WordPress core ---
$core_dir = getenv('WP_CORE_DIR');
$core_dir = $core_dir !== false ? $core_dir : '';

if ($core_dir === '') {
    foreach ([
        dirname(__DIR__, 5) . '/web/wp', // web/app/plugins/<plugin>/tests → project root
        dirname(__DIR__, 4) . '/web/wp',
    ] as $candidate) {
        if (is_readable($candidate . '/wp-settings.php')) {
            $core_dir = realpath($candidate);
            break;
        }
    }
}

if ($core_dir === '') {
    fwrite(STDERR, "WordPress core not found. Set WP_CORE_DIR (for example, C:/projects/wordpress/web/wp).\n");
    exit(1);
}

define('ABSPATH', rtrim($core_dir, '/\\') . '/');

// --- Content directory: the same one the Bedrock dev environment uses (web/app) ---
define('WP_CONTENT_DIR', dirname(__DIR__, 4) . '/app');
define('WP_CONTENT_URL', 'https://wordpress.test/app');
define('WP_PLUGIN_DIR', WP_CONTENT_DIR . '/plugins');
define('WPMU_PLUGIN_DIR', WP_CONTENT_DIR . '/mu-plugins');

// --- Everything else ---
define('WP_TESTS_DOMAIN', 'wordpress.test');
define('WP_TESTS_EMAIL', 'admin@wordpress.test');
define('WP_TESTS_TITLE', 'WordPress Plugin Lab');
define('WP_PHP_BINARY', getenv('WP_PHP_BINARY') ?: PHP_BINARY);
define('WP_DEFAULT_THEME', 'twentytwentyfive');

// Debugging inside tests: errors are reported without breaking the plugin output.
define('WP_DEBUG', true);
define('WP_DEBUG_DISPLAY', false);
