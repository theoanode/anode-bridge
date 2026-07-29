<?php
/**
 * Orchestrateur du plugin : enregistre les contrôleurs REST.
 *
 * @package Anode\Bridge
 */

declare( strict_types = 1 );

namespace Anode\Bridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plugin {

	/**
	 * Contrôleurs REST à enregistrer.
	 *
	 * @var array<class-string>
	 */
	private const CONTROLLERS = [
		Rest_Site::class,
		Rest_Bricks::class,
		Rest_Design_System::class,
		Rest_Media::class,
		Rest_Content::class,
	];

	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
		add_action( 'init', [ $this, 'expose_bricks_meta' ], 20 );
	}

	public function register_routes(): void {
		foreach ( self::CONTROLLERS as $controller ) {
			( new $controller() )->register_routes();
		}
	}

	/**
	 * Rend les metas Bricks lisibles via l'API REST standard.
	 *
	 * Bricks préfixe ses metas par « _ » : elles sont protégées et invisibles
	 * pour wp/v2. On les expose en lecture seule pour permettre au MCP de
	 * repérer rapidement les pages construites avec Bricks sans requête
	 * supplémentaire. L'écriture passe exclusivement par anode/v1/bricks/*,
	 * qui valide et régénère le CSS.
	 */
	public function expose_bricks_meta(): void {
		if ( ! Bricks_Adapter::is_available() ) {
			return;
		}

		foreach ( Bricks_Adapter::editable_post_types() as $post_type ) {
			register_post_meta(
				$post_type,
				Bricks_Adapter::META_EDITOR_MODE,
				[
					'type'          => 'string',
					'single'        => true,
					'show_in_rest'  => true,
					'auth_callback' => static fn (): bool => Security::can_read(),
				]
			);
		}
	}
}
