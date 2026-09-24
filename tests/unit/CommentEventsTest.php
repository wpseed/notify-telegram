<?php

declare(strict_types=1);

namespace Wpseed\NotifyTelegram\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Wpseed\NotifyTelegram\Event\CommentEvents;

/**
 * Shortening comment text for a chat window.
 */
final class CommentEventsTest extends TestCase
{
    public function test_short_comment_is_kept_as_it_is(): void
    {
        self::assertSame('Nice post', CommentEvents::excerpt('Nice post'));
    }

    public function test_whitespace_in_the_comment_is_collapsed(): void
    {
        self::assertSame('one two', CommentEvents::excerpt("one \n\n  two"));
    }

    public function test_long_comment_is_cut_with_an_ellipsis(): void
    {
        $excerpt = CommentEvents::excerpt(str_repeat('a', CommentEvents::EXCERPT_LENGTH + 20));

        self::assertSame(CommentEvents::EXCERPT_LENGTH, mb_strlen($excerpt));
        self::assertStringEndsWith('…', $excerpt);
    }
}
