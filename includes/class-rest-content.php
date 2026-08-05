<?php
/**
 * Endpoints de contenu complémentaires à wp/v2.
 *
 * wp/v2 couvre déjà le CRUD des pages, articles et médias. On ajoute ici ce
 * qui manque au quotidien : résoudre une URL en identifiant, dupliquer une
 * page avec sa mise en page Bricks, lire l'arborescence, et piloter les
 * réglages de site strictement listés.
 *
 * @package Anode\Bridge
 */

declare( strict_types = 1 );

namespace Anode\Bridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Rest_Content {

	/**
	 * Réglages modifiables via le pont.
	 *
	 * Liste blanche volontairement courte : le MCP est fourni aux clients, on
	 * n'ouvre que ce qui est utile à la construction du site.
	 *
	 * @var array<string, string> option => type
	 */
	private const WRITABLE_OPTIONS = [
		'blogname'        => 'string',
		'blogdescription' => 'string',
		'page_on_front'   => 'integer',
		'page_for_posts'  => 'integer',
		'show_on_front'   => 'string',
		'posts_per_page'  => 'integer',
		'date_format'     => 'string',
		'time_format'     => 'string',
		'start_of_week'   => 'integer',

		/*
		 * Le favicon — identifiant d'un média de la bibliothèque.
		 *
		 * Il manquait, et le manque ne se voyait pas : WordPress n'émet
		 * simplement aucune balise `icon`, le navigateur affiche son icône par
		 * défaut, et aucune capture ni aucun audit du dépôt ne le relève.
		 * Mesuré le 02/08/2026 sur preprod.agence-anode.fr — `site_icon` à 0.
		 *
		 * Sans cette entrée, le poser demandait du SSH : c'est un réglage de
		 * construction du site, il relève du MCP (§11).
		 */
		'site_icon'       => 'integer',

		// Réglages SEO par défaut, posés par l'extension anode-seo. Ils
		// s'appliquent à toutes les pages : les ouvrir ici évite d'avoir à
		// répéter le même titre ou la même image sur chacune.
		'anode_seo_title_template'      => 'string',
		'anode_seo_default_description' => 'string',
		'anode_seo_social_image'        => 'string',
		'anode_seo_locale'              => 'string',
		'anode_seo_indexable'           => 'string',
	];

