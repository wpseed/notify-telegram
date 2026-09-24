<?php

declare(strict_types=1);

namespace Wpseed\NotifyTelegram\Tests\Unit;

use Wpseed\NotifyTelegram\Greeting;
use PHPUnit\Framework\TestCase;

/**
 * Fast unit tests for the pure logic: WordPress is not needed.
 */
final class GreetingTest extends TestCase
{
    public function test_it_uses_default_template(): void
    {
        self::assertSame('Hello, John!', Greeting::format('John'));
    }

    public function test_it_trims_the_name(): void
    {
        self::assertSame('Hello, John!', Greeting::format('  John  '));
    }

    public function test_it_falls_back_when_name_is_empty(): void
    {
        self::assertSame('Hello, world!', Greeting::format(''));
    }

    public function test_it_uses_a_custom_template(): void
    {
        self::assertSame('Hi, Jane!', Greeting::format('Jane', 'Hi, %s!'));
    }

    public function test_it_appends_placeholder_when_template_has_none(): void
    {
        self::assertSame('Hello John', Greeting::format('John', 'Hello'));
    }

    public function test_it_falls_back_when_template_is_blank(): void
    {
        self::assertSame('Hello, John!', Greeting::format('John', '   '));
    }

    public function test_it_falls_back_when_template_cannot_be_formatted(): void
    {
        // Two placeholders and one argument: sprintf() throws, the greeting must not take the page down.
        self::assertSame('Hello, John!', Greeting::format('John', '%s and %s'));
    }

    public function test_it_prints_escaped_percent_signs(): void
    {
        self::assertSame('100% John', Greeting::format('John', '100%% %s'));
    }
}
