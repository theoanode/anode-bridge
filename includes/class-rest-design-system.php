<?php
/**
 * Endpoint design system : applique un fichier de tokens versionné en Git
 * vers les variables globales Bricks, et relit l'état courant.
 *
 * Le fichier de tokens (`design-system/tokens.json` dans le dépôt du site)
 * est la source de vérité. Bricks n'en est que le reflet : on pousse toujours
 * du dépôt vers le site, jamais l'inverse — sauf via `GET`, qui sert à
 * détecter une dérive faite à la main dans le builder.
 *
 * @package Anode\Bridge
 */

declare( strict_types = 1 );

namespace Anode\Bridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Rest_Design_System {

	/** Option conservant la trace du dernier design system appliqué. */
	private const OPT_APPLIED = 'anode_design_system';

	public function register_routes(): void {
		register_rest_route(
			NAMESPACE_,
			'/design-system',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_design_system' ],
					'permission_callback' => [ Security::class, 'permission_read' ],
				],
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'apply_design_system' ],
					'permission_callback' => [ Security::class, 'permission_manage' ],
					'args'                => [
						'tokens'  => [
							'required'    => true,
							'type'        => 'object',
							'description' => 'Contenu de design-system/tokens.json.',
						],
						'dry_run' => [
							'type'        => 'boolean',
							'default'     => false,
							'description' => 'Calcule les changements sans les écrire.',
						],
						'prune'   => [
							'type'        => 'boolean',
							'default'     => false,
							'description' => 'Supprime les variables Bricks absentes des tokens.',
						],
					],
				],
			]
		);
	}

	/**
	 * État courant : variables Bricks, palette, et dernier design system appliqué.
	 */
	public function get_design_system(): \WP_REST_Response {
		$applied   = get_option( self::OPT_APPLIED, null );
		$variables = Bricks_Adapter::get_variables();

		return rest_ensure_response(
			[
				'applied'    => is_array( $applied ) ? $applied : null,
				'variables'  => $variables,
				'count'      => count( $variables ),
				'categories' => Bricks_Adapter::get_variable_categories(),
				'palette'    => Bricks_Adapter::get_color_palette(),
				'drift'      => $this->detect_drift( $applied, $variables ),
			]
		);
	}

	/**
	 * Applique les tokens : chaque token devient une variable globale Bricks,
	 * utilisable dans le builder comme `var(--nom-du-token)`.
	 */
	public function apply_design_system( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$tokens = $request->get_param( 'tokens' );

		if ( ! is_array( $tokens ) ) {
			return new \WP_Error( 'anode_bridge_invalid', 'Le paramètre « tokens » doit être un objet.', [ 'status' => 400 ] );
		}

		$flattened = $this->flatten_tokens( $tokens );

		if ( $flattened instanceof \WP_Error ) {
			return $flattened;
		}

		if ( ! $flattened ) {
			return new \WP_Error( 'anode_bridge_invalid', 'Aucun token exploitable dans le fichier fourni.', [ 'status' => 400 ] );
		}

		// Une catégorie Bricks par groupe de premier niveau (color, space, font…).
		$categories = $this->sync_categories( array_unique( array_column( $flattened, 'group' ) ) );

		$variables = [];

		foreach ( $flattened as $token ) {
			$variables[] = [
				'name'     => $token['name'],
				'value'    => $token['value'],
				'category' => $categories[ $token['group'] ] ?? '',
			];
		}

		$validated = Validator::variables( $variables );

		if ( $validated instanceof \WP_Error ) {
			return $validated;
		}

		$existing = Bricks_Adapter::get_variables();
		$diff     = $this->diff( $existing, $validated, (bool) $request->get_param( 'prune' ) );

		if ( $request->get_param( 'dry_run' ) ) {
			return rest_ensure_response(
				[
					'dry_run' => true,
					'summary' => $diff['summary'],
					'changes' => $diff['changes'],
					'message' => $this->summarize( $diff['summary'], true ),
				]
			);
		}

		Bricks_Adapter::set_variables( $diff['result'] );

		// La palette Bricks (sélecteur de couleurs du builder) reprend les
		// tokens de couleur, pour que le builder propose la charte du site.
		$palette = $this->build_palette( $flattened );

		if ( $palette ) {
			Bricks_Adapter::set_color_palette( $palette );
		}

		update_option(
			self::OPT_APPLIED,
			[
				'name'      => is_string( $tokens['$name'] ?? null ) ? $tokens['$name'] : ( $tokens['name'] ?? 'design-system' ),
				'version'   => is_string( $tokens['$version'] ?? null ) ? $tokens['$version'] : ( $tokens['version'] ?? null ),
				'applied_at' => gmdate( 'c' ),
				'tokens'    => array_column( $flattened, 'value', 'name' ),
			],
			false
		);

		Security::log( 'design-system.apply', $diff['summary'] );

		return rest_ensure_response(
			[
				'dry_run' => false,
				'summary' => $diff['summary'],
				'changes' => $diff['changes'],
				'total'   => count( $diff['result'] ),
				'message' => $this->summarize( $diff['summary'], false ),
			]
		);
	}

	/* ------------------------------------------------------------------ */
	/* Transformation des tokens                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * Aplatit un objet de tokens imbriqué en liste de variables CSS.
	 *
	 *   { "color": { "primary": { "500": "#2f6df6" } } }
	 *   ->  color-primary-500 = #2f6df6
	 *
	 * Les clés commençant par « $ » sont des métadonnées, pas des tokens.
	 * Une valeur peut référencer un autre token via « {color.primary.500} ».
	 *
	 * @param array<string, mixed> $tokens Objet de tokens.
	 *
	 * @return array<int, array{name: string, value: string, group: string}>|\WP_Error
	 */
	private function flatten_tokens( array $tokens ): array|\WP_Error {
		$flat = [];

		$walk = static function ( array $node, array $path ) use ( &$walk, &$flat ): void {
			foreach ( $node as $key => $value ) {
				// json_decode transforme les clés numériques (« 600 ») en entiers :
				// on normalise en chaîne avant tout traitement, sans quoi toute
				// l'échelle de couleurs serait ignorée.
				$segment = strtolower( (string) $key );

				if ( str_starts_with( $segment, '$' ) ) {
					continue;
				}

				$current = [ ...$path, $segment ];

				if ( is_array( $value ) ) {
					// Convention Design Tokens : { "$value": "…" } termine une branche.
					if ( isset( $value['$value'] ) && ( is_string( $value['$value'] ) || is_numeric( $value['$value'] ) ) ) {
						$flat[] = [
							'name'  => implode( '-', $current ),
							'value' => (string) $value['$value'],
							'group' => $path[0] ?? $segment,
						];
						continue;
					}

					$walk( $value, $current );
					continue;
				}

				if ( is_string( $value ) || is_numeric( $value ) || is_bool( $value ) ) {
					$flat[] = [
						'name'  => implode( '-', $current ),
						'value' => is_bool( $value ) ? ( $value ? 'true' : 'false' ) : (string) $value,
						'group' => $path[0] ?? $segment,
					];
				}
			}
		};

		$walk( $tokens, [] );

		return $this->resolve_references( $flat );
	}

	/**
	 * Résout les références « {chemin.vers.token} » entre tokens.
	 *
	 * @param array<int, array{name: string, value: string, group: string}> $flat Tokens aplatis.
	 *
	 * @return array<int, array{name: string, value: string, group: string}>|\WP_Error
	 */
	private function resolve_references( array $flat ): array|\WP_Error {
		$by_name = array_column( $flat, 'value', 'name' );
		$passes  = 0;

		do {
			$changed = false;

			foreach ( $flat as $index => $token ) {
				if ( ! str_contains( $token['value'], '{' ) ) {
					continue;
				}

				$resolved = preg_replace_callback(
					'/\{([a-z0-9._-]+)\}/i',
					static function ( array $matches ) use ( $by_name ): string {
						$key = str_replace( [ '.', '_' ], '-', strtolower( $matches[1] ) );

						return $by_name[ $key ] ?? $matches[0];
					},
					$token['value']
				);

				if ( $resolved !== $token['value'] ) {
					$flat[ $index ]['value']     = $resolved;
					$by_name[ $token['name'] ]   = $resolved;
					$changed                     = true;
				}
			}

			if ( ++$passes > 10 ) {
				return new \WP_Error(
					'anode_bridge_invalid',
					'Référence circulaire détectée entre les tokens du design system.',
					[ 'status' => 400 ]
				);
			}
		} while ( $changed );

		foreach ( $flat as $token ) {
			if ( preg_match( '/\{[a-z0-9._-]+\}/i', $token['value'] ) ) {
				return new \WP_Error(
					'anode_bridge_invalid',
					sprintf( 'Le token « %s » référence un token inexistant : %s', $token['name'], $token['value'] ),
					[ 'status' => 400 ]
				);
			}
		}

		return $flat;
	}

	/**
	 * Crée/réutilise une catégorie Bricks par groupe de tokens.
	 *
	 * @param array<int, string> $groups Groupes de premier niveau.
	 *
	 * @return array<string, string> Groupe => id de catégorie.
	 */
	private function sync_categories( array $groups ): array {
		$existing = Bricks_Adapter::get_variable_categories();
		$map      = [];

		foreach ( $existing as $category ) {
			if ( isset( $category['name'], $category['id'] ) ) {
				$map[ strtolower( (string) $category['name'] ) ] = (string) $category['id'];
			}
		}

		$changed = false;

		foreach ( $groups as $group ) {
			$group = strtolower( (string) $group );

			if ( '' === $group || isset( $map[ $group ] ) ) {
				continue;
			}

			$id            = Bricks_Adapter::generate_id();
			$map[ $group ] = $id;
			$existing[]    = [ 'id' => $id, 'name' => $group ];
			$changed       = true;
		}

		if ( $changed ) {
			Bricks_Adapter::set_variable_categories( $existing );
		}

		return $map;
	}

	/**
	 * Construit la palette de couleurs du builder à partir des tokens couleur.
	 *
	 * @param array<int, array{name: string, value: string, group: string}> $flat Tokens aplatis.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function build_palette( array $flat ): array {
		$colors = [];

		foreach ( $flat as $token ) {
			if ( 'color' !== $token['group'] && 'colors' !== $token['group'] ) {
				continue;
			}

			$colors[] = [
				'id'    => Bricks_Adapter::generate_id(),
				'name'  => $token['name'],
				'raw'   => 'var(--' . $token['name'] . ')',
				'hex'   => preg_match( '/^#[0-9a-f]{3,8}$/i', $token['value'] ) ? $token['value'] : '',
			];
		}

		if ( ! $colors ) {
			return [];
		}

		$existing = Bricks_Adapter::get_color_palette();
		$id       = 'anode-ds';

		foreach ( $existing as $index => $palette ) {
			if ( ( $palette['id'] ?? '' ) === $id ) {
				$existing[ $index ]['colors'] = $colors;

				return $existing;
			}
		}

		array_unshift(
			$existing,
			[
				'id'     => $id,
				'name'   => 'Design system',
				'colors' => $colors,
			]
		);

		return $existing;
	}

	/* ------------------------------------------------------------------ */
	/* Diff                                                                */
	/* ------------------------------------------------------------------ */

	/**
	 * Compare les variables en base et celles issues des tokens.
	 *
	 * @param array<int, array<string, mixed>> $existing Variables en base.
	 * @param array<int, array<string, mixed>> $incoming Variables issues des tokens.
	 * @param bool                             $prune    Supprime les variables absentes des tokens.
	 *
	 * @return array{result: array<int, array<string, mixed>>, summary: array<string, int>, changes: array<string, array<int, string>>}
	 */
	private function diff( array $existing, array $incoming, bool $prune ): array {
		$by_name = [];

		foreach ( $existing as $variable ) {
			if ( isset( $variable['name'] ) ) {
				$by_name[ $variable['name'] ] = $variable;
			}
		}

		$incoming_names = array_column( $incoming, 'name' );
		$result         = [];
		$changes        = [ 'added' => [], 'updated' => [], 'removed' => [], 'unchanged' => [] ];

		foreach ( $incoming as $variable ) {
			$name = $variable['name'];

			if ( ! isset( $by_name[ $name ] ) ) {
				$changes['added'][] = $name;
				$result[]           = $variable;
				continue;
			}

			// L'identifiant existant est conservé : les éléments Bricks
			// référencent les variables par id.
			$variable['id'] = $by_name[ $name ]['id'] ?? $variable['id'];

			if ( ( $by_name[ $name ]['value'] ?? null ) === $variable['value'] ) {
				$changes['unchanged'][] = $name;
			} else {
				$changes['updated'][] = sprintf( '%s : %s → %s', $name, (string) ( $by_name[ $name ]['value'] ?? '' ), $variable['value'] );
			}

			$result[] = $variable;
		}

		// Variables présentes en base mais absentes des tokens.
		foreach ( $existing as $variable ) {
			$name = $variable['name'] ?? '';

			if ( '' === $name || in_array( $name, $incoming_names, true ) ) {
				continue;
			}

			if ( $prune ) {
				$changes['removed'][] = $name;
			} else {
				$result[] = $variable;
			}
		}

		return [
			'result'  => $result,
			'summary' => [
				'added'     => count( $changes['added'] ),
				'updated'   => count( $changes['updated'] ),
				'removed'   => count( $changes['removed'] ),
				'unchanged' => count( $changes['unchanged'] ),
			],
			'changes' => $changes,
		];
	}

	/**
	 * Repère les variables modifiées à la main dans le builder depuis la
	 * dernière application du design system.
	 *
	 * @param mixed                            $applied   Dernier design system appliqué.
	 * @param array<int, array<string, mixed>> $variables Variables actuelles.
	 *
	 * @return array<int, string>
	 */
	private function detect_drift( mixed $applied, array $variables ): array {
		if ( ! is_array( $applied ) || ! is_array( $applied['tokens'] ?? null ) ) {
			return [];
		}

		$current = array_column( $variables, 'value', 'name' );
		$drift   = [];

		foreach ( $applied['tokens'] as $name => $value ) {
			if ( ! isset( $current[ $name ] ) ) {
				$drift[] = sprintf( '%s : supprimée dans Bricks', $name );
			} elseif ( $current[ $name ] !== $value ) {
				$drift[] = sprintf( '%s : %s en base, %s dans les tokens', $name, (string) $current[ $name ], (string) $value );
			}
		}

		return $drift;
	}

	/**
	 * @param array<string, int> $summary Compteurs du diff.
	 */
	private function summarize( array $summary, bool $dry_run ): string {
		return sprintf(
			'%s : %d ajoutée(s), %d modifiée(s), %d supprimée(s), %d inchangée(s).',
			$dry_run ? 'Simulation' : 'Design system appliqué',
			$summary['added'],
			$summary['updated'],
			$summary['removed'],
			$summary['unchanged']
		);
	}
}
