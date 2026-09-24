<?php

declare(strict_types=1);

namespace Wpseed\NotifyTelegram\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Wpseed\NotifyTelegram\Message;

/**
 * Rendering and validating the text of a message.
 */
final class MessageTest extends TestCase
{
    public function test_render_replaces_placeholders(): void
    {
        self::assertSame(
            'New user: Ann <ann@example.com>',
            Message::render('New user: %display_name% <%user_email%>', [
                'display_name' => 'Ann',
                'user_email' => 'ann@example.com',
            ])
        );
    }

    public function test_render_leaves_placeholders_without_a_value(): void
    {
        self::assertSame('Hi %name%!', Message::render('Hi %name%!', []));
    }

    public function test_render_ignores_non_scalar_values(): void
    {
        self::assertSame('%items%', Message::render('%items%', ['items' => ['a', 'b']]));
    }

    public function test_placeholders_in_lists_each_name_once_in_order(): void
    {
        self::assertSame(
            ['b', 'a'],
            Message::placeholders_in('%b% then %a% then %b%')
        );
    }

    public function test_validate_template_accepts_declared_placeholders(): void
    {
        self::assertNull(Message::validate_template('Hi %name%', ['name']));
    }

    public function test_validate_template_rejects_unknown_placeholder(): void
    {
        self::assertStringContainsString('nmae', (string) Message::validate_template('Hi %nmae%', ['name']));
    }

    public function test_validate_template_rejects_a_stray_percent_sign(): void
    {
        self::assertNotNull(Message::validate_template('100% done', ['name']));
    }

    public function test_validate_template_rejects_empty_text(): void
    {
        self::assertNotNull(Message::validate_template('   ', ['name']));
    }

    public function test_from_array_round_trips_a_message(): void
    {
        $message = Message::from_template('demo', 'Hi %name%', ['name' => 'Ann'], 'Demo');

        $rebuilt = Message::from_array($message->to_array());

        self::assertSame('Hi Ann', $rebuilt->text());
        self::assertSame('demo', $rebuilt->event_id());
        self::assertSame('Demo', $rebuilt->subject());
        self::assertSame(['name' => 'Ann'], $rebuilt->context());
    }
}
