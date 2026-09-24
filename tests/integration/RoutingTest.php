<?php

declare(strict_types=1);

namespace Wpseed\NotifyTelegram\Tests\Integration;

use Wpseed\NotifyTelegram\Channel\ChannelRegistry;
use Wpseed\NotifyTelegram\Delivery\Log;
use Wpseed\NotifyTelegram\Delivery\Queue;
use Wpseed\NotifyTelegram\Delivery\Router;
use Wpseed\NotifyTelegram\Event\CommentEvents;
use Wpseed\NotifyTelegram\Event\Event;
use Wpseed\NotifyTelegram\Event\EventRegistry;
use Wpseed\NotifyTelegram\Event\UserEvents;
use Wpseed\NotifyTelegram\Settings\Settings;
use Wpseed\NotifyTelegram\Tests\Fakes\RecordingChannel;
use WP_UnitTestCase;

/**
 * From an event to a channel: the toggles, the template and the real WordPress hooks.
 */
final class RoutingTest extends WP_UnitTestCase
{
    private RecordingChannel $recorder;

    private EventRegistry $events;

    private Router $router;

    private Log $log;

    private ChannelRegistry $channels;

    public function set_up(): void
    {
        parent::set_up();

        delete_option(Settings::OPTION);
        delete_option(Log::OPTION);

        // Deliver inside the request: assertions then see the result without running cron.
        add_filter('notify_telegram_send_async', '__return_false');

        $this->recorder = new RecordingChannel('recorder');
        $this->log = new Log();
        $this->channels = new ChannelRegistry([$this->recorder]);
        $this->events = new EventRegistry();
        $this->router = new Router(
            new Settings(),
            $this->channels,
            $this->events,
            new Queue($this->channels, $this->log),
            $this->log
        );

        $this->events->register(new Event('demo', 'Demo', ['name' => 'Name'], 'Hello %name%'));
    }

    public function tear_down(): void
    {
        remove_filter('notify_telegram_send_async', '__return_false');

        parent::tear_down();
    }

    public function test_a_registered_event_reaches_a_configured_channel(): void
    {
        $this->router->dispatch('demo', ['name' => 'Ann']);

        self::assertSame('Hello Ann', $this->recorder->last_text());
        self::assertSame('demo', $this->log->entries()[0]['event']);
        self::assertSame('recorder', $this->log->entries()[0]['channel']);
        self::assertTrue($this->log->entries()[0]['ok']);
    }

    public function test_an_unknown_event_sends_nothing(): void
    {
        $this->router->dispatch('never-registered', ['name' => 'Ann']);

        self::assertSame([], $this->recorder->sent);
    }

    public function test_the_master_switch_stops_everything(): void
    {
        update_option(Settings::OPTION, ['enabled' => false]);
        $this->router = $this->routerWithFreshSettings();

        $this->router->dispatch('demo', ['name' => 'Ann']);

        self::assertSame([], $this->recorder->sent);
    }

    public function test_an_event_toggle_stops_that_event_only(): void
    {
        update_option(Settings::OPTION, ['events' => ['demo' => false]]);
        $this->router = $this->routerWithFreshSettings();

        $this->router->dispatch('demo', ['name' => 'Ann']);

        self::assertSame([], $this->recorder->sent);
    }

    public function test_a_disabled_channel_is_skipped(): void
    {
        update_option(Settings::OPTION, ['channels' => ['recorder' => ['enabled' => false]]]);
        $this->router = $this->routerWithFreshSettings();

        $this->router->dispatch('demo', ['name' => 'Ann']);

        self::assertSame([], $this->recorder->sent);
    }

    public function test_an_unconfigured_channel_is_skipped_and_logged(): void
    {
        $channels = new ChannelRegistry([new RecordingChannel('empty', false)]);
        $log = new Log();

        (new Router(
            new Settings(),
            $channels,
            $this->events,
            new Queue($channels, $log),
            $log
        ))->dispatch('demo', ['name' => 'Ann']);

        self::assertSame([], $channels->get('empty')->sent);
        self::assertFalse($log->entries()[0]['ok']);
        self::assertStringContainsString('no channel', $log->entries()[0]['message']);
    }

    public function test_a_saved_template_replaces_the_event_default(): void
    {
        update_option(Settings::OPTION, ['templates' => ['demo' => 'Hey %name%!']]);
        $this->router = $this->routerWithFreshSettings();

        $this->router->dispatch('demo', ['name' => 'Ann']);

        self::assertSame('Hey Ann!', $this->recorder->last_text());
    }

    public function test_a_new_user_is_reported(): void
    {
        (new UserEvents())->register($this->events, $this->router);

        $user_id = self::factory()->user->create([
            'user_login' => 'ann',
            'user_email' => 'ann@example.com',
            'display_name' => 'Ann Example',
        ]);

        self::assertNotFalse(get_userdata($user_id));
        self::assertStringContainsString('ann@example.com', $this->recorder->last_text());
        self::assertStringContainsString('Ann Example', $this->recorder->last_text());
    }

    public function test_a_failed_login_is_reported(): void
    {
        (new UserEvents())->register($this->events, $this->router);

        do_action('wp_login_failed', 'admin');

        self::assertStringContainsString('Failed login: admin', $this->recorder->last_text());
    }

    public function test_a_published_comment_is_reported(): void
    {
        (new CommentEvents())->register($this->events, $this->router);

        $post_id = self::factory()->post->create(['post_title' => 'Hello world']);
        self::factory()->comment->create([
            'comment_post_ID' => $post_id,
            'comment_author' => 'Bob',
            'comment_content' => 'Nice post',
            'comment_approved' => 1,
        ]);

        self::assertStringContainsString('Nice post', $this->recorder->last_text());
        self::assertStringContainsString('Hello world', $this->recorder->last_text());
    }

    public function test_a_comment_waiting_for_moderation_is_not_reported(): void
    {
        (new CommentEvents())->register($this->events, $this->router);

        $post_id = self::factory()->post->create(['post_title' => 'Hello world']);
        self::factory()->comment->create([
            'comment_post_ID' => $post_id,
            'comment_content' => 'Waiting',
            'comment_approved' => 0,
        ]);

        self::assertSame([], $this->recorder->sent);
    }

    /**
     * The Router caches settings per instance, so a test that changes the option needs a new one.
     */
    private function routerWithFreshSettings(): Router
    {
        return new Router(
            new Settings(),
            $this->channels,
            $this->events,
            new Queue($this->channels, $this->log),
            $this->log
        );
    }
}
