<?php
/**
 * Comment events.
 *
 * @package NotifyTelegram
 */

declare(strict_types=1);

namespace Wpseed\NotifyTelegram\Event;

use Wpseed\NotifyTelegram\Delivery\Router;
use WP_Comment;

/**
 * Watches wp_insert_comment.
 *
 * Only approved comments are reported: a hold-for-moderation queue is exactly the noise a
 * notification should not add to, and spam is caught by the site, not by Telegram.
 */
final class CommentEvents implements EventSource {

	/**
	 * Event identifier: a comment was published.
	 */
	public const COMMENT_POSTED = 'comment_posted';

	/**
	 * How much of the comment text is sent.
	 */
	public const EXCERPT_LENGTH = 200;

	/**
	 * Router the occurrences go to.
	 *
	 * @var Router|null
	 */
	private ?Router $router = null;

	/**
	 * Declares the event and hooks it up.
	 *
	 * @param EventRegistry $events Registry to declare events in.
	 * @param Router        $router Router to hand occurrences to.
	 * @return void
	 */
	public function register( EventRegistry $events, Router $router ): void {
		$this->router = $router;

		$events->register(
			new Event(
				self::COMMENT_POSTED,
				__( 'New comment', 'notify-telegram' ),
				array(
					'comment_id'   => __( 'Comment ID', 'notify-telegram' ),
					'author'       => __( 'Comment author', 'notify-telegram' ),
					'author_email' => __( 'Author email', 'notify-telegram' ),
					'content'      => __( 'Comment text, shortened', 'notify-telegram' ),
					'post_title'   => __( 'Post title', 'notify-telegram' ),
					'post_url'     => __( 'Post URL', 'notify-telegram' ),
				),
				/* translators: %post_title%: post title, %author%: comment author, %content%: comment text. */
				__( 'New comment on %post_title% by %author%: %content%', 'notify-telegram' )
			)
		);

		add_action( 'wp_insert_comment', array( $this, 'on_comment_inserted' ), 10, 2 );
	}

	/**
	 * Reports a published comment.
	 *
	 * @param int        $comment_id Comment ID.
	 * @param WP_Comment $comment    Comment.
	 * @return void
	 */
	public function on_comment_inserted( int $comment_id, WP_Comment $comment ): void {
		if ( '1' !== (string) $comment->comment_approved ) {
			return;
		}

		$this->router?->dispatch(
			self::COMMENT_POSTED,
			array(
				'comment_id'   => $comment_id,
				'author'       => (string) $comment->comment_author,
				'author_email' => (string) $comment->comment_author_email,
				'content'      => self::excerpt( (string) $comment->comment_content ),
				'post_title'   => (string) get_the_title( (int) $comment->comment_post_ID ),
				'post_url'     => (string) get_permalink( (int) $comment->comment_post_ID ),
			)
		);
	}

	/**
	 * Shortens the comment text for a chat window.
	 *
	 * @param string $content Comment text.
	 * @return string
	 */
	public static function excerpt( string $content ): string {
		$text = trim( preg_replace( '/\s+/', ' ', $content ) ?? $content );

		return mb_strlen( $text ) <= self::EXCERPT_LENGTH
			? $text
			: mb_substr( $text, 0, self::EXCERPT_LENGTH - 1 ) . '…';
	}
}
