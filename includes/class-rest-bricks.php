<?php
/**
 * Endpoints Bricks : contenu des pages, classes globales, variables, templates.
 *
 * @package Anode\Bridge
 */

declare( strict_types = 1 );

namespace Anode\Bridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Rest_Bricks {

	public function register_routes(): void {
		register_rest_route(
			NAMESPACE_,
			'/bricks/content/(?P<id>\d+)',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_content' ],
					'permission_callback' => [ Security::class, 'permission_read' ],
					'args'                => $this->content_args(),
				],
				[
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => [ $this, 'update_content' ],
					'permission_callback' => [ Security::class, 'permission_write' ],
					'args'                => $this->content_args() + [
						'elements' => [
							'required'    => true,
							'type'        => 'array',
							'description' => 'Structure Bricks : tableau plat de nœuds reliés par parent/children.',
						],
					],
				],
			]
		);

		register_rest_route(
			NAMESPACE_,
			'/bricks/render/(?P<id>\d+)',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'render_content' ],
				'permission_callback' => [ Security::class, 'permission_read' ],
				'args'                => $this->content_args(),
			]
		);

		register_rest_route(
			NAMESPACE_,
			'/bricks/classes',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_classes' ],
					'permission_callback' => [ Security::class, 'permission_read' ],
				],
				[
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => [ $this, 'update_classes' ],
					'permission_callback' => [ Security::class, 'permission_manage' ],
					'args'                => [
						'classes'    => [ 'required' => true, 'type' => 'array' ],
						'mode'       => [
							'type'    => 'string',
							'enum'    => [ 'merge', 'replace' ],
							'default' => 'merge',
						],
						'categories' => [ 'type' => 'array' ],
						'strict_bem' => [ 'type' => 'boolean', 'default' => true ],
					],
				],
			]
		);

		register_rest_route(
			NAMESPACE_,
			'/bricks/classes/(?P<name>[^/]+)',
			[
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => [ $this, 'delete_class' ],
				'permission_callback' => [ Security::class, 'permission_manage' ],
			]
		);

		register_rest_route(
			NAMESPACE_,
			'/bricks/variables',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_variables' ],
					'permission_callback' => [ Security::class, 'permission_read' ],
				],
				[
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => [ $this, 'update_variables' ],
					'permission_callback' => [ Security::class, 'permission_manage' ],
					'args'                => [
						'variables'  => [ 'required' => true, 'type' => 'array' ],
						'mode'       => [
							'type'    => 'string',
							'enum'    => [ 'merge', 'replace' ],
							'default' => 'merge',
						],
						'categories' => [ 'type' => 'array' ],
					],
				],
			]
		);

		register_rest_route(
			NAMESPACE_,
			'/bricks/breakpoints',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_breakpoints' ],
					'permission_callback' => [ Security::class, 'permission_read' ],
				],
				[
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => [ $this, 'update_breakpoints' ],
					'permission_callback' => [ Security::class, 'permission_manage' ],
					'args'                => [
						'breakpoints' => [
							'required'    => true,
							'type'        => 'array',
							'description' => 'Liste de { key, width }. Seules les largeurs des points existants sont modifiées.',
						],
					],
				],
			]
		);

		register_rest_route(
			NAMESPACE_,
			'/bricks/templates',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'list_templates' ],
					'permission_callback' => [ Security::class, 'permission_read' ],
					'args'                => [
						'type' => [ 'type' => 'string' ],
					],
				],
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'upsert_template' ],
					'permission_callback' => [ Security::class, 'permission_manage' ],
					'args'                => [
						'title' => [
							'required'    => true,
							'type'        => 'string',
							'description' => 'Nom du template. Un template du même nom et du même type est mis à jour.',
						],
						'type'  => [
							'required'    => true,
							'type'        => 'string',
							'description' => 'header, footer, section, content, archive, single, popup, error, search.',
						],
						'elements' => [
							'type'        => 'array',
							'description' => 'Structure Bricks du template.',
						],
						'conditions' => [
							'type'        => 'array',
							'description' => 'Conditions d’affichage Bricks. Par défaut : tout le site.',
						],
					],
				],
			]
		);
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private function content_args(): array {
		return [
			'id'   => [
				'required' => true,
				'type'     => 'integer',
			],
			'area' => [
				'type'    => 'string',
				'enum'    => [ 'content', 'header', 'footer' ],
				'default' => 'content',
			],
		];
	}

	/* ------------------------------------------------------------------ */
	/* Contenu                                                             */
	/* ------------------------------------------------------------------ */

	public function get_content( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$post_id = (int) $request->get_param( 'id' );
		$area    = (string) $request->get_param( 'area' );
		$post    = $this->require_post( $post_id );

		if ( $post instanceof \WP_Error ) {
			return $post;
		}

		$elements = Bricks_Adapter::get_elements( $post_id, $area );

		return rest_ensure_response(
			[
				'id'          => $post_id,
				'title'       => get_the_title( $post_id ),
				'slug'        => $post->post_name,
				'post_type'   => $post->post_type,
				'status'      => $post->post_status,
				'permalink'   => get_permalink( $post_id ),
				'edit_url'    => $this->builder_url( $post_id ),
				'area'        => $area,
				'editor_mode' => get_post_meta( $post_id, Bricks_Adapter::META_EDITOR_MODE, true ) ?: 'wordpress',
				'count'       => count( $elements ),
				'elements'    => $elements,
			]
		);
	}

	public function update_content( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$post_id = (int) $request->get_param( 'id' );
		$area    = (string) $request->get_param( 'area' );
		$post    = $this->require_post( $post_id );

		if ( $post instanceof \WP_Error ) {
			return $post;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error(
				'anode_bridge_forbidden',
				'Vous ne pouvez pas modifier ce contenu.',
				[ 'status' => 403 ]
			);
		}

		$elements = Validator::elements( $request->get_param( 'elements' ) );

		if ( $elements instanceof \WP_Error ) {
			return $elements;
		}

		$previous = Bricks_Adapter::get_elements( $post_id, $area );

		Bricks_Adapter::set_elements( $post_id, $elements, $area );

		Security::log(
			'bricks.content.update',
			[
				'post_id' => $post_id,
				'area'    => $area,
				'before'  => count( $previous ),
				'after'   => count( $elements ),
			]
		);

		return rest_ensure_response(
			[
				'id'        => $post_id,
				'area'      => $area,
				'count'     => count( $elements ),
				'permalink' => get_permalink( $post_id ),
				'edit_url'  => $this->builder_url( $post_id ),
				'message'   => sprintf( '%d élément(s) enregistré(s) dans la zone « %s ».', count( $elements ), $area ),
			]
		);
	}

	/**
	 * Rend le HTML produit par une structure Bricks.
	 *
	 * Sert à vérifier le résultat d'une écriture sans ouvrir un navigateur.
	 */
	public function render_content( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( ! class_exists( '\Bricks\Frontend' ) ) {
			return new \WP_Error(
				'anode_bridge_no_bricks',
				'Le thème Bricks n’est pas actif sur ce site.',
				[ 'status' => 409 ]
			);
		}

		$post_id = (int) $request->get_param( 'id' );
		$area    = (string) $request->get_param( 'area' );
		$target  = $this->require_post( $post_id );

		if ( $target instanceof \WP_Error ) {
			return $target;
		}

		$elements = Bricks_Adapter::get_elements( $post_id, $area );

		if ( ! $elements ) {
			return rest_ensure_response( [ 'id' => $post_id, 'area' => $area, 'html' => '' ] );
		}

		// Bricks rend ses éléments dans le contexte de la boucle principale.
		global $post;
		$post = $target; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		setup_postdata( $post );

		$html = \Bricks\Frontend::render_data( $elements, $area );

		wp_reset_postdata();

		return rest_ensure_response(
			[
				'id'   => $post_id,
				'area' => $area,
				'html' => $html,
			]
		);
	}

	/* ------------------------------------------------------------------ */
	/* Classes globales                                                    */
	/* ------------------------------------------------------------------ */

	public function get_classes(): \WP_REST_Response {
		$classes = Bricks_Adapter::get_global_classes();

		return rest_ensure_response(
			[
				'count'      => count( $classes ),
				'classes'    => $classes,
				'categories' => Bricks_Adapter::get_class_categories(),
			]
		);
	}

	public function update_classes( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$incoming = Validator::global_classes(
			$request->get_param( 'classes' ),
			(bool) $request->get_param( 'strict_bem' )
		);

		if ( $incoming instanceof \WP_Error ) {
			return $incoming;
		}

		$mode = (string) $request->get_param( 'mode' );

		if ( 'replace' === $mode ) {
			$result = $incoming;
			$added  = count( $incoming );
			$updated = 0;
		} else {
			[ $result, $added, $updated ] = $this->merge_by_name( Bricks_Adapter::get_global_classes(), $incoming );
		}

		Bricks_Adapter::set_global_classes( $result );

		if ( is_array( $request->get_param( 'categories' ) ) ) {
			$categories = Validator::categories( $request->get_param( 'categories' ) );

			if ( $categories instanceof \WP_Error ) {
				return $categories;
			}

			Bricks_Adapter::set_class_categories( $categories );
		}

		Security::log(
			'bricks.classes.update',
			[
				'mode'    => $mode,
				'added'   => $added,
				'updated' => $updated,
				'total'   => count( $result ),
			]
		);

		return rest_ensure_response(
			[
				'mode'    => $mode,
				'added'   => $added,
				'updated' => $updated,
				'total'   => count( $result ),
				'message' => sprintf( '%d classe(s) ajoutée(s), %d mise(s) à jour, %d au total.', $added, $updated, count( $result ) ),
			]
		);
	}

	public function delete_class( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$name    = urldecode( (string) $request->get_param( 'name' ) );
		$classes = Bricks_Adapter::get_global_classes();
		$kept    = [];
		$removed = 0;

		foreach ( $classes as $class ) {
			if ( ( $class['name'] ?? '' ) === $name || ( $class['id'] ?? '' ) === $name ) {
				++$removed;
				continue;
			}

			$kept[] = $class;
		}

		if ( 0 === $removed ) {
			return new \WP_Error(
				'anode_bridge_not_found',
				sprintf( 'Aucune classe globale nommée « %s ».', $name ),
				[ 'status' => 404 ]
			);
		}

		Bricks_Adapter::set_global_classes( $kept );
		Security::log( 'bricks.classes.delete', [ 'name' => $name ] );

		return rest_ensure_response(
			[
				'removed' => $removed,
				'total'   => count( $kept ),
				'message' => sprintf( 'Classe « %s » supprimée.', $name ),
			]
		);
	}

	/* ------------------------------------------------------------------ */
	/* Variables globales                                                  */
	/* ------------------------------------------------------------------ */

	public function get_variables(): \WP_REST_Response {
		$variables = Bricks_Adapter::get_variables();

		return rest_ensure_response(
			[
				'count'      => count( $variables ),
				'variables'  => $variables,
				'categories' => Bricks_Adapter::get_variable_categories(),
			]
		);
	}

	public function update_variables( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$incoming = Validator::variables( $request->get_param( 'variables' ) );

		if ( $incoming instanceof \WP_Error ) {
			return $incoming;
		}

		$mode = (string) $request->get_param( 'mode' );

		if ( 'replace' === $mode ) {
			$result  = $incoming;
			$added   = count( $incoming );
			$updated = 0;
		} else {
			[ $result, $added, $updated ] = $this->merge_by_name( Bricks_Adapter::get_variables(), $incoming );
		}

		Bricks_Adapter::set_variables( $result );

		if ( is_array( $request->get_param( 'categories' ) ) ) {
			$categories = Validator::categories( $request->get_param( 'categories' ) );

			if ( $categories instanceof \WP_Error ) {
				return $categories;
			}

			Bricks_Adapter::set_variable_categories( $categories );
		}

		Security::log(
			'bricks.variables.update',
			[
				'mode'    => $mode,
				'added'   => $added,
				'updated' => $updated,
				'total'   => count( $result ),
			]
		);

		return rest_ensure_response(
			[
				'mode'    => $mode,
				'added'   => $added,
				'updated' => $updated,
				'total'   => count( $result ),
				'message' => sprintf( '%d variable(s) ajoutée(s), %d mise(s) à jour, %d au total.', $added, $updated, count( $result ) ),
			]
		);
	}

	/* ------------------------------------------------------------------ */
	/* Points de rupture                                                   */
	/* ------------------------------------------------------------------ */

	public function get_breakpoints(): \WP_REST_Response {
		return rest_ensure_response(
			[
				'custom_enabled' => class_exists( '\Bricks\Database' )
					? (bool) \Bricks\Database::get_setting( 'customBreakpoints', false )
					: false,
				'breakpoints'    => class_exists( '\Bricks\Breakpoints' )
					? \Bricks\Breakpoints::get_breakpoints()
					: [],
			]
		);
	}

	/**
	 * Ajuste la largeur des points de rupture existants.
	 *
	 * À faire avant de poser la première classe : Bricks indexe les styles
	 * responsives par clé de point de rupture, et changer les largeurs après
	 * coup laisse des styles rattachés à des seuils qui ont bougé.
	 *
	 * On ne modifie que les largeurs — ni la liste, ni les clés, ni le point
	 * de base. Ajouter ou retirer un point de rupture depuis l'API casserait
	 * les styles déjà enregistrés.
	 */
	public function update_breakpoints( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( ! class_exists( '\Bricks\Breakpoints' ) ) {
			return new \WP_Error( 'anode_bridge_no_bricks', 'Le thème Bricks n’est pas actif.', [ 'status' => 409 ] );
		}

		$current = \Bricks\Breakpoints::get_breakpoints();
		$wanted  = [];

		foreach ( (array) $request->get_param( 'breakpoints' ) as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['key'] ) || ! isset( $entry['width'] ) ) {
				return new \WP_Error( 'anode_bridge_invalid', 'Chaque entrée doit comporter « key » et « width ».', [ 'status' => 400 ] );
			}

			$width = (int) $entry['width'];

			if ( $width < 200 || $width > 3000 ) {
				return new \WP_Error(
					'anode_bridge_invalid',
					sprintf( 'Largeur hors bornes pour « %s » : %d (attendu entre 200 et 3000).', (string) $entry['key'], $width ),
					[ 'status' => 400 ]
				);
			}

			$wanted[ (string) $entry['key'] ] = $width;
		}

		$changes = [];
		$known   = wp_list_pluck( $current, 'key' );

		foreach ( array_keys( $wanted ) as $key ) {
			if ( ! in_array( $key, $known, true ) ) {
				return new \WP_Error(
					'anode_bridge_invalid',
					sprintf( 'Point de rupture inconnu : « %s ». Existants : %s.', $key, implode( ', ', $known ) ),
					[ 'status' => 400 ]
				);
			}
		}

		foreach ( $current as $index => $breakpoint ) {
			$key = $breakpoint['key'] ?? '';

			if ( isset( $wanted[ $key ] ) && (int) $breakpoint['width'] !== $wanted[ $key ] ) {
				$changes[]                   = sprintf( '%s : %d → %d px', $key, (int) $breakpoint['width'], $wanted[ $key ] );
				$current[ $index ]['width']  = $wanted[ $key ];
			}
		}

		// Les largeurs personnalisées ne sont prises en compte que si Bricks
		// est explicitement passé en mode « points de rupture personnalisés ».
		$settings                       = get_option( Bricks_Adapter::OPT_GLOBAL_SETTINGS, [] );
		$settings                       = is_array( $settings ) ? $settings : [];
		$enabled_before                 = ! empty( $settings['customBreakpoints'] );
		$settings['customBreakpoints']  = true;

		update_option( Bricks_Adapter::OPT_GLOBAL_SETTINGS, $settings, false );
		update_option( Bricks_Adapter::OPT_BREAKPOINTS, array_values( $current ), false );

		Bricks_Adapter::regenerate_css();

		Security::log( 'bricks.breakpoints.update', [ 'changes' => $changes ] );

		return rest_ensure_response(
			[
				'changes'        => $changes,
				'custom_enabled' => true,
				'breakpoints'    => $current,
				'message'        => $changes
					? sprintf( '%d point(s) de rupture ajusté(s) : %s.', count( $changes ), implode( ' · ', $changes ) )
						. ( $enabled_before ? '' : ' Mode personnalisé activé.' )
					: 'Aucun changement : les largeurs étaient déjà celles demandées.',
			]
		);
	}

	/* ------------------------------------------------------------------ */
	/* Templates                                                           */
	/* ------------------------------------------------------------------ */

	public function list_templates( \WP_REST_Request $request ): \WP_REST_Response {
		$args = [
			'post_type'      => Bricks_Adapter::POST_TYPE_TEMPLATE,
			'posts_per_page' => 100,
			'post_status'    => [ 'publish', 'draft' ],
			'orderby'        => 'title',
			'order'          => 'ASC',
		];

		$type = $request->get_param( 'type' );

		if ( is_string( $type ) && '' !== $type ) {
			$args['meta_query'] = [
				[
					'key'   => Bricks_Adapter::META_TEMPLATE_TYPE,
					'value' => sanitize_key( $type ),
				],
			];
		}

		$templates = [];

		foreach ( get_posts( $args ) as $template ) {
			$templates[] = [
				'id'       => $template->ID,
				'title'    => $template->post_title,
				'slug'     => $template->post_name,
				'status'   => $template->post_status,
				'type'     => get_post_meta( $template->ID, Bricks_Adapter::META_TEMPLATE_TYPE, true ),
				'edit_url' => $this->builder_url( $template->ID ),
			];
		}

		return rest_ensure_response(
			[
				'count'     => count( $templates ),
				'templates' => $templates,
			]
		);
	}

	/**
	 * Crée ou met à jour un template Bricks (en-tête, pied de page, section…).
	 *
	 * Le type `bricks_template` n'est pas exposé à l'API REST standard : sans
	 * cette route, poser un en-tête ou un pied de page imposerait de passer par
	 * le builder, donc par un accès au site.
	 *
	 * L'appel est idempotent : un template du même nom et du même type est mis
	 * à jour plutôt que dupliqué — rejouer un déploiement ne crée pas de
	 * doublons.
	 */
	public function upsert_template( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( ! Bricks_Adapter::is_available() ) {
			return new \WP_Error( 'anode_bridge_no_bricks', 'Le thème Bricks n’est pas actif.', [ 'status' => 409 ] );
		}

		$title = sanitize_text_field( (string) $request->get_param( 'title' ) );
		$type  = sanitize_key( (string) $request->get_param( 'type' ) );

		$allowed = [ 'header', 'footer', 'section', 'content', 'archive', 'single', 'popup', 'error', 'search' ];

		if ( ! in_array( $type, $allowed, true ) ) {
			return new \WP_Error(
				'anode_bridge_invalid',
				sprintf( 'Type de template inconnu : « %s ». Valeurs possibles : %s.', $type, implode( ', ', $allowed ) ),
				[ 'status' => 400 ]
			);
		}

		$elements = [];

		if ( null !== $request->get_param( 'elements' ) ) {
			$elements = Validator::elements( $request->get_param( 'elements' ) );

			if ( $elements instanceof \WP_Error ) {
				return $elements;
			}
		}

		// Recherche d'un template existant de même nom et de même type.
		$existing = get_posts(
			[
				'post_type'      => Bricks_Adapter::POST_TYPE_TEMPLATE,
				'title'          => $title,
				'posts_per_page' => 1,
				'post_status'    => [ 'publish', 'draft' ],
				'meta_query'     => [
					[ 'key' => Bricks_Adapter::META_TEMPLATE_TYPE, 'value' => $type ],
				],
			]
		);

		$created = ! $existing;

		if ( $existing ) {
			$template_id = $existing[0]->ID;
		} else {
			$template_id = wp_insert_post(
				[
					'post_type'   => Bricks_Adapter::POST_TYPE_TEMPLATE,
					'post_title'  => $title,
					'post_status' => 'publish',
				],
				true
			);

			if ( is_wp_error( $template_id ) ) {
				return new \WP_Error( 'anode_bridge_failed', $template_id->get_error_message(), [ 'status' => 500 ] );
			}

			$template_id = (int) $template_id;
		}

		update_post_meta( $template_id, Bricks_Adapter::META_TEMPLATE_TYPE, $type );

		// Un en-tête et un pied de page se rangent dans la zone qui porte leur
		// nom ; les autres types vivent dans la zone de contenu.
		$area = in_array( $type, [ 'header', 'footer' ], true ) ? $type : 'content';

		if ( $elements ) {
			Bricks_Adapter::set_elements( $template_id, $elements, $area );
		}

		/*
		 * Sans condition d'affichage, Bricks n'applique le template nulle part.
		 * La valeur « any » est celle que Bricks attend pour « tout le site »
		 * (includes/templates.php, case 'any') — vérifiée dans le code du
		 * thème, l'intitulé de l'interface n'est pas la clé stockée.
		 */
		$conditions = $request->get_param( 'conditions' );

		if ( ! is_array( $conditions ) || ! $conditions ) {
			$conditions = [ [ 'main' => 'any' ] ];
		}

		$settings          = get_post_meta( $template_id, Bricks_Adapter::META_TEMPLATE_SETTINGS, true );
		$settings          = is_array( $settings ) ? $settings : [];
		$settings['templateConditions'] = $conditions;

		update_post_meta( $template_id, Bricks_Adapter::META_TEMPLATE_SETTINGS, $settings );

		Bricks_Adapter::regenerate_css( $template_id );

		Security::log(
			'bricks.template.upsert',
			[ 'id' => $template_id, 'type' => $type, 'created' => $created, 'count' => count( $elements ) ]
		);

		return rest_ensure_response(
			[
				'id'      => $template_id,
				'title'   => $title,
				'type'    => $type,
				'area'    => $area,
				'created' => $created,
				'count'   => count( $elements ),
				'edit_url' => $this->builder_url( $template_id ),
				'message' => sprintf(
					'Template « %s » (%s) %s avec %d élément(s).',
					$title,
					$type,
					$created ? 'créé' : 'mis à jour',
					count( $elements )
				),
			]
		);
	}

	/* ------------------------------------------------------------------ */
	/* Utilitaires                                                         */
	/* ------------------------------------------------------------------ */

	/**
	 * Fusionne deux listes indexées par « name ».
	 *
	 * L'entrée existante est remplacée par la nouvelle, en conservant son `id`
	 * d'origine : les éléments Bricks référencent les classes par identifiant,
	 * changer l'id casserait toutes les pages qui l'utilisent.
	 *
	 * @param array<int, array<string, mixed>> $existing Liste en base.
	 * @param array<int, array<string, mixed>> $incoming Liste entrante.
	 *
	 * @return array{0: array<int, array<string, mixed>>, 1: int, 2: int}
	 */
	private function merge_by_name( array $existing, array $incoming ): array {
		$index = [];

		foreach ( $existing as $position => $entry ) {
			if ( isset( $entry['name'] ) ) {
				$index[ $entry['name'] ] = $position;
			}
		}

		$added   = 0;
		$updated = 0;

		foreach ( $incoming as $entry ) {
			$name = $entry['name'];

			if ( isset( $index[ $name ] ) ) {
				$entry['id']                = $existing[ $index[ $name ] ]['id'] ?? $entry['id'];
				$existing[ $index[ $name ] ] = $entry;
				++$updated;
			} else {
				$existing[]     = $entry;
				$index[ $name ] = count( $existing ) - 1;
				++$added;
			}
		}

		return [ array_values( $existing ), $added, $updated ];
	}

	private function require_post( int $post_id ): \WP_Post|\WP_Error {
		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post ) {
			return new \WP_Error(
				'anode_bridge_not_found',
				sprintf( 'Aucun contenu avec l’identifiant %d.', $post_id ),
				[ 'status' => 404 ]
			);
		}

		return $post;
	}

	private function builder_url( int $post_id ): string {
		return add_query_arg(
			[
				'bricks' => 'run',
				'p'      => $post_id,
			],
			get_permalink( $post_id ) ?: home_url()
		);
	}
}
