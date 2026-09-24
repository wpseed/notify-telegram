<?php
/**
 * REST route the React admin application reads and writes the settings through.
 *
 * @package NotifyTelegram
 */

declare(strict_types=1);

namespace Wpseed\NotifyTelegram\Rest;

use Wpseed\NotifyTelegram\Greeting;
use Wpseed\NotifyTelegram\Plugin;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Settings endpoint: `notify-telegram/v1/settings`.
 *
 * Only users with `manage_options` reach it, and cookie-authenticated requests must carry the
 * `wp_rest` nonce — which the admin application sends in the X-WP-Nonce header, straight from the
 * configuration PHP prints next to the bundle.
 */
final class SettingsController {

	/**
	 * REST namespace.
	 */
	public const REST_NAMESPACE = 'notify-telegram/v1';

	/**
	 * Route below the namespace.
	 */
	public const ROUTE = '/settings';

	/**
	 * Registers the REST hook.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers the route and its arguments.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => array( $this, 'can_manage_options' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_settings' ),
					'permission_callback' => array( $this, 'can_manage_options' ),
					'args'                => array(
						'template' => array(
							'type'              => 'string',
							'required'          => true,
							/* translators: %s: placeholder that is replaced with the name. */
							'description'       => __( 'Greeting template, %s is replaced with the name.', 'notify-telegram' ),
							'sanitize_callback' => array( $this, 'sanitize_template' ),
							'validate_callback' => array( $this, 'validate_template' ),
						),
					),
				),
				'schema' => array( $this, 'get_schema' ),
			)
		);
	}

	/**
	 * Whether the current user may read and change the settings.
	 *
	 * @return bool
	 */
	public function can_manage_options(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Returns the stored settings.
	 *
	 * @return WP_REST_Response
	 */
	public function get_settings(): WP_REST_Response {
		return rest_ensure_response( $this->settings() );
	}

	/**
	 * Stores the submitted settings and returns what was stored.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function update_settings( WP_REST_Request $request ): WP_REST_Response {
		update_option( Plugin::OPTION_TEMPLATE, (string) $request->get_param( 'template' ) );

		return rest_ensure_response( $this->settings() );
	}

	/**
	 * Stored settings, with the same fallback the front end uses.
	 *
	 * @return array{template: string}
	 */
	public function settings(): array {
		$stored = get_option( Plugin::OPTION_TEMPLATE, Greeting::DEFAULT_TEMPLATE );

		return array(
			'template' => is_string( $stored ) && '' !== trim( $stored ) ? $stored : Greeting::DEFAULT_TEMPLATE,
		);
	}

	/**
	 * Cleans the submitted template: plain text, blank input falls back to the plugin default.
	 *
	 * @param mixed $value Submitted value.
	 * @return string
	 */
	public function sanitize_template( mixed $value ): string {
		$template = is_string( $value ) ? trim( sanitize_text_field( $value ) ) : '';

		return '' === $template ? Greeting::DEFAULT_TEMPLATE : $template;
	}

	/**
	 * Rejects templates the site could not render.
	 *
	 * The template lands in sprintf() with exactly one argument, so a percent sign is only allowed as
	 * part of the placeholder (%s) or as an escaped literal (%%), and the placeholder may appear at
	 * most once: `Hi %d!` silently renders "Hi 0!" and `%s and %s` throws in the middle of a page.
	 *
	 * @param mixed $value Submitted value.
	 * @return bool|WP_Error
	 */
	public function validate_template( mixed $value ): bool|WP_Error {
		$template = $this->sanitize_template( $value );

		if ( str_contains( str_replace( array( '%s', '%%' ), '', $template ), '%' ) ) {
			return new WP_Error(
				'notify_telegram_template_percent',
				__( 'Only the name placeholder may contain a percent sign.', 'notify-telegram' ),
				array( 'status' => 400 )
			);
		}

		if ( substr_count( $template, '%s' ) > 1 ) {
			return new WP_Error(
				'notify_telegram_template_placeholder',
				__( 'The template must contain the name placeholder at most once.', 'notify-telegram' ),
				array( 'status' => 400 )
			);
		}

		try {
			sprintf( $template, 'John' );
		} catch ( \Throwable ) {
			return new WP_Error(
				'notify_telegram_template_format',
				__( 'The template cannot be used to build the greeting.', 'notify-telegram' ),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * Schema of the route.
	 *
	 * @return array<string, mixed>
	 */
	public function get_schema(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'notify_telegram_settings',
			'type'       => 'object',
			'properties' => array(
				'template' => array(
					'description' => __( 'Greeting template used by the shortcode.', 'notify-telegram' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
				),
			),
		);
	}
}
