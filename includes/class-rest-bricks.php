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
						'overwrite' => [
							'type'        => 'boolean',
							'default'     => false,
							'description' => 'Écrase une zone modifiée à la main depuis notre dernière écriture. '
								. 'Sans lui, une telle zone fait répondre 409 : une modification faite à la main '
								. 'ne se perd pas sans qu’on le demande.',
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
					'args'                => [
						'with_usage' => [ 'type' => 'boolean', 'default' => false ],
					],
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
						'overwrite'  => [
							'type'        => 'boolean',
							'default'     => false,
							'description' => 'Écrase une classe modifiée dans le builder depuis notre dernière '
								. 'écriture. Sans lui, une telle classe fait répondre 409 — et l’appel remplace '
								. 'les réglages d’une classe sans les fusionner.',
						],
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
				'args'                => [
					'force' => [ 'type' => 'boolean', 'default' => false ],
				],
			]
		);

		register_rest_route(
			NAMESPACE_,
			'/bricks/custom-css',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_custom_css' ],
					'permission_callback' => [ Security::class, 'permission_read' ],
				],
				[
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => [ $this, 'update_custom_css' ],
					'permission_callback' => [ Security::class, 'permission_manage' ],
					'args'                => [
						'css' => [ 'required' => true, 'type' => 'string' ],
						'overwrite'  => [
							'type'        => 'boolean',
							'default'     => false,
							'description' => 'Écrase ce qui a été modifié à la main depuis notre dernière écriture. Sans lui, un tel écart fait répondre 409.',
						],
					],
				],
			]
		);

		register_rest_route(
			NAMESPACE_,
			'/bricks/controls',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_controls' ],
				'permission_callback' => [ Security::class, 'permission_read' ],
				'args'                => [
					'elements' => [
						'type'        => 'string',
						'description' => 'Types d’éléments, séparés par des virgules. Vide = ceux employés par le site.',
					],
				],
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
						'overwrite'  => [
							'type'        => 'boolean',
							'default'     => false,
							'description' => 'Écrase ce qui a été modifié à la main depuis notre dernière écriture. Sans lui, un tel écart fait répondre 409.',
						],
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

		/*
		 * Basculer une publication entre Bricks et l'éditeur de WordPress.
		 *
		 * `_bricks_editor_mode` est volontairement **non inscriptible** par
		 * `wp/v2` (voir `Plugin::expose_bricks_meta`) : un simple PUT sur cette
		 * méta ferait disparaître la mise en page d'une page qui n'a pas changé
		 * d'un octet. Le refus est la bonne conduite par défaut.
		 *
		 * Mais il n'existait alors **aucun** chemin pour la bascule légitime :
		 * reprendre cinq articles construits en Bricks pour que leur texte
		 * redevienne modifiable dans le back-office. Une opération nommée, avec
		 * sa garde, vaut mieux qu'une méta libre — ou qu'un contournement SSH.
		 *
		 * La garde : on refuse de passer à « wordpress » tant que `post_content`
		 * est vide. C'est exactement l'accident — le gabarit prend la main, et le
		 * visiteur reçoit une page sans contenu. Mesuré le 06/08/2026 : cinq
		 * articles servis avec un <main> de 539 octets.
		 */
		register_rest_route(
			NAMESPACE_,
			'/bricks/editor-mode',
			[
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'set_editor_mode' ],
					'permission_callback' => [ Security::class, 'permission_manage' ],
					'args'                => [
						'id'   => [ 'required' => true, 'type' => 'integer' ],
						'mode' => [
							'required' => true,
							'type'     => 'string',
							'enum'     => [ 'bricks', 'wordpress' ],
						],
					],
				],
			]
		);

		register_rest_route(
			NAMESPACE_,
			'/bricks/components',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'list_components' ],
					'permission_callback' => [ Security::class, 'permission_read' ],
					'args'                => [
						'with_elements' => [ 'type' => 'boolean', 'default' => false ],
					],
				],
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'upsert_component' ],
					'permission_callback' => [ Security::class, 'permission_manage' ],
					'args'                => [
						'overwrite' => [
							'type'        => 'boolean',
							'default'     => false,
							'description' => 'Écrase un composant modifié dans le builder depuis notre dernière '
								. 'écriture. Sans lui, un tel composant fait répondre 409 — sa définition décide '
								. 'du dessin de toutes ses instances.',
						],
						'label' => [
							'required'    => true,
							'type'        => 'string',
							'description' => 'Nom du composant. Un composant du même nom est mis à jour, pas dupliqué.',
						],
						'elements'   => [
							'required'    => true,
							'type'        => 'array',
							'description' => 'Sous-arbre du composant : un seul élément racine (parent = 0).',
						],
						'properties' => [
							'type'        => 'array',
							'description' => 'Propriétés variabilisées : { id, label, type, default, connections }.',
						],
						'category' => [ 'type' => 'string' ],
						'desc'     => [ 'type' => 'string' ],
					],
				],
			]
		);

		register_rest_route(
			NAMESPACE_,
			'/bricks/templates/(?P<id>\d+)',
			[
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => [ $this, 'delete_template' ],
				'permission_callback' => [ Security::class, 'permission_manage' ],
			]
		);

		register_rest_route(
			NAMESPACE_,
			'/bricks/components/(?P<label>[^/]+)',
			[
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => [ $this, 'delete_component' ],
				'permission_callback' => [ Security::class, 'permission_manage' ],
				'args'                => [
					'force' => [ 'type' => 'boolean', 'default' => false ],
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
						'overwrite' => [
							'type'        => 'boolean',
							'default'     => false,
							'description' => 'Écrase un template modifié à la main depuis notre dernière écriture. '
								. 'Sans lui, un tel template fait répondre 409.',
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

	/**
	 * Rien ne s'écrase sans qu'on le demande — la mise en page comprise.
	 *
	 * ## Pourquoi c'était le trou du dispositif
	 *
	 * Les commandes du dépôt ont leur garde depuis le 06/08/2026 (§10 bis) :
	 * `apply-pages`, `apply-posts` et `apply-composants` refusent devant un écart et
	 * nomment leurs sorties. Cette route, non — et c'est **la surface qu'un humain
	 * retouche le plus** : on ouvre Bricks, on déplace un bloc, on corrige un texte.
	 * La prochaine écriture l'effaçait sans un mot, sans même dire ce qui changeait.
	 *
	 * Ce n'est pas une négligence de mise en œuvre : c'était écrit noir sur blanc
	 * dans la documentation — « `bricks_set_page` écrase la zone visée » —, présenté
	 * comme une propriété de l'outil, avec pour seule parade une consigne de lire
	 * avant d'écrire. Une consigne demande ; du code empêche.
	 *
	 * ## La forme du refus
	 *
	 * Un **409**, pas un 403 : il ne manque aucun droit, il y a un désaccord d'état.
	 * Et le corps nomme les sorties, parce qu'un refus qui ne dit pas comment
	 * avancer se contourne au hasard. `data` porte les empreintes : c'est ce qui
	 * permet à un appelant de décider sans redemander la page.
	 *
	 * @param array<int, mixed> $servi     Contenu actuellement en place.
	 * @param bool              $demande   L'appelant demande-t-il l'écrasement ?
	 * @return true|\WP_Error
	 */
	private function refus_ecrasement( int $post_id, string $area, array $servi, bool $demande ) {
		if ( $demande ) {
			return true;
		}

		$retenue = Bricks_Adapter::empreinte_retenue( $post_id, $area );
		$servie  = Bricks_Adapter::empreinte( $servi );
		$verdict = Bricks_Adapter::verdict( $retenue, $servie, ! $servi );

		if ( $verdict['ecrire'] ) {
			return true;
		}

		$explication = 'modifiee-a-la-main' === $verdict['motif']
			? sprintf(
				'La zone « %s » a été modifiée depuis notre dernière écriture — dans Bricks, '
					. 'ou dans le back-office. Écrire maintenant effacerait ce travail.',
				$area
			)
			: sprintf(
				'La zone « %s » porte déjà %d élément(s) que nous n’avons pas écrits : nous n’avons '
					. 'aucune empreinte pour elle. Leur provenance est inconnue, donc écrire serait '
					. 'effacer un travail dont on ignore l’auteur.',
				$area,
				count( $servi )
			);

		return new \WP_Error(
			'anode_bridge_ecrasement',
			$explication . ' Rien n’a été écrit.'
				. ' Trois sorties : lire la zone et repartir de là (bricks_get_page) ;'
				. ' écraser en le demandant (overwrite: true) ;'
				. ' ou ne rien faire — l’écart reste, et il est nommé.',
			[
				'status'             => 409,
				'motif'              => $verdict['motif'],
				'area'               => $area,
				'empreinte_retenue'  => $retenue,
				'empreinte_servie'   => $servie,
				'elements_en_place'  => count( $servi ),
			]
		);
	}

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

		$refus = $this->refus_ecrasement( $post_id, $area, $previous, (bool) $request->get_param( 'overwrite' ) );

		if ( $refus instanceof \WP_Error ) {
			return $refus;
		}

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

	public function get_classes( ?\WP_REST_Request $request = null ): \WP_REST_Response {
		$classes = Bricks_Adapter::get_global_classes();

		$sortie = [
			'count'      => count( $classes ),
			'classes'    => $classes,
			'categories' => Bricks_Adapter::get_class_categories(),
		];

		/*
		 * Sur quels types d'éléments chaque classe est-elle posée ?
		 *
		 * Bricks émet le CSS d'une classe globale **par type d'élément**, et
		 * seulement pour les contrôles que ce type déclare. Un réglage qu'un des
		 * types ne connaît pas ne sortira que sur les autres — le style disparaît
		 * alors sur une partie des instances, sans erreur.
		 */
		if ( $request && $request->get_param( 'with_usage' ) ) {
			$sortie['usage'] = $this->class_usage( $classes );
		}

		return rest_ensure_response( $sortie );
	}

	/**
	 * Index nom de classe → types d'éléments qui la portent.
	 *
	 * @param array<int, array<string, mixed>> $classes Classes globales.
	 *
	 * @return array<string, array<int, string>>
	 */
	private function class_usage( array $classes ): array {
		$noms = [];

		foreach ( $classes as $classe ) {
			if ( ! empty( $classe['id'] ) && ! empty( $classe['name'] ) ) {
				$noms[ $classe['id'] ] = $classe['name'];
			}
		}

		$usage = [];

		$compter = static function ( $element ) use ( $noms, &$usage ): void {
			if ( ! is_array( $element ) ) {
				return;
			}

			$type = $element['name'] ?? '';

			if ( ! is_string( $type ) || '' === $type ) {
				return;
			}

			$ids = $element['settings']['_cssGlobalClasses'] ?? [];

			if ( ! is_array( $ids ) ) {
				return;
			}

			foreach ( $ids as $id ) {
				if ( is_string( $id ) && isset( $noms[ $id ] ) ) {
					$usage[ $noms[ $id ] ][ $type ] = true;
				}
			}
		};

		foreach ( $this->all_bricks_post_ids() as $post_id ) {
			foreach ( array_keys( Bricks_Adapter::AREAS ) as $area ) {
				foreach ( Bricks_Adapter::get_elements( $post_id, $area ) as $element ) {
					$compter( $element );
				}
			}
		}

		// Les éléments d'un composant ne sont dans aucune page : sans eux, une
		// classe employée uniquement dans un composant paraîtrait orpheline.
		foreach ( Bricks_Adapter::get_components() as $component ) {
			$elements = is_array( $component['elements'] ?? null ) ? $component['elements'] : [];

			foreach ( $elements as $element ) {
				$compter( $element );
			}

			/*
			 * Les classes d'une propriété de type « class » n'apparaissent nulle
			 * part dans `_cssGlobalClasses` : Bricks les ajoute au rendu, depuis
			 * l'option choisie par l'instance. Sans ce relevé, une classe de
			 * variante — `c-button--glass`, `is-patio` — passe pour inutilisée, et
			 * aucun réglage ne lui est proposé.
			 */
			foreach ( is_array( $component['properties'] ?? null ) ? $component['properties'] : [] as $propriete ) {
				if ( 'class' !== ( $propriete['type'] ?? '' ) ) {
					continue;
				}

				$ids = [];

				foreach ( (array) ( $propriete['default'] ?? [] ) as $id ) {
					$ids[] = $id;
				}

				foreach ( is_array( $propriete['options'] ?? null ) ? $propriete['options'] : [] as $option ) {
					foreach ( (array) ( $option['value'] ?? [] ) as $id ) {
						$ids[] = $id;
					}
				}

				// Les types d'éléments concernés sont ceux que la propriété relie.
				$types = [];

				foreach ( array_keys( is_array( $propriete['connections'] ?? null ) ? $propriete['connections'] : [] ) as $element_id ) {
					foreach ( $elements as $element ) {
						if ( is_array( $element ) && (string) ( $element['id'] ?? '' ) === (string) $element_id ) {
							$types[] = $element['name'] ?? '';
						}
					}
				}

				foreach ( $ids as $id ) {
					if ( ! is_string( $id ) || ! isset( $noms[ $id ] ) ) {
						continue;
					}

					foreach ( $types as $type ) {
						if ( is_string( $type ) && '' !== $type ) {
							$usage[ $noms[ $id ] ][ $type ] = true;
						}
					}
				}
			}
		}

		foreach ( $usage as $nom => $types ) {
			$usage[ $nom ] = array_keys( $types );
			sort( $usage[ $nom ] );
		}

		ksort( $usage );

		return $usage;
	}

	/**
	 * Les empreintes d'une liste d'entrées nommées — variables, et tout ce qui suit
	 * la même forme.
	 *
	 * @param array<int, array<string, mixed>> $entrees
	 * @return array<string, string>
	 */
	private static function empreintes_nommees( array $entrees, string $genre ): array {
		$couples = [];

		foreach ( $entrees as $entree ) {
			if ( ! isset( $entree['name'] ) ) {
				continue;
			}

			$couples[ $genre . ':' . (string) $entree['name'] ] = Bricks_Adapter::empreinte( $entree );
		}

		return $couples;
	}

	/**
	 * Refuse d'écraser une variable retouchée dans le builder.
	 *
	 * @param array<int, array<string, mixed>> $incoming
	 * @return true|\WP_Error
	 */
	private function refus_variables( array $incoming, bool $demande ) {
		if ( $demande ) {
			return true;
		}

		$index = [];

		foreach ( Bricks_Adapter::get_variables() as $variable ) {
			if ( isset( $variable['name'] ) ) {
				$index[ (string) $variable['name'] ] = $variable;
			}
		}

		$fautives = [];
		$motif    = 'modifiee-a-la-main';

		foreach ( $incoming as $entree ) {
			$nom = (string) ( $entree['name'] ?? '' );

			if ( '' === $nom || ! isset( $index[ $nom ] ) ) {
				continue;
			}

			// Une variable dont la valeur est déjà celle qu'on veut écrire n'est pas un
			// conflit : c'est le cas courant d'un `ds_apply` rejoué, et refuser là
			// rendrait l'outil inutilisable.
			if ( Bricks_Adapter::empreinte( $index[ $nom ] ) === Bricks_Adapter::empreinte( $entree ) ) {
				continue;
			}

			$verdict = Empreintes::verdict( 'variable:' . $nom, $index[ $nom ] );

			if ( ! $verdict['ecrire'] ) {
				$fautives[] = $nom;
				$motif      = $verdict['motif'];
			}
		}

		return $fautives ? Empreintes::refus( $fautives, 'variable(s)', $motif ) : true;
	}

	/** Clé d'empreinte d'une classe globale. */
	private static function cle_de_classe( string $nom ): string {
		return 'classe:' . $nom;
	}

	/**
	 * Les empreintes de toutes les classes d'une liste.
	 *
	 * @param array<int, array<string, mixed>> $classes
	 * @return array<string, string>
	 */
	private static function empreintes_des_classes( array $classes ): array {
		$couples = [];

		foreach ( $classes as $classe ) {
			if ( ! isset( $classe['name'] ) ) {
				continue;
			}

			$couples[ self::cle_de_classe( (string) $classe['name'] ) ] = Bricks_Adapter::empreinte( $classe );
		}

		return $couples;
	}

	/**
	 * Refuse d'écraser une classe retouchée à la main.
	 *
	 * Une classe **absente** du site n'est pas concernée : la créer n'efface rien.
	 * Une classe présente **sans réglages** ne l'est pas non plus — c'est une
	 * coquille créée par `create_missing_classes`, et l'habiller est précisément ce
	 * qu'on attend (§15 bis).
	 *
	 * @param array<int, array<string, mixed>> $incoming
	 * @return true|\WP_Error
	 */
	private function refus_classes( array $incoming, bool $demande ) {
		if ( $demande ) {
			return true;
		}

		$index = [];

		foreach ( Bricks_Adapter::get_global_classes() as $classe ) {
			if ( isset( $classe['name'] ) ) {
				$index[ (string) $classe['name'] ] = $classe;
			}
		}

		$fautives = [];
		$motif    = 'modifiee-a-la-main';

		foreach ( $incoming as $entree ) {
			$nom = (string) ( $entree['name'] ?? '' );

			if ( '' === $nom || ! isset( $index[ $nom ] ) ) {
				continue;
			}

			$servie = $index[ $nom ];

			// Une coquille vide n'a rien à perdre : c'est le cas normal après
			// `create_missing_classes`, et refuser ici bloquerait tout habillage.
			if ( empty( $servie['settings'] ) ) {
				continue;
			}

			$verdict = Empreintes::verdict( self::cle_de_classe( $nom ), $servie );

			if ( ! $verdict['ecrire'] ) {
				$fautives[] = $nom;
				$motif      = $verdict['motif'];
			}
		}

		return $fautives ? Empreintes::refus( $fautives, 'classe(s)', $motif ) : true;
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

		/*
		 * Les catégories sont validées **avant** la moindre écriture : elles
		 * l'étaient après celle des classes, si bien qu'une catégorie mal formée
		 * renvoyait un 400 — donc « rien n'a été fait » — alors que les classes
		 * étaient déjà en base, et sans une ligne de journal pour le dire.
		 */
		$categories = null;

		if ( is_array( $request->get_param( 'categories' ) ) ) {
			$categories = Validator::categories( $request->get_param( 'categories' ) );

			if ( $categories instanceof \WP_Error ) {
				return $categories;
			}
		}

		/*
		 * Rien ne s'écrase sans qu'on le demande — les classes aussi (§10 bis).
		 *
		 * `merge_by_name` **réassigne l'entrée entière** et ne conserve que son `id` :
		 * envoyer une classe avec un seul réglage efface tous les autres, et l'appel
		 * répond « 1 mise(s) à jour ». Le piège est documenté depuis longtemps, avec
		 * pour parade une consigne — lire les réglages par la route REST avant
		 * d'écrire. Une consigne demande ; du code empêche.
		 *
		 * La garde est **par classe** : refuser celle-là sans bloquer les quarante
		 * autres d'une même passe. Une garde qui empêche le travail normal est une
		 * garde qu'on désactive.
		 */
		$refus = $this->refus_classes( $incoming, (bool) $request->get_param( 'overwrite' ) );

		if ( $refus instanceof \WP_Error ) {
			return $refus;
		}

		if ( 'replace' === $mode ) {
			/*
			 * Même en remplacement, une classe reconnue par son nom garde son
			 * identifiant : les éléments des pages désignent les classes par
			 * identifiant, et le validateur en génère un neuf dès qu'il n'en reçoit
			 * pas. La liste entrante écrite telle quelle détachait donc chaque
			 * classe de toutes ses pages — elles perdaient leurs styles sans avoir
			 * changé, et le compte rendu annonçait un ajout.
			 */
			[ $result, $added, $updated ] = $this->keep_existing_ids( Bricks_Adapter::get_global_classes(), $incoming );
		} else {
			[ $result, $added, $updated ] = $this->merge_by_name( Bricks_Adapter::get_global_classes(), $incoming );
		}

		Bricks_Adapter::set_global_classes( $result );

		/*
		 * On retient ce qu'on vient d'écrire, dans le même geste — sinon la garde
		 * criera au passage suivant sur une classe que personne n'a touchée, et un
		 * faux positif apprend à passer outre.
		 *
		 * On relit `$result` plutôt que `$incoming` : c'est ce qui est réellement en
		 * base, `id` repris compris. Retenir l'entrant produirait une empreinte que
		 * la lecture suivante ne retrouverait jamais.
		 */
		Empreintes::retenir( self::empreintes_des_classes( $result ) );

		if ( null !== $categories ) {
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

	/**
	 * Supprime une classe globale, sauf si elle est encore posée quelque part.
	 *
	 * Le refus manquait, là où la suppression d'un composant employé répond 409 :
	 * les deux cas sont pourtant le même. Une classe retirée de la liste globale
	 * disparaît du balisage des éléments qui la référencent — ils perdent leur
	 * style, et aucune page n'a changé.
	 */
	public function delete_class( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$name    = urldecode( (string) $request->get_param( 'name' ) );
		$classes = Bricks_Adapter::get_global_classes();
		$kept    = [];
		$removed = 0;
		$cibles  = [];

		foreach ( $classes as $class ) {
			if ( ( $class['name'] ?? '' ) === $name || ( $class['id'] ?? '' ) === $name ) {
				++$removed;
				$cibles[] = (string) ( $class['name'] ?? '' );
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

		if ( ! $request->get_param( 'force' ) ) {
			$usage    = $this->class_usage( $classes );
			$employee = [];

			foreach ( $cibles as $cible ) {
				if ( ! empty( $usage[ $cible ] ) ) {
					$employee[] = sprintf( '« %s » sur %s', $cible, implode( ', ', $usage[ $cible ] ) );
				}
			}

			if ( $employee ) {
				return new \WP_Error(
					'anode_bridge_conflict',
					sprintf(
						'Classe encore employée : %s. Les éléments qui la portent perdraient son style sans qu’aucune '
						. 'page ait changé. Retirez-la de ces éléments d’abord, ou passez force = true.',
						implode( ' ; ', $employee )
					),
					[ 'status' => 409 ]
				);
			}
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

		// Même ordre qu'au-dessus, pour la même raison : on valide tout avant
		// d'écrire quoi que ce soit.
		$categories = null;

		if ( is_array( $request->get_param( 'categories' ) ) ) {
			$categories = Validator::categories( $request->get_param( 'categories' ) );

			if ( $categories instanceof \WP_Error ) {
				return $categories;
			}
		}

		/*
		 * Même garde que les classes (§10 bis). La §9 dit qu'on ne modifie jamais une
		 * variable dans le builder — mais elle le dit à nous, et un client qui achète
		 * son site n'a pas lu la §9. `ds_apply` reviendrait alors sur sa couleur, et
		 * la seule trace serait un différentiel que personne ne relit.
		 */
		$refus = $this->refus_variables( $incoming, (bool) $request->get_param( 'overwrite' ) );

		if ( $refus instanceof \WP_Error ) {
			return $refus;
		}

		if ( 'replace' === $mode ) {
			$result  = $incoming;
			$added   = count( $incoming );
			$updated = 0;
		} else {
			[ $result, $added, $updated ] = $this->merge_by_name( Bricks_Adapter::get_variables(), $incoming );
		}

		Bricks_Adapter::set_variables( $result );

		Empreintes::retenir( self::empreintes_nommees( $result, 'variable' ) );

		if ( null !== $categories ) {
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

		/*
		 * Troisième argument à `null` — « ne change pas l'autoload » : Bricks lit
		 * ses réglages et ses points de rupture à **chaque** requête du site, et le
		 * `false` posé ici les sortait du chargement automatique. Chaque page payait
		 * alors deux requêtes de plus, pour une écriture qui n'a rien à dire de la
		 * façon dont l'option est lue.
		 */
		update_option( Bricks_Adapter::OPT_GLOBAL_SETTINGS, $settings, null );
		update_option( Bricks_Adapter::OPT_BREAKPOINTS, array_values( $current ), null );

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
	/* CSS personnalisé global                                             */
	/* ------------------------------------------------------------------ */

	public function get_custom_css(): \WP_REST_Response {
		$reglages = get_option( Bricks_Adapter::OPT_GLOBAL_SETTINGS, [] );
		$css      = is_array( $reglages ) && isset( $reglages['customCss'] ) ? (string) $reglages['customCss'] : '';

		return rest_ensure_response(
			[
				// Ce que Bricks servira : les contre-obliques sont retirées à la
				// lecture (Database::$global_data['settings'] passe par
				// stripslashes_deep), donc on rend la valeur telle qu'elle sortira.
				'css'    => stripslashes( $css ),
				'length' => strlen( $css ),
			]
		);
	}

	/**
	 * Écrit le CSS personnalisé global — celui qu'on édite dans les réglages Bricks.
	 *
	 * C'est le seul endroit qui garde l'**ordre** d'un ensemble de règles. Le CSS
	 * personnalisé d'une classe, lui, est émis à la position où Bricks rencontre
	 * cette classe en rendant la page : deux règles à spécificité égale portées
	 * par deux classes différentes ne se départagent plus par l'ordre des
	 * feuilles, mais par l'ordre de rendu. Pour un portage, la nuance décide du
	 * résultat.
	 *
	 * Piège : Bricks retire les contre-obliques à la lecture. Un `content: "\2014"`
	 * arriverait en `"2014"`. On stocke donc la valeur pré-échappée, comme le fait
	 * l'écran d'administration.
	 */
	public function update_custom_css( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$css = (string) $request->get_param( 'css' );

		$ouvrantes = substr_count( $css, '{' );
		$fermantes = substr_count( $css, '}' );

		if ( $ouvrantes !== $fermantes ) {
			return new \WP_Error(
				'anode_bridge_invalid',
				sprintf(
					'CSS déséquilibré : %d « { » pour %d « } ». Bricks écarterait tout le bloc.',
					$ouvrantes,
					$fermantes
				),
				[ 'status' => 400 ]
			);
		}

		if ( false !== strpos( $css, '%root%' ) ) {
			return new \WP_Error(
				'anode_bridge_invalid',
				'« %root% » n’est résolu que par le builder : écrivez le sélecteur réel.',
				[ 'status' => 400 ]
			);
		}

		$reglages = get_option( Bricks_Adapter::OPT_GLOBAL_SETTINGS, [] );
		$reglages = is_array( $reglages ) ? $reglages : [];

		/*
		 * Le CSS global se garde **en bloc** : il est écrit en bloc, et c'est là que
		 * vivent les règles qu'aucun contrôle de panneau ne porte (§5, rang 4). Une
		 * règle ajoutée à la main depuis le builder y est donc parfaitement
		 * légitime — et c'était exactement ce qu'une réécriture effaçait.
		 */
		$actuel = (string) wp_unslash( (string) ( $reglages['customCss'] ?? '' ) );

		if ( ! (bool) $request->get_param( 'overwrite' ) ) {
			$verdict = Empreintes::verdict( 'css-global', $actuel );

			if ( ! $verdict['ecrire'] ) {
				return Empreintes::refus( [ 'CSS personnalisé global' ], 'le CSS global', $verdict['motif'] );
			}
		}

		$reglages['customCss'] = wp_slash( $css );

		// `null` : l'autoload de l'option reste ce qu'il était (voir plus haut).
		update_option( Bricks_Adapter::OPT_GLOBAL_SETTINGS, $reglages, null );

		Empreintes::retenir( [ 'css-global' => Bricks_Adapter::empreinte( [ $css ] ) ] );

		Bricks_Adapter::regenerate_css();

		Security::log( 'bricks.custom_css.update', [ 'length' => strlen( $css ) ] );

		return rest_ensure_response(
			[
				'length'  => strlen( $css ),
				'rules'   => $ouvrantes,
				'message' => sprintf( 'CSS personnalisé global écrit : %d règle(s).', $ouvrantes ),
			]
		);
	}

	/* ------------------------------------------------------------------ */
	/* Contrôles de style                                                  */
	/* ------------------------------------------------------------------ */

	/**
	 * Ce que chaque type d'élément sait exprimer dans son panneau de style.
	 *
	 * Indispensable, et pas déductible de l'extérieur : la clé d'un réglage
	 * dépend du type d'élément. Un titre porte `_gap`, un bloc porte `_gridGap` —
	 * les éléments de disposition (section, container, block, div) redéclarent
	 * leurs contrôles avec d'autres noms.
	 *
	 * Conséquence sur les classes globales : Bricks émet leur CSS **par type
	 * d'élément**, et seulement pour les contrôles que ce type déclare. Une classe
	 * posée sur un bouton et sur un bloc, avec un réglage que seul l'un des deux
	 * connaît, produit un style qui n'apparaît que sur la moitié des instances.
	 * Sans cette route, on ne peut que le deviner.
	 */
	public function get_controls( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( ! class_exists( '\Bricks\Elements' ) ) {
			return new \WP_Error( 'anode_bridge_no_bricks', 'Le thème Bricks n’est pas actif.', [ 'status' => 409 ] );
		}

		$demandes = (string) $request->get_param( 'elements' );
		$noms     = $demandes
			? array_values( array_filter( array_map( 'trim', explode( ',', $demandes ) ) ) )
			: $this->element_names_in_use();

		$sortie = [];

		foreach ( $noms as $nom ) {
			$controls = \Bricks\Elements::get_element( [ 'name' => $nom ], 'controls' );

			if ( ! is_array( $controls ) ) {
				$sortie[ $nom ] = null;

				continue;
			}

			$par_cle = [];

			foreach ( $controls as $cle => $control ) {
				// Les contrôles de style commencent par un tiret bas ; les autres
				// pilotent le contenu de l'élément, pas son apparence.
				if ( ! is_string( $cle ) || '' === $cle || '_' !== $cle[0] ) {
					continue;
				}

				if ( ! is_array( $control ) || empty( $control['css'] ) || ! is_array( $control['css'] ) ) {
					continue;
				}

				$proprietes = [];

				foreach ( $control['css'] as $regle ) {
					if ( ! is_array( $regle ) || empty( $regle['property'] ) ) {
						continue;
					}

					$proprietes[] = [
						'property' => (string) $regle['property'],
						'selector' => (string) ( $regle['selector'] ?? '' ),
					];
				}

				if ( ! $proprietes ) {
					continue;
				}

				$par_cle[ $cle ] = [
					'type'       => (string) ( $control['type'] ?? '' ),
					'unit'       => $control['unit'] ?? null,
					'units'      => ! empty( $control['units'] ),
					'css'        => $proprietes,
					'directions' => $control['directions'] ?? null,
				];
			}

			$sortie[ $nom ] = $par_cle;
		}

		return rest_ensure_response( [ 'count' => count( $sortie ), 'elements' => $sortie ] );
	}

	/**
	 * Types d'éléments réellement employés par le site.
	 *
	 * @return array<int, string>
	 */
	private function element_names_in_use(): array {
		$noms = [];

		foreach ( $this->all_bricks_post_ids() as $post_id ) {
			foreach ( array_keys( Bricks_Adapter::AREAS ) as $area ) {
				foreach ( Bricks_Adapter::get_elements( $post_id, $area ) as $element ) {
					if ( ! empty( $element['name'] ) && is_string( $element['name'] ) ) {
						$noms[ $element['name'] ] = true;
					}
				}
			}
		}

		foreach ( Bricks_Adapter::get_components() as $component ) {
			foreach ( is_array( $component['elements'] ?? null ) ? $component['elements'] : [] as $element ) {
				if ( ! empty( $element['name'] ) && is_string( $element['name'] ) ) {
					$noms[ $element['name'] ] = true;
				}
			}
		}

		ksort( $noms );

		return array_keys( $noms );
	}

	/**
	 * Tous les contenus susceptibles de porter une mise en page Bricks.
	 *
	 * Le balayage était codé en dur : page, post et template, trois statuts, et un
	 * plafond de 500. Ce que ce plafond décide n'est pourtant pas cosmétique — le
	 * compte d'instances qui autorise la suppression d'un composant en dépend. Un
	 * composant posé sur un type personnalisé, sur une page en attente de
	 * relecture, dans la corbeille, ou au-delà du cinq-centième contenu passait
	 * donc pour inutilisé, et devenait supprimable sans un mot.
	 *
	 * D'où les trois corrections : les types que Bricks déclare éditables, tous
	 * les statuts enregistrés, et un parcours par lots jusqu'à épuisement. Les
	 * révisions restent dehors — leur `post_type` est `revision`, et compter une
	 * version antérieure gonflerait un décompte qui parle de pages en ligne.
	 *
	 * @return array<int, int>
	 */
	private function all_bricks_post_ids(): \Generator {
		$lot  = 200;
		$page = 0;

		do {
			$batch = get_posts(
				[
					'post_type'      => Bricks_Adapter::editable_post_types(),
					'post_status'    => array_keys( get_post_stati() ),
					'posts_per_page' => $lot,
					'offset'         => $page * $lot,
					'orderby'        => 'ID',
					'order'          => 'ASC',
					'fields'         => 'ids',
				]
			);

			/*
			 * Les appelants lisent ensuite la méta de chaque contenu : sans amorçage,
			 * le balayage exhaustif ferait une requête par contenu. On amorce donc
			 * par lot, comme le fait WP_Query lorsqu'elle rapporte les objets entiers.
			 */
			if ( $batch ) {
				update_meta_cache( 'post', $batch );
			}

			foreach ( $batch as $id ) {
				yield (int) $id;
			}

			/*
			 * Et l'on rend la mémoire du lot avant de passer au suivant.
			 *
			 * Un générateur plutôt qu'un tableau, et une libération par lot : ces
			 * routes sont des **lectures**, appelées couramment — l'inventaire des
			 * composants, la carte des contrôles. Sur un site à quelques milliers de
			 * contenus portant chacun un `_bricks_page_content_2` volumineux, garder
			 * en mémoire la méta de tous les contenus à la fois atteint la limite
			 * PHP. La consommation ne dépend plus de la taille du site, mais de
			 * celle d'un lot.
			 */
			foreach ( $batch as $id ) {
				wp_cache_delete( (int) $id, 'post_meta' );
			}

			++$page;
		} while ( count( $batch ) === $lot );
	}

	/* ------------------------------------------------------------------ */
	/* Composants                                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * Bascule une publication entre Bricks et l'éditeur de WordPress.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function set_editor_mode( \WP_REST_Request $request ) {
		$post_id = (int) $request->get_param( 'id' );
		$mode    = (string) $request->get_param( 'mode' );
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return new \WP_Error( 'bricks_post_absent', "Publication {$post_id} introuvable.", [ 'status' => 404 ] );
		}

		/*
		 * La garde. Rendre la main à WordPress sur une publication dont le
		 * `post_content` est vide, c'est servir une page vide : la mise en page
		 * vit dans les métas de Bricks, que l'éditeur ne lit pas.
		 */
		if ( 'wordpress' === $mode && '' === trim( (string) $post->post_content ) ) {
			return new \WP_Error(
				'bricks_contenu_vide',
				"« {$post->post_title} » n'a aucun contenu d'éditeur : repasser à WordPress "
					. 'servirait une page vide. Écrire post_content AVANT de basculer.',
				[ 'status' => 409 ]
			);
		}

		if ( 'bricks' === $mode ) {
			update_post_meta( $post_id, Bricks_Adapter::META_EDITOR_MODE, 'bricks' );
		} else {
			delete_post_meta( $post_id, Bricks_Adapter::META_EDITOR_MODE );
		}

		return new \WP_REST_Response(
			[
				'id'          => $post_id,
				'editor_mode' => get_post_meta( $post_id, Bricks_Adapter::META_EDITOR_MODE, true ) ?: 'wordpress',
				'message'     => "« {$post->post_title} » s'édite désormais avec "
					. ( 'bricks' === $mode ? 'Bricks.' : 'l’éditeur de WordPress.' ),
			]
		);
	}

	public function list_components( \WP_REST_Request $request ): \WP_REST_Response {
		$with_elements = (bool) $request->get_param( 'with_elements' );
		$instances     = $this->count_component_instances();
		$references    = $this->component_references();
		$components    = [];

		foreach ( Bricks_Adapter::get_components() as $component ) {
			$id       = (string) ( $component['id'] ?? '' );
			$elements = is_array( $component['elements'] ?? null ) ? $component['elements'] : [];
			$root     = null;

			foreach ( $elements as $element ) {
				if ( ( $element['id'] ?? '' ) === $id ) {
					$root = $element;
					break;
				}
			}

			$entry = [
				'id'         => $id,
				'label'      => (string) ( $component['label'] ?? $root['label'] ?? '' ),
				'category'   => (string) ( $component['category'] ?? 'components' ),
				'desc'       => (string) ( $component['desc'] ?? '' ),
				'root_name'  => (string) ( $root['name'] ?? '' ),
				'count'      => count( $elements ),
				'instances'  => $instances[ $id ] ?? 0,
				/*
				 * Un composant imbriqué dans un autre n'apparaît dans aucune page :
				 * sans cette colonne il s'affiche à zéro instance, et l'on croit
				 * qu'il ne sert à rien.
				 */
				'embedded_in' => $references[ $id ] ?? [],
				'properties' => array_map(
					static function ( array $property ): array {
						return [
							'id'          => $property['id'] ?? '',
							'label'       => $property['label'] ?? '',
							'type'        => $property['type'] ?? 'text',
							'default'     => $property['default'] ?? null,
							'connections' => $property['connections'] ?? [],
						];
					},
					is_array( $component['properties'] ?? null ) ? $component['properties'] : []
				),
			];

			if ( $with_elements ) {
				$entry['elements'] = $elements;
			}

			$components[] = $entry;
		}

		return rest_ensure_response(
			[
				'count'      => count( $components ),
				'components' => $components,
			]
		);
	}

	/**
	 * Crée ou met à jour un composant Bricks.
	 *
	 * L'appel est idempotent sur le **nom** : un composant du même nom est mis à
	 * jour en conservant son identifiant. C'est essentiel — les pages désignent
	 * un composant par son `cid`, donc changer d'identifiant reviendrait à vider
	 * toutes les instances du site sans le moindre message.
	 */
	public function upsert_component( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( ! Bricks_Adapter::is_available() ) {
			return new \WP_Error( 'anode_bridge_no_bricks', 'Le thème Bricks n’est pas actif.', [ 'status' => 409 ] );
		}

		$label      = trim( (string) $request->get_param( 'label' ) );
		$existing   = Bricks_Adapter::get_components();
		$position   = null;
		$previous_id = null;

		foreach ( $existing as $index => $component ) {
			if ( $this->component_label( $component ) === $label ) {
				$position    = $index;
				$previous_id = (string) ( $component['id'] ?? '' );
				break;
			}
		}

		$incoming = [
			'label'      => $label,
			'elements'   => $request->get_param( 'elements' ),
			'properties' => $request->get_param( 'properties' ) ?? [],
			'category'   => $request->get_param( 'category' ),
			'desc'       => $request->get_param( 'desc' ),
		];

		// Reprise des métadonnées de création du composant remplacé.
		if ( null !== $position ) {
			foreach ( [ '_created', '_user_id' ] as $key ) {
				if ( isset( $existing[ $position ][ $key ] ) ) {
					$incoming[ $key ] = $existing[ $position ][ $key ];
				}
			}
		}

		if ( $previous_id ) {
			$incoming = $this->rebind_component_id( $incoming, $previous_id );
		}

		$component = Validator::component( $incoming );

		if ( $component instanceof \WP_Error ) {
			return $component;
		}

		/*
		 * Même garde (§10 bis). Un composant est **partagé** : sa définition décide du
		 * dessin de ses trente-sept instances. L'écraser sans le demander défait donc
		 * une correction en trente-sept endroits d'un coup — et l'appel étant
		 * idempotent sur le nom, rien ne distingue « je repose la même définition » de
		 * « j'efface celle qui a été retouchée ».
		 *
		 * La garde ne joue que sur une **mise à jour** : un composant qui n'existe pas
		 * n'a rien à perdre.
		 */
		if ( null !== $position && ! (bool) $request->get_param( 'overwrite' ) ) {
			$verdict = Empreintes::verdict( 'composant:' . $label, $existing[ $position ] );

			if ( ! $verdict['ecrire'] ) {
				return Empreintes::refus( [ $label ], 'composant(s)', $verdict['motif'] );
			}
		}

		if ( null !== $position ) {
			$existing[ $position ] = $component;
		} else {
			// Comme le builder : le dernier composant créé arrive en tête de liste.
			array_unshift( $existing, $component );
		}

		Bricks_Adapter::set_components( $existing );

		Empreintes::retenir( [ 'composant:' . $label => Bricks_Adapter::empreinte( $component ) ] );

		$instances = $this->count_component_instances();

		Security::log(
			'bricks.component.upsert',
			[
				'id'         => $component['id'],
				'label'      => $component['label'],
				'created'    => null === $position,
				'count'      => count( $component['elements'] ),
				'properties' => count( $component['properties'] ),
			]
		);

		return rest_ensure_response(
			[
				'id'         => $component['id'],
				'label'      => $component['label'],
				'created'    => null === $position,
				'count'      => count( $component['elements'] ),
				'properties' => count( $component['properties'] ),
				'instances'  => $instances[ $component['id'] ] ?? 0,
				'message'    => sprintf(
					'Composant « %s » %s : %d élément(s), %d propriété(s).',
					$component['label'],
					null === $position ? 'créé' : 'mis à jour',
					count( $component['elements'] ),
					count( $component['properties'] )
				),
			]
		);
	}

	/**
	 * Supprime un composant, sauf s'il est encore posé quelque part.
	 *
	 * Un composant supprimé alors qu'il a des instances laisse dans les pages des
	 * éléments qui ne rendent plus rien : la section disparaît du site sans que
	 * la page ait changé. Le refus par défaut est donc la seule réponse utile.
	 */
	public function delete_component( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$label      = trim( urldecode( (string) $request->get_param( 'label' ) ) );
		$components = Bricks_Adapter::get_components();
		$position   = null;

		foreach ( $components as $index => $component ) {
			if ( $this->component_label( $component ) === $label ) {
				$position = $index;
				break;
			}
		}

		if ( null === $position ) {
			return new \WP_Error(
				'anode_bridge_not_found',
				sprintf( 'Aucun composant nommé « %s ».', $label ),
				[ 'status' => 404 ]
			);
		}

		$id        = (string) $components[ $position ]['id'];
		$instances = $this->count_component_instances()[ $id ] ?? 0;
		$dans      = $this->component_references()[ $id ] ?? [];

		if ( ( $instances || $dans ) && ! $request->get_param( 'force' ) ) {
			return new \WP_Error(
				'anode_bridge_conflict',
				sprintf(
					'Le composant « %s » est encore employé : %d instance(s) dans des pages%s. '
					. 'Ses instances afficheraient du vide. Retirez-les d’abord, ou passez force = true.',
					$label,
					$instances,
					$dans ? sprintf( ', et imbriqué dans %s', implode( ', ', $dans ) ) : ''
				),
				[ 'status' => 409 ]
			);
		}

		unset( $components[ $position ] );

		Bricks_Adapter::set_components( array_values( $components ) );

		Security::log(
			'bricks.component.delete',
			[ 'id' => $id, 'label' => $label, 'instances' => $instances, 'embedded_in' => $dans ]
		);

		return rest_ensure_response(
			[
				'id'          => $id,
				'label'       => $label,
				'instances'   => $instances,
				'embedded_in' => $dans,
				'message'     => sprintf( 'Composant « %s » supprimé.', $label ),
			]
		);
	}

	/**
	 * Réattribue à un composant entrant l'identifiant du composant qu'il remplace.
	 *
	 * L'identifiant d'un composant est celui de son élément racine : il faut donc
	 * le reporter sur la racine, sur le `parent` de ses enfants directs, et sur
	 * les connexions de propriétés qui la désignent.
	 *
	 * @param array<string, mixed> $component Composant entrant, non encore validé.
	 * @param string               $target_id Identifiant à conserver.
	 *
	 * @return array<string, mixed>
	 */
	private function rebind_component_id( array $component, string $target_id ): array {
		$elements = is_array( $component['elements'] ?? null ) ? $component['elements'] : [];
		$root_id  = null;

		foreach ( $elements as $element ) {
			if ( is_array( $element ) && 0 === (int) ( $element['parent'] ?? 0 ) ) {
				$root_id = (string) ( $element['id'] ?? '' );
				break;
			}
		}

		if ( ! $root_id || $root_id === $target_id ) {
			$component['id'] = $target_id;

			return $component;
		}

		foreach ( $elements as $index => $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			if ( (string) ( $element['id'] ?? '' ) === $root_id ) {
				$elements[ $index ]['id'] = $target_id;
			}

			if ( (string) ( $element['parent'] ?? '' ) === $root_id ) {
				$elements[ $index ]['parent'] = $target_id;
			}
		}

		$properties = is_array( $component['properties'] ?? null ) ? $component['properties'] : [];

		foreach ( $properties as $index => $property ) {
			if ( ! is_array( $property ) || ! is_array( $property['connections'] ?? null ) ) {
				continue;
			}

			if ( isset( $property['connections'][ $root_id ] ) ) {
				$properties[ $index ]['connections'][ $target_id ] = $property['connections'][ $root_id ];
				unset( $properties[ $index ]['connections'][ $root_id ] );
			}
		}

		$component['id']         = $target_id;
		$component['elements']   = $elements;
		$component['properties'] = $properties;

		return $component;
	}

	/**
	 * Compte les instances de chaque composant, toutes zones confondues.
	 *
	 * Un composant sans instance n'est pas une erreur — il peut venir d'être
	 * créé. Mais un composant censé porter l'en-tête du site et qui affiche zéro
	 * instance signale que le template n'a pas été relié : c'est le contrôle qui
	 * manque le plus souvent après une écriture par l'API.
	 *
	 * Le balayage est celui de `all_bricks_post_ids()` : exhaustif, parce que ce
	 * compte autorise ou refuse une suppression — un contenu manqué se traduit
	 * par un composant supprimé alors qu'il servait encore.
	 *
	 * @return array<string, int>
	 */
	private function count_component_instances(): array {
		$counts = [];

		foreach ( $this->all_bricks_post_ids() as $post_id ) {
			foreach ( array_keys( Bricks_Adapter::AREAS ) as $area ) {
				foreach ( Bricks_Adapter::get_elements( (int) $post_id, $area ) as $element ) {
					$cid = $element['cid'] ?? '';

					if ( is_string( $cid ) && '' !== $cid ) {
						$counts[ $cid ] = ( $counts[ $cid ] ?? 0 ) + 1;
					}
				}
			}
		}

		return $counts;
	}

	/**
	 * Composants employés à l'intérieur d'un autre composant.
	 *
	 * Une instance imbriquée ne se trouve dans aucune page : elle vit dans les
	 * éléments d'une **définition**. Comptée seulement sur les pages, elle est
	 * donc invisible — et le composant imbriqué s'affiche à zéro instance.
	 *
	 * Ce n'est pas un détail d'affichage : la suppression s'appuie sur ce compte.
	 * Sans ce relevé, « Coordonnées », posée dans « Aside contact » et rendue sur
	 * cinq pages, était supprimable sans un mot — les cinq pages auraient affiché
	 * du vide, sans qu'aucune ait changé.
	 *
	 * @return array<string, list<string>> cid du composant imbriqué → libellés des composants qui le posent.
	 */
	private function component_references(): array {
		$references = [];

		foreach ( Bricks_Adapter::get_components() as $component ) {
			$label    = $this->component_label( $component );
			$elements = is_array( $component['elements'] ?? null ) ? $component['elements'] : [];

			foreach ( $elements as $element ) {
				if ( ! is_array( $element ) ) {
					continue;
				}

				$cid = $element['cid'] ?? '';

				// Un composant qui se contiendrait lui-même n'existe pas dans Bricks.
				if ( ! is_string( $cid ) || '' === $cid || $cid === (string) ( $component['id'] ?? '' ) ) {
					continue;
				}

				if ( ! in_array( $label, $references[ $cid ] ?? [], true ) ) {
					$references[ $cid ][] = $label;
				}
			}
		}

		return $references;
	}

	/**
	 * Nom d'un composant, où qu'il soit rangé.
	 *
	 * Bricks a déplacé le libellé de l'objet composant vers son élément racine
	 * en 1.12 : les deux emplacements existent donc en base selon l'âge du
	 * composant.
	 *
	 * @param array<string, mixed> $component Composant en base.
	 */
	private function component_label( array $component ): string {
		if ( isset( $component['label'] ) && is_string( $component['label'] ) && '' !== trim( $component['label'] ) ) {
			return trim( $component['label'] );
		}

		$id = (string) ( $component['id'] ?? '' );

		foreach ( is_array( $component['elements'] ?? null ) ? $component['elements'] : [] as $element ) {
			if ( is_array( $element ) && (string) ( $element['id'] ?? '' ) === $id && isset( $element['label'] ) ) {
				return trim( (string) $element['label'] );
			}
		}

		return '';
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
	 * Décide les conditions d'affichage à écrire pour un template.
	 *
	 * Ce que cette méthode empêche est arrivé, mesuré le 31/07/2026 sur
	 * blueprint.agence-anode.fr : mettre à jour le gabarit du template « 404 » sans
	 * repasser ses conditions écrasait `templateConditions` par « any », et
	 * Bricks servait alors la page d'erreur **à la place de tout le site** —
	 * accueil comprise, en HTTP 200. L'appel avait pourtant répondu « mis à jour
	 * avec 10 élément(s) », sans un mot sur la condition remplacée.
	 *
	 * Trois règles, dans cet ordre :
	 *
	 *   1. des conditions fournies font foi ;
	 *   2. sinon, celles déjà en base sont **conservées** — une mise à jour de
	 *      contenu n'a aucune raison de déplacer un template ;
	 *   3. sinon seulement, un défaut déduit du type.
	 *
	 * Le défaut par type compte autant que le reste : « any » sur un template
	 * `error` ou `search` est précisément la valeur qui capture le site entier,
	 * alors que Bricks a une condition dédiée pour chacun (`includes/templates.php`,
	 * `case 'error'` → `is_404()`, `case 'search'` → `is_search()`).
	 *
	 * @param mixed      $fournies    Conditions passées par l'appelant.
	 * @param mixed      $existantes  Conditions déjà stockées, s'il y en a.
	 * @param string     $type        Type du template.
	 */
	private static function conditions_du_template( $fournies, $existantes, string $type ): array {
		if ( is_array( $fournies ) && $fournies ) {
			return $fournies;
		}

		if ( is_array( $existantes ) && $existantes ) {
			return $existantes;
		}

		// « any » est la clé que Bricks attend pour « tout le site » — vérifiée
		// dans le code du thème, l'intitulé de l'interface n'est pas la clé
		// stockée. Elle ne convient qu'aux types servis partout.
		$par_type = [
			'error'  => [ [ 'main' => 'error' ] ],
			'search' => [ [ 'main' => 'search' ] ],
		];

		return $par_type[ $type ] ?? [ [ 'main' => 'any' ] ];
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
			/*
			 * La même garde qu'une page : un template est une mise en page comme une
			 * autre, retouchée dans le même builder. La laisser hors du champ aurait
			 * déplacé le trou au lieu de le fermer — et un gabarit d'archive corrigé à
			 * la main est exactement le genre de travail qu'on ne refait pas deux fois.
			 */
			$refus = $this->refus_ecrasement(
				$template_id,
				$area,
				Bricks_Adapter::get_elements( $template_id, $area ),
				(bool) $request->get_param( 'overwrite' )
			);

			if ( $refus instanceof \WP_Error ) {
				return $refus;
			}

			Bricks_Adapter::set_elements( $template_id, $elements, $area );
		}

		$settings = get_post_meta( $template_id, Bricks_Adapter::META_TEMPLATE_SETTINGS, true );
		$settings = is_array( $settings ) ? $settings : [];

		$settings['templateConditions'] = self::conditions_du_template(
			$request->get_param( 'conditions' ),
			$settings['templateConditions'] ?? null,
			$type
		);

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

	/**
	 * Supprime un template Bricks.
	 *
	 * Le type `bricks_template` n'est pas exposé à l'API REST standard : sans
	 * cette route, retirer un en-tête devenu inutile imposerait de passer par
	 * l'administration — donc par un accès au site, ce que tout le reste du pont
	 * s'attache à éviter.
	 *
	 * Un template vidé mais toujours actif n'est pas neutre : Bricks émet quand
	 * même son enveloppe, et la page se retrouve avec un `<header>` vide — un
	 * repère de navigation qui n'annonce rien.
	 */
	public function delete_template( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$template_id = (int) $request->get_param( 'id' );
		$template    = get_post( $template_id );

		if ( ! $template instanceof \WP_Post || Bricks_Adapter::POST_TYPE_TEMPLATE !== $template->post_type ) {
			return new \WP_Error(
				'anode_bridge_not_found',
				sprintf( 'Aucun template Bricks avec l’identifiant %d.', $template_id ),
				[ 'status' => 404 ]
			);
		}

		$type  = (string) get_post_meta( $template_id, Bricks_Adapter::META_TEMPLATE_TYPE, true );
		$title = $template->post_title;

		if ( ! wp_delete_post( $template_id, true ) ) {
			return new \WP_Error( 'anode_bridge_failed', 'La suppression a échoué.', [ 'status' => 500 ] );
		}

		Bricks_Adapter::regenerate_css();

		Security::log( 'bricks.template.delete', [ 'id' => $template_id, 'type' => $type, 'title' => $title ] );

		return rest_ensure_response(
			[
				'id'      => $template_id,
				'title'   => $title,
				'type'    => $type,
				'message' => sprintf( 'Template « %s » (%s) supprimé.', $title, $type ?: 'sans type' ),
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

	/**
	 * Écrit la liste entrante telle quelle, mais reprend l'`id` déjà connu.
	 *
	 * C'est ce que le mode « replace » attend : la liste finale est bien celle
	 * qu'on envoie — rien n'est conservé de ce qui n'y figure pas — mais une
	 * entrée dont le nom existait déjà garde son identifiant. Sans cela, le
	 * validateur lui en attribue un neuf, et les éléments des pages, qui
	 * désignent les classes par identifiant, ne la retrouvent plus.
	 *
	 * @param array<int, array<string, mixed>> $existing Liste en base.
	 * @param array<int, array<string, mixed>> $incoming Liste entrante.
	 *
	 * @return array{0: array<int, array<string, mixed>>, 1: int, 2: int}
	 */
	private function keep_existing_ids( array $existing, array $incoming ): array {
		$ids = [];

		foreach ( $existing as $entry ) {
			if ( isset( $entry['name'], $entry['id'] ) && is_string( $entry['name'] ) ) {
				$ids[ $entry['name'] ] = $entry['id'];
			}
		}

		$added   = 0;
		$updated = 0;

		foreach ( $incoming as $position => $entry ) {
			if ( isset( $ids[ $entry['name'] ] ) ) {
				$incoming[ $position ]['id'] = $ids[ $entry['name'] ];
				++$updated;
			} else {
				++$added;
			}
		}

		return [ array_values( $incoming ), $added, $updated ];
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
