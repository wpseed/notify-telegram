<?php

declare(strict_types=1);

namespace Wpseed\NotifyTelegram\Tests\Integration;

use Wpseed\NotifyTelegram\Delivery\Log;
use Wpseed\NotifyTelegram\Plugin;
use Wpseed\NotifyTelegram\Rest\SettingsController;
use Wpseed\NotifyTelegram\Settings\Settings;
use Wpseed\NotifyTelegram\Tests\Fakes\RecordingChannel;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * The REST routes the admin application works through.
 */
final class SettingsRestTest extends WP_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();

        delete_option(Settings::OPTION);
        delete_option(Log::OPTION);

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        do_action('rest_api_init', rest_get_server());
    }

    public function test_a_subscriber_cannot_read_the_state(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));

        $response = rest_do_request(self::request('GET', SettingsController::ROUTE_SETTINGS));

        self::assertSame(403, $response->get_status());
    }

    public function test_the_state_lists_the_channels_with_their_fields(): void
    {
        $data = self::data(self::request('GET', SettingsController::ROUTE_SETTINGS));

        self::assertSame(['telegram', 'email', 'webhook'], array_column($data['channels'], 'id'));
        self::assertSame('Telegram', $data['channels'][0]['label']);
        self::assertFalse($data['channels'][0]['configured']);
        self::assertSame(['token', 'chat_ids'], array_column($data['channels'][0]['fields'], 'name'));
        self::assertSame('textarea', $data['channels'][0]['fields'][1]['type']);
    }

    public function test_the_state_lists_the_events_with_their_placeholders(): void
    {
        $data = self::data(self::request('GET', SettingsController::ROUTE_SETTINGS));

        self::assertSame(['user_registered', 'user_login_failed', 'comment_posted'], array_column($data['events'], 'id'));
        self::assertTrue($data['enabled']);
        self::assertTrue($data['events'][0]['enabled']);
        self::assertSame('', $data['events'][0]['template']);
        self::assertContains('user_email', array_column($data['events'][0]['placeholders'], 'name'));
        self::assertNotSame('', $data['events'][0]['default_template']);
    }

    public function test_saving_stores_the_settings(): void
    {
        $response = rest_do_request(self::request('POST', SettingsController::ROUTE_SETTINGS, [
            'enabled' => false,
            'channels' => ['telegram' => ['enabled' => true, 'token' => ' 123:abc ', 'chat_ids' => '-1001']],
            'events' => ['comment_posted' => false],
            'templates' => ['user_registered' => 'Hi %user_login%'],
        ]));

        self::assertSame(200, $response->get_status());

        $stored = get_option(Settings::OPTION);

        self::assertFalse($stored['enabled']);
        self::assertSame('123:abc', $stored['channels']['telegram']['token']);
        self::assertFalse($stored['events']['comment_posted']);
        self::assertSame('Hi %user_login%', $stored['templates']['user_registered']);
    }

    public function test_saving_returns_the_stored_state(): void
    {
        $data = self::data(self::request('POST', SettingsController::ROUTE_SETTINGS, [
            'enabled' => true,
            'channels' => ['webhook' => ['urls' => 'https://example.com/hook']],
        ]));

        self::assertTrue($data['channels'][2]['configured']);
        self::assertSame('https://example.com/hook', $data['channels'][2]['fields'][0]['value']);
    }

    public function test_a_template_with_an_unknown_placeholder_is_rejected(): void
    {
        $response = rest_do_request(self::request('POST', SettingsController::ROUTE_SETTINGS, [
            'templates' => ['user_registered' => 'Hi %nmae%'],
        ]));

        self::assertSame(400, $response->get_status());
        self::assertStringContainsString('nmae', (string) $response->get_data()['message'] ?? '');
        self::assertFalse(get_option(Settings::OPTION), 'nothing may be stored when a template is rejected');
    }

    public function test_the_test_route_reports_every_configured_channel(): void
    {
        $plugin = Plugin::instance();
        $recorder = new RecordingChannel('recorder');

        try {
            $plugin->channels()->register($recorder);

            $data = self::data(self::request('POST', SettingsController::ROUTE_TEST));

            // The email channel is configured out of the box (it falls back to the site address), so the
            // recorder has to appear next to it rather than alone.
            self::assertContains('Recorder', array_column($data['results'], 'channel'));
            self::assertNotContains('Telegram', array_column($data['results'], 'channel'));
            self::assertStringContainsString('Test message from', $recorder->last_text());
            self::assertSame('test', $data['log'][0]['event']);
            self::assertSame('Recorder', $data['log'][0]['channel_label']);
            self::assertNotSame('', $data['log'][0]['date']);
        } finally {
            $plugin->channels()->unregister('recorder');
        }
    }

    public function test_the_log_route_empties_the_log(): void
    {
        $log = Plugin::instance()->log();
        $log->add('user_registered', 'webhook', true, 'Delivered.');

        $data = self::data(self::request('DELETE', SettingsController::ROUTE_LOG));

        self::assertSame([], $data['log']);
        self::assertSame([], $log->entries());
    }

    /**
     * A request against the plugin's namespace.
     *
     * @param string               $method HTTP method.
     * @param string               $route  Route below the namespace.
     * @param array<string, mixed> $body   JSON body.
     */
    private static function request(string $method, string $route, array $body = []): WP_REST_Request
    {
        $request = new WP_REST_Request($method, '/' . SettingsController::REST_NAMESPACE . $route);

        if ($body !== []) {
            $request->set_header('Content-Type', 'application/json');
            $request->set_body((string) wp_json_encode($body));
        }

        return $request;
    }

    /**
     * Response body as an array.
     *
     * @return array<string, mixed>
     */
    private static function data(WP_REST_Request $request): array
    {
        $response = rest_do_request($request);

        self::assertInstanceOf(WP_REST_Response::class, $response);
        self::assertSame(200, $response->get_status());

        $data = $response->get_data();

        return is_array($data) ? $data : [];
    }
}