	public function register_routes(): void {
		register_rest_route(
			NAMESPACE_,
			'/content/resolve',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'resolve' ],
				'permission_callback' => [ Security::class, 'permission_read' ],
				'args'                => [
					'path' => [
						'required'    => true,
						'type'        => 'string',
						'description' => 'URL complète ou chemin relatif (ex. « /nos-services/audit »).',
					],
				],
			]
		);

		register_rest_route(
			NAMESPACE_,
			'/content/tree',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'tree' ],
				'permission_callback' => [ Security::class, 'permission_read' ],
				'args'                => [
					'post_type' => [ 'type' => 'string', 'default' => 'page' ],
				],
			]
		);

		register_rest_route(
			NAMESPACE_,
			'/content/(?P<id>\d+)/duplicate',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'duplicate' ],
				'permission_callback' => [ Security::class, 'permission_write' ],
				'args'                => [
					'id'     => [ 'required' => true, 'type' => 'integer' ],
					'title'  => [ 'type' => 'string' ],
					'slug'   => [ 'type' => 'string' ],
					'status' => [ 'type' => 'string', 'enum' => [ 'draft', 'publish', 'private' ], 'default' => 'draft' ],
				],
			]
		);

		register_rest_route(
			NAMESPACE_,
			'/settings',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_settings' ],
					'permission_callback' => [ Security::class, 'permission_read' ],
				],
				[
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => [ $this, 'update_settings' ],
					'permission_callback' => [ Security::class, 'permission_manage' ],
				],
			]
		);

		register_rest_route(
			NAMESPACE_,
			'/menus',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_menus' ],
				'permission_callback' => [ Security::class, 'permission_read' ],
			]
		);
	}

	/**
	 * Résout une URL ou un chemin en identifiant de contenu.
	 */
	public function resolve( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$path = trim( (string) $request->get_param( 'path' ) );
		$url  = str_starts_with( $path, 'http' ) ? $path : home_url( '/' . ltrim( $path, '/' ) );

		$post_id = url_to_postid( $url );

		if ( ! $post_id ) {
			// url_to_postid ne gère pas la page d'accueil statique.
			$home = untrailingslashit( home_url() );

			if ( untrailingslashit( $url ) === $home && 'page' === get_option( 'show_on_front' ) ) {
				$post_id = (int) get_option( 'page_on_front' );
			}
		}

		if ( ! $post_id ) {
			return new \WP_Error(
				'anode_bridge_not_found',
				sprintf( 'Aucun contenu ne correspond à « %s ».', $path ),
				[ 'status' => 404 ]
			);
		}

		$post = get_post( $post_id );

		return rest_ensure_response(
			[
				'id'          => $post_id,
				'title'       => $post->post_title,
				'slug'        => $post->post_name,
				'post_type'   => $post->post_type,
				'status'      => $post->post_status,
				'permalink'   => get_permalink( $post_id ),
				'editor_mode' => get_post_meta( $post_id, Bricks_Adapter::META_EDITOR_MODE, true ) ?: 'wordpress',
			]
		);
	}

	/**
	 * Vérifie qu'un type de contenu a vocation à passer par le pont.
	 *
	 * Le pont est un outil éditorial : il expose ce que WordPress expose
	 * lui-même à son API. Un type déclaré `show_in_rest => false` est un type
	 * que le site garde pour lui — un journal, une file de traitement, des
	 * demandes reçues par formulaire. Sans ce contrôle, il suffisait de
	 * demander `post_type=anode_soumission` pour lister toutes les demandes
	 * d'un site, adresses e-mail comprises, avec un simple compte d'édition.
	 */
	public static function readable_post_type( string $post_type ): \WP_Post_Type|\WP_Error {
		$object = get_post_type_object( $post_type );

		if ( ! $object instanceof \WP_Post_Type ) {
			return new \WP_Error(
				'anode_bridge_unknown_type',
				sprintf( 'Type de contenu inconnu : « %s ».', $post_type ),
				[ 'status' => 404 ]
			);
		}

		if ( empty( $object->show_in_rest ) ) {
			return new \WP_Error(
				'anode_bridge_private_type',
				sprintf(
					'Le type « %s » n’est pas exposé à l’API REST par ce site : le pont ne le sert pas non plus. '
						. 'Consultez-le depuis l’administration.',
					$post_type
				),
				[ 'status' => 403 ]
			);
		}

		return $object;
	}

	/**
	 * Arborescence des pages, avec indication du mode d'édition.
	 */
	public function tree( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$post_type = sanitize_key( (string) $request->get_param( 'post_type' ) );
		$objet     = self::readable_post_type( $post_type );

		if ( $objet instanceof \WP_Error ) {
			return $objet;
		}

		$posts = get_posts(
			[
				'post_type'      => $post_type,
				'posts_per_page' => 300,
				'post_status'    => [ 'publish', 'draft', 'private', 'pending' ],
				'orderby'        => [ 'menu_order' => 'ASC', 'title' => 'ASC' ],
			]
		);

		$front = (int) get_option( 'page_on_front' );
		$blog  = (int) get_option( 'page_for_posts' );
		$nodes = [];

		foreach ( $posts as $post ) {
			$nodes[] = [
				'id'          => $post->ID,
				'title'       => $post->post_title,
				'slug'        => $post->post_name,
				'parent'      => $post->post_parent,
				'status'      => $post->post_status,
				'menu_order'  => $post->menu_order,
				'permalink'   => get_permalink( $post->ID ),
				'editor_mode' => get_post_meta( $post->ID, Bricks_Adapter::META_EDITOR_MODE, true ) ?: 'wordpress',
				'is_front'    => $post->ID === $front,
				'is_blog'     => $post->ID === $blog,
			];
		}

		return rest_ensure_response(
			[
				'post_type' => $post_type,
				'count'     => count( $nodes ),
				'items'     => $nodes,
			]
		);
	}

	/**
	 * Duplique un contenu avec sa mise en page Bricks et ses réglages.
	 */
	public function duplicate( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$source_id = (int) $request->get_param( 'id' );
		$source    = get_post( $source_id );

		if ( ! $source instanceof \WP_Post ) {
			return new \WP_Error( 'anode_bridge_not_found', sprintf( 'Aucun contenu avec l’identifiant %d.', $source_id ), [ 'status' => 404 ] );
		}

		if ( ! current_user_can( 'edit_post', $source_id ) ) {
			return new \WP_Error( 'anode_bridge_forbidden', 'Vous ne pouvez pas dupliquer ce contenu.', [ 'status' => 403 ] );
		}

		$title = $request->get_param( 'title' );
		$title = is_string( $title ) && '' !== $title ? $title : $source->post_title . ' (copie)';

		$new_id = wp_insert_post(
			[
				'post_title'     => sanitize_text_field( $title ),
				'post_name'      => sanitize_title( (string) ( $request->get_param( 'slug' ) ?: '' ) ) ?: '',
				'post_content'   => $source->post_content,
				'post_excerpt'   => $source->post_excerpt,
				'post_status'    => (string) $request->get_param( 'status' ),
				'post_type'      => $source->post_type,
				'post_parent'    => $source->post_parent,
				'menu_order'     => $source->menu_order,
				'comment_status' => $source->comment_status,
				'ping_status'    => $source->ping_status,
			],
			true
		);

		if ( is_wp_error( $new_id ) ) {
			return new \WP_Error( 'anode_bridge_duplicate_failed', $new_id->get_error_message(), [ 'status' => 500 ] );
		}

		$new_id = (int) $new_id;

		// Métadonnées Bricks : c'est ce qui fait de la copie une vraie copie.
		$copied = 0;

		foreach ( array_merge( array_values( Bricks_Adapter::AREAS ), [ Bricks_Adapter::META_SETTINGS, Bricks_Adapter::META_EDITOR_MODE ] ) as $meta_key ) {
			$value = get_post_meta( $source_id, $meta_key, true );

			if ( '' !== $value && [] !== $value ) {
				update_post_meta( $new_id, $meta_key, $value );
				++$copied;
			}
		}

		// Taxonomies et image mise en avant.
		foreach ( get_object_taxonomies( $source->post_type ) as $taxonomy ) {
			$terms = wp_get_object_terms( $source_id, $taxonomy, [ 'fields' => 'ids' ] );

			if ( ! is_wp_error( $terms ) && $terms ) {
				wp_set_object_terms( $new_id, $terms, $taxonomy );
			}
		}

		$thumbnail = get_post_thumbnail_id( $source_id );

		if ( $thumbnail ) {
			set_post_thumbnail( $new_id, $thumbnail );
		}

		Bricks_Adapter::regenerate_css( $new_id );

		Security::log( 'content.duplicate', [ 'source' => $source_id, 'target' => $new_id ] );

		return rest_ensure_response(
			[
				'id'        => $new_id,
				'title'     => get_the_title( $new_id ),
				'status'    => get_post_status( $new_id ),
				'permalink' => get_permalink( $new_id ),
				'meta_copied' => $copied,
				'message'   => sprintf( '« %s » dupliqué (identifiant %d).', $source->post_title, $new_id ),
			]
		);
	}

	public function get_settings(): \WP_REST_Response {
		$settings = [];

		foreach ( array_keys( self::WRITABLE_OPTIONS ) as $option ) {
			$settings[ $option ] = get_option( $option );
		}

		$settings['permalink_structure'] = get_option( 'permalink_structure' );
		$settings['site_url']            = site_url();
		$settings['home_url']            = home_url();

		return rest_ensure_response( $settings );
	}

	public function update_settings( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$updated  = [];
		$rejected = [];

		foreach ( $request->get_json_params() ?: [] as $option => $value ) {
			if ( ! isset( self::WRITABLE_OPTIONS[ $option ] ) ) {
				$rejected[] = $option;
				continue;
			}

			$value = 'integer' === self::WRITABLE_OPTIONS[ $option ]
				? (int) $value
				: sanitize_text_field( (string) $value );

			update_option( $option, $value );
			$updated[ $option ] = $value;
		}

		if ( ! $updated && $rejected ) {
			return new \WP_Error(
				'anode_bridge_invalid',
				sprintf( 'Réglage(s) non modifiable(s) via le pont : %s.', implode( ', ', $rejected ) ),
				[ 'status' => 400 ]
			);
		}

		Security::log( 'settings.update', [ 'updated' => array_keys( $updated ) ] );

		return rest_ensure_response(
			[
				'updated'  => $updated,
				'rejected' => $rejected,
				'message'  => sprintf( '%d réglage(s) mis à jour.', count( $updated ) ),
			]
		);
	}

	/**
	 * Menus de navigation et emplacements du thème.
	 */
	public function get_menus(): \WP_REST_Response {
		$menus = [];

		foreach ( wp_get_nav_menus() as $menu ) {
			$items = [];

			foreach ( wp_get_nav_menu_items( $menu->term_id ) ?: [] as $item ) {
				$items[] = [
					'id'     => $item->ID,
					'title'  => $item->title,
					'url'    => $item->url,
					'parent' => (int) $item->menu_item_parent,
					'order'  => (int) $item->menu_order,
					'object' => $item->object,
					'object_id' => (int) $item->object_id,
				];
			}

			$menus[] = [
				'id'    => $menu->term_id,
				'name'  => $menu->name,
				'slug'  => $menu->slug,
				'count' => count( $items ),
				'items' => $items,
			];
		}

		return rest_ensure_response(
			[
				'menus'     => $menus,
				'locations' => get_nav_menu_locations(),
				'registered_locations' => get_registered_nav_menus(),
			]
		);
	}
}
