<?php
/**
 * Endpoints d'information et de maintenance du site.
 *
 * @package Anode\Bridge
 */

declare( strict_types = 1 );

namespace Anode\Bridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Rest_Site {

	public function register_routes(): void {
		register_rest_route(
			NAMESPACE_,
			'/site',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_site' ],
				'permission_callback' => [ Security::class, 'permission_read' ],
			]
		);

		register_rest_route(
			NAMESPACE_,
			'/cache/flush',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'flush_cache' ],
				'permission_callback' => [ Security::class, 'permission_manage' ],
			]
		);

		register_rest_route(
			NAMESPACE_,
			'/audit-log',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_audit_log' ],
				'permission_callback' => [ Security::class, 'permission_manage' ],
				'args'                => [
					'limit' => [
						'type'    => 'integer',
						'default' => 50,
						'minimum' => 1,
						'maximum' => 200,
					],
				],
			]
		);
	}

	/**
	 * Carte d'identité du site : ce que le MCP interroge au démarrage pour
	 * savoir de quoi il dispose.
	 */
	public function get_site(): \WP_REST_Response {
		$theme  = wp_get_theme();
		$parent = $theme->parent();

		$post_types = [];

		foreach ( get_post_types( [ 'show_in_rest' => true ], 'objects' ) as $post_type ) {
			$post_types[] = [
				'slug'      => $post_type->name,
				'label'     => $post_type->label,
				'rest_base' => $post_type->rest_base ?: $post_type->name,
				'hierarchical' => (bool) $post_type->hierarchical,
			];
		}

		$data = [
			'name'        => get_bloginfo( 'name' ),
			'description' => get_bloginfo( 'description' ),
			'url'         => home_url(),
			'admin_url'   => admin_url(),
			'language'    => get_locale(),
			'timezone'    => wp_timezone_string(),
			'versions'    => [
				'wordpress' => get_bloginfo( 'version' ),
				'php'       => PHP_VERSION,
				'bridge'    => VERSION,
			],
			'theme'       => [
				'name'    => $theme->get( 'Name' ),
				'version' => $theme->get( 'Version' ),
				'parent'  => $parent ? $parent->get( 'Name' ) : null,
				'stylesheet' => get_stylesheet(),
			],
			'bricks'      => [
				'available' => Bricks_Adapter::is_available(),
				'version'   => defined( 'BRICKS_VERSION' ) ? BRICKS_VERSION : null,
				'post_types' => Bricks_Adapter::is_available() ? Bricks_Adapter::editable_post_types() : [],
				'css_loading' => class_exists( '\Bricks\Database' ) ? \Bricks\Database::get_setting( 'cssLoading', 'inline' ) : null,
			],
			'post_types'  => $post_types,
			'plugins'     => $this->active_plugins(),
			'user'        => [
				'id'    => get_current_user_id(),
				'login' => wp_get_current_user()->user_login,
				'can'   => [
					'read'   => Security::can_read(),
					'write'  => Security::can_write(),
					'manage' => Security::can_manage(),
				],
			],
		];

		return rest_ensure_response( $data );
	}

	/**
	 * @return array<int, array<string, string>>
	 */
	private function active_plugins(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$active = (array) get_option( 'active_plugins', [] );
		$all    = get_plugins();
		$list   = [];

		foreach ( $active as $file ) {
			if ( isset( $all[ $file ] ) ) {
				$list[] = [
					'file'    => $file,
					'name'    => $all[ $file ]['Name'],
					'version' => $all[ $file ]['Version'],
				];
			}
		}

		return $list;
	}

	/**
	 * Vide les caches connus et force Bricks à régénérer son CSS.
	 */
	public function flush_cache(): \WP_REST_Response {
		$flushed = [];

		wp_cache_flush();
		$flushed[] = 'object-cache';

		if ( Bricks_Adapter::is_available() && class_exists( '\Bricks\Assets_Files' ) ) {
			\Bricks\Assets_Files::regenerate_css_files();
			$flushed[] = 'bricks-css';
		}

		// WP Rocket : présent sur tous les sites issus du blueprint.
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
			$flushed[] = 'wp-rocket';
		}

		if ( function_exists( 'rocket_clean_minify' ) ) {
			rocket_clean_minify();
			$flushed[] = 'wp-rocket-minify';
		}

		Security::log( 'cache.flush', [ 'flushed' => $flushed ] );

		return rest_ensure_response(
			[
				'flushed' => $flushed,
				'message' => 'Caches vidés : ' . implode( ', ', $flushed ) . '.',
			]
		);
	}

	public function get_audit_log( \WP_REST_Request $request ): \WP_REST_Response {
		return rest_ensure_response(
			[
				'entries' => Security::get_log( (int) $request->get_param( 'limit' ) ),
			]
		);
	}
}
