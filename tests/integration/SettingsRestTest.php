<?php

declare(strict_types=1);

namespace Wpseed\NotifyTelegram\Tests\Integration;

use Wpseed\NotifyTelegram\Greeting;
use Wpseed\NotifyTelegram\Plugin;
use Wpseed\NotifyTelegram\Rest\SettingsController;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * Integration tests for the REST route the admin application reads and writes the settings through.
 *
 * Requests carry the same X-WP-Nonce header the application sends, because WordPress requires it for
 * cookie authentication. That the header is really mandatory cannot be asserted here: the test
 * environment authenticates through the current-user global instead of cookies, so it is verified in
 * the browser against the live site.
 */
final class SettingsRestTest extends WP_UnitTestCase
{
    private const ROUTE = '/' . SettingsController::REST_NAMESPACE . SettingsController::ROUTE;

    public function test_the_route_is_registered(): void
    {
        self::assertArrayHasKey(self::ROUTE, rest_get_server()->get_routes());
    }

    public function test_anonymous_requests_are_refused(): void
    {
        wp_set_current_user(0);

        self::assertSame(401, $this->request('GET')->get_status());
    }

    public function test_subscribers_are_refused(): void
    {
        $this->signInAs('subscriber');

        self::assertSame(403, $this->request('GET')->get_status());
    }

    public function test_administrators_read_the_stored_settings(): void
    {
        $this->signInAs('administrator');
        update_option(Plugin::OPTION_TEMPLATE, 'Howdy, %s!');

        $response = $this->request('GET', [], true);

        self::assertSame(200, $response->get_status());
        self::assertSame(['template' => 'Howdy, %s!'], $response->get_data());
    }

    public function test_a_blank_option_is_reported_as_the_default_template(): void
    {
        $this->signInAs('administrator');
        update_option(Plugin::OPTION_TEMPLATE, '   ');

        $response = $this->request('GET', [], true);

        self::assertSame(['template' => Greeting::DEFAULT_TEMPLATE], $response->get_data());
    }

    public function test_administrators_store_a_new_template(): void
    {
        $this->signInAs('administrator');

        $response = $this->request('POST', ['template' => 'Hi, %s!'], true);

        self::assertSame(200, $response->get_status());
        self::assertSame('Hi, %s!', get_option(Plugin::OPTION_TEMPLATE));
        self::assertSame(['template' => 'Hi, %s!'], $response->get_data());
    }

    public function test_blank_input_falls_back_to_the_default_template(): void
    {
        $this->signInAs('administrator');

        $this->request('POST', ['template' => '   '], true);

        self::assertSame(Greeting::DEFAULT_TEMPLATE, get_option(Plugin::OPTION_TEMPLATE));
    }

    public function test_a_template_without_placeholder_is_accepted(): void
    {
        // Greeting::format() appends the placeholder when the template has none.
        $this->signInAs('administrator');

        $response = $this->request('POST', ['template' => 'Hello'], true);

        self::assertSame(200, $response->get_status());
        self::assertSame('Hello', get_option(Plugin::OPTION_TEMPLATE));
    }

    public function test_an_escaped_percent_sign_is_accepted(): void
    {
        $this->signInAs('administrator');

        $response = $this->request('POST', ['template' => '100%% %s'], true);

        self::assertSame(200, $response->get_status());
        self::assertSame('100%% %s', get_option(Plugin::OPTION_TEMPLATE));
    }

    /**
     * @dataProvider broken_templates
     */
    public function test_broken_templates_are_rejected_and_nothing_is_stored(string $template): void
    {
        $this->signInAs('administrator');
        update_option(Plugin::OPTION_TEMPLATE, Greeting::DEFAULT_TEMPLATE);

        $response = $this->request('POST', ['template' => $template], true);

        self::assertSame(400, $response->get_status());
        self::assertSame(
            Greeting::DEFAULT_TEMPLATE,
            get_option(Plugin::OPTION_TEMPLATE),
            'A rejected template must not reach the option'
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function broken_templates(): array
    {
        return [
            'two placeholders'  => ['%s and %s'],
            'foreign specifier' => ['Hi %d!'],
            'lone percent sign' => ['100% sure, %s'],
        ];
    }

    private function signInAs(string $role): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => $role]));
    }

    /**
     * Dispatches a request against the plugin route.
     *
     * @param string $method    HTTP method.
     * @param array  $params    Request parameters.
     * @param bool   $withNonce Whether to send the nonce the application sends.
     */
    private function request(string $method, array $params = [], bool $withNonce = false): WP_REST_Response
    {
        $request = new WP_REST_Request($method, self::ROUTE);

        foreach ($params as $name => $value) {
            $request->set_param($name, $value);
        }

        if ($withNonce) {
            $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
        }

        $response = rest_do_request($request);
        self::assertInstanceOf(WP_REST_Response::class, $response);

        return $response;
    }
}
