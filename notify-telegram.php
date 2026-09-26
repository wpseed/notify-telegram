<?php
/**
 * Plugin Name:       Notify Telegram
 * Description:       Notifications for WordPress events, delivered to the channels you configure: Telegram,
 *                    email or any incoming webhook. Messages, toggles and the delivery log live under
 *                    Settings → Notify Telegram.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      8.2
 * Author:            Plugin Lab
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       notify-telegram
 *
 * @package NotifyTelegram
 */

declare(strict_types=1);

namespace Wpseed\NotifyTelegram;

defined( 'ABSPATH' ) || exit;

$notify_telegram_autoloader = __DIR__ . '/vendor/autoload.php';

if ( is_readable( $notify_telegram_autoloader ) ) {
	require_once $notify_telegram_autoloader;
}

Plugin::boot( __FILE__ );
