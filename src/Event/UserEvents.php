<?php
/**
 * User events: registrations and failed logins.
 *
 * @package NotifyTelegram
 */

declare(strict_types=1);

namespace Wpseed\NotifyTelegram\Event;

use Wpseed\NotifyTelegram\Delivery\Router;

/**
 * Watches user_register and wp_login_failed.
 *
 * Failed logins are the event that earns its keep on a quiet site: the first thing that catches a
 * brute-force run is a notification, long before anyone opens the logs.
 */
final class UserEvents implements EventSource {

	/**
	 * Event identifier: a new user registered.
	 */
	public const USER_REGISTERED = 'user_registered';

	/**
	 * Event identifier: a login attempt failed.
	 */
	public const LOGIN_FAILED = 'user_login_failed';

	/**
	 * Router the occurrences go to.
	 *
	 * @var Router|null
	 */
	private ?Router $router = null;

	/**
	 * Declares the events and hooks them up.
	 *
	 * @param EventRegistry $events Registry to declare events in.
	 * @param Router        $router Router to hand occurrences to.
	 * @return void
	 */
	public function register( EventRegistry $events, Router $router ): void {
		$this->router = $router;

		$events->register(
			new Event(
				self::USER_REGISTERED,
				__( 'New user registration', 'notify-telegram' ),
				array(
					'user_id'      => __( 'User ID', 'notify-telegram' ),
					'user_login'   => __( 'Username', 'notify-telegram' ),
					'user_email'   => __( 'Email', 'notify-telegram' ),
					'display_name' => __( 'Display name', 'notify-telegram' ),
					'roles'        => __( 'Roles, comma separated', 'notify-telegram' ),
				),
				/* translators: %display_name%: display name, %user_email%: email, %roles%: roles. */
				__( 'New user: %display_name% <%user_email%> (%roles%)', 'notify-telegram' )
			)
		);

		$events->register(
			new Event(
				self::LOGIN_FAILED,
				__( 'Failed login attempt', 'notify-telegram' ),
				array(
					'username' => __( 'Submitted username', 'notify-telegram' ),
					'ip'       => __( 'Client IP address', 'notify-telegram' ),
				),
				/* translators: %username%: submitted username, %ip%: client IP address. */
				__( 'Failed login: %username% from %ip%', 'notify-telegram' )
			)
		);

		add_action( 'user_register', array( $this, 'on_user_registered' ), 10, 1 );
		add_action( 'wp_login_failed', array( $this, 'on_login_failed' ), 10, 1 );
	}

	/**
	 * Reports a new user.
	 *
	 * @param int $user_id User ID.
	 * @return void
	 */
	public function on_user_registered( int $user_id ): void {
		$user = get_userdata( $user_id );

		if ( false === $user ) {
			return;
		}

		$this->dispatch(
			self::USER_REGISTERED,
			array(
				'user_id'      => $user_id,
				'user_login'   => (string) $user->user_login,
				'user_email'   => (string) $user->user_email,
				'display_name' => (string) $user->display_name,
				'roles'        => implode( ', ', array_map( 'strval', (array) $user->roles ) ),
			)
		);
	}

	/**
	 * Reports a failed login.
	 *
	 * @param string $username Submitted username.
	 * @return void
	 */
	public function on_login_failed( string $username ): void {
		$this->dispatch(
			self::LOGIN_FAILED,
			array(
				'username' => sanitize_text_field( $username ),
				'ip'       => isset( $_SERVER['REMOTE_ADDR'] )
					? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) )
					: '',
			)
		);
	}

	/**
	 * Hands the occurrence to the router.
	 *
	 * @param string               $event_id Event identifier.
	 * @param array<string, mixed> $context  Placeholder values.
	 * @return void
	 */
	private function dispatch( string $event_id, array $context ): void {
		$this->router?->dispatch( $event_id, $context );
	}
}
