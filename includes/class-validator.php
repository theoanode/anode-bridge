<?php
/**
 * Validation et assainissement des structures Bricks.
 *
 * Une structure Bricks est un tableau plat de nœuds reliés par `parent` et
 * `children`. Une structure incohérente (parent orphelin, cycle, id dupliqué)
 * casse le builder sans message d'erreur : on refuse en amont plutôt que
 * d'écrire des données corrompues en base.
 *
 * @package Anode\Bridge
 */

declare( strict_types = 1 );

namespace Anode\Bridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Validator {

	/** Identifiant Bricks : alphanumérique court. */
	private const ID_PATTERN = '/^[A-Za-z0-9_-]{3,32}$/';

	/** Nom d'élément Bricks (ex. « section », « heading », « my-component »). */
	private const NAME_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,63}$/i';

	/**
	 * Nom de classe CSS conforme BEM.
	 *
	 * bloc            : c-hero, l-grid, u-hidden
	 * élément         : c-hero__title
	 * modificateur    : c-hero--dark, c-hero__title--large
	 */
	public const BEM_PATTERN = '/^[a-z][a-z0-9]*(-[a-z0-9]+)*(__[a-z][a-z0-9]*(-[a-z0-9]+)*)?(--[a-z][a-z0-9]*(-[a-z0-9]+)*)?$/';

	/**
	 * Nom de variable CSS (sans les deux tirets initiaux).
	 */
	private const VARIABLE_PATTERN = '/^[a-z][a-z0-9]*(-[a-z0-9]+)*$/';

	/**
	 * Valide une structure d'éléments Bricks complète.
	 *
	 * @param mixed $elements Structure brute reçue de l'API.
	 *
	 * @return array<int, array<string, mixed>>|\WP_Error Structure assainie ou erreur détaillée.
	 */
	public static function elements( mixed $elements ): array|\WP_Error {
		if ( ! is_array( $elements ) ) {
			return self::error( 'La structure doit être un tableau d’éléments.' );
		}

		// Un contenu vide est légitime (page vidée).
		if ( ! $elements ) {
			return [];
		}

		$clean = [];
		$ids   = [];

		foreach ( array_values( $elements ) as $index => $element ) {
			$node = self::element( $element, $index );

			if ( $node instanceof \WP_Error ) {
				return $node;
			}

			if ( isset( $ids[ $node['id'] ] ) ) {
				return self::error( sprintf( 'Identifiant d’élément dupliqué : « %s ».', $node['id'] ) );
			}

			$ids[ $node['id'] ] = $index;
			$clean[]            = $node;
		}

		$integrity = self::check_tree_integrity( $clean, $ids );

		if ( $integrity instanceof \WP_Error ) {
			return $integrity;
		}

		return $clean;
	}

	/**
	 * Valide un nœud unique.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function element( mixed $element, int $index ): array|\WP_Error {
		if ( ! is_array( $element ) ) {
			return self::error( sprintf( 'Élément #%d : objet attendu.', $index ) );
		}

		$id   = $element['id'] ?? '';
		$name = $element['name'] ?? '';

		if ( ! is_string( $id ) || ! preg_match( self::ID_PATTERN, $id ) ) {
			return self::error( sprintf( 'Élément #%d : « id » manquant ou invalide (3 à 32 caractères alphanumériques).', $index ) );
		}

		if ( ! is_string( $name ) || ! preg_match( self::NAME_PATTERN, $name ) ) {
			return self::error( sprintf( 'Élément « %s » : « name » manquant ou invalide.', $id ) );
		}

		$parent = $element['parent'] ?? 0;

		if ( ! is_string( $parent ) && ! is_int( $parent ) ) {
			return self::error( sprintf( 'Élément « %s » : « parent » doit être 0 ou un identifiant.', $id ) );
		}

		if ( is_string( $parent ) && '' !== $parent && '0' !== $parent && ! preg_match( self::ID_PATTERN, $parent ) ) {
			return self::error( sprintf( 'Élément « %s » : identifiant parent invalide.', $id ) );
		}

		$children = $element['children'] ?? [];

		if ( ! is_array( $children ) ) {
			return self::error( sprintf( 'Élément « %s » : « children » doit être un tableau.', $id ) );
		}

		foreach ( $children as $child ) {
			if ( ! is_string( $child ) || ! preg_match( self::ID_PATTERN, $child ) ) {
				return self::error( sprintf( 'Élément « %s » : identifiant enfant invalide.', $id ) );
			}
		}

		$settings = $element['settings'] ?? [];

		if ( ! is_array( $settings ) ) {
			return self::error( sprintf( 'Élément « %s » : « settings » doit être un objet.', $id ) );
		}

		$settings = self::sanitize_settings( $settings, $name, $id );

		if ( $settings instanceof \WP_Error ) {
			return $settings;
		}

		$node = [
			'id'       => $id,
			'name'     => $name,
			'parent'   => ( '' === $parent || '0' === $parent ) ? 0 : $parent,
			'children' => array_values( $children ),
			'settings' => $settings,
		];

		// Champs facultatifs conservés tels quels s'ils sont bien typés.
		foreach ( [ 'label', 'themeStyle', 'component', 'cid', 'instanceId' ] as $optional ) {
			if ( isset( $element[ $optional ] ) && ( is_string( $element[ $optional ] ) || is_array( $element[ $optional ] ) ) ) {
				$node[ $optional ] = $element[ $optional ];
			}
		}

		return $node;
	}

	/**
	 * Assainit les réglages d'un élément.
	 *
	 * Point de sécurité : l'élément « code » de Bricks peut exécuter du PHP.
	 * Bricks protège l'exécution par une signature HMAC que ce pont ne peut pas
	 * produire — du code injecté ne s'exécuterait donc pas. On refuse malgré
	 * tout explicitement, pour que le refus soit lisible et ne dépende pas
	 * d'un détail d'implémentation de Bricks.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function sanitize_settings( array $settings, string $name, string $id ): array|\WP_Error {
		if ( 'code' === $name && ! empty( $settings['executeCode'] ) ) {
			return self::error(
				sprintf(
					'Élément « %s » : l’exécution de PHP (executeCode) est interdite via le pont REST. Utilisez le builder Bricks pour ce cas.',
					$id
				),
				403
			);
		}

		if ( 'html' === $name && isset( $settings['html'] ) ) {
			$verdict = self::check_raw_html( (string) $settings['html'], $id );

			if ( $verdict instanceof \WP_Error ) {
				return $verdict;
			}
		}

		// Une signature ne peut venir que du builder : on ne la propage jamais.
		unset( $settings['signature'] );

		if ( isset( $settings['query'] ) && is_array( $settings['query'] ) ) {
			unset( $settings['query']['signature'] );
		}

		return $settings;
	}

	/**
	 * Contrôle le balisage brut d'un élément « html ».
	 *
	 * Cet élément est le seul de Bricks à rendre son contenu tel quel, sans
	 * conteneur ni signature — c'est ce qui permet d'y poser un SVG en ligne,
	 * et c'est aussi ce qui en fait une porte d'entrée. Le pont étant ouvert
	 * aux clients, on referme les usages qui n'ont rien à y faire :
	 *
	 *   • script, PHP, gestionnaires `on…` et `javascript:` — exécution ;
	 *   • iframe, object, embed, ressource distante — la règle zéro dépendance,
	 *     qu'aucune page ne doit pouvoir contourner par ce biais.
	 *
	 * Ce n'est pas un assainisseur général : c'est un refus net, avec un motif
	 * lisible, pour que l'auteur corrige au lieu de découvrir une amputation
	 * silencieuse de son balisage.
	 */
	private static function check_raw_html( string $html, string $id ): bool|\WP_Error {
		$forbidden = [
			'/<\s*(script|iframe|object|embed|form|link|meta)\b/i' => 'la balise « %s » n’est pas autorisée dans un élément HTML',
			'/<\?/'                                               => 'le code PHP n’est pas autorisé',
			'/\son[a-z]+\s*=/i'                                   => 'les gestionnaires d’événement en attribut (on…=) ne sont pas autorisés',
			'/javascript\s*:/i'                                   => 'les URL « javascript: » ne sont pas autorisées',
			'/(src|href|xlink:href)\s*=\s*["\']?(https?:)?\/\//i'  => 'une ressource distante rompt la règle zéro dépendance',
		];

		foreach ( $forbidden as $pattern => $reason ) {
			if ( preg_match( $pattern, $html, $matches ) ) {
				return self::error(
					sprintf(
						'Élément « %s » : %s.',
						$id,
						sprintf( $reason, strtolower( $matches[1] ?? '' ) )
					),
					403
				);
			}
		}

		return true;
	}

	/**
	 * Vérifie la cohérence de l'arbre : références croisées et absence de cycle.
	 *
	 * @param array<int, array<string, mixed>> $elements Nœuds assainis.
	 * @param array<string, int>               $ids      Index id => position.
	 */
	private static function check_tree_integrity( array $elements, array $ids ): bool|\WP_Error {
		$has_root = false;

		foreach ( $elements as $node ) {
			if ( 0 === $node['parent'] ) {
				$has_root = true;
			} elseif ( ! isset( $ids[ $node['parent'] ] ) ) {
				return self::error(
					sprintf( 'Élément « %s » : le parent « %s » n’existe pas dans la structure.', $node['id'], $node['parent'] )
				);
			}

			foreach ( $node['children'] as $child ) {
				if ( ! isset( $ids[ $child ] ) ) {
					return self::error(
						sprintf( 'Élément « %s » : l’enfant « %s » n’existe pas dans la structure.', $node['id'], $child )
					);
				}

				if ( $elements[ $ids[ $child ] ]['parent'] !== $node['id'] ) {
					return self::error(
						sprintf(
							'Incohérence : « %s » est listé comme enfant de « %s », mais son parent déclaré est « %s ».',
							$child,
							$node['id'],
							(string) $elements[ $ids[ $child ] ]['parent']
						)
					);
				}
			}
		}

		if ( ! $has_root ) {
			return self::error( 'La structure ne contient aucun élément racine (parent = 0).' );
		}

		return self::check_cycles( $elements, $ids );
	}

	/**
	 * Détecte les cycles de parenté (A parent de B parent de A).
	 *
	 * @param array<int, array<string, mixed>> $elements Nœuds assainis.
	 * @param array<string, int>               $ids      Index id => position.
	 */
	private static function check_cycles( array $elements, array $ids ): bool|\WP_Error {
		$depth_limit = count( $elements ) + 1;

		foreach ( $elements as $node ) {
			$current = $node['parent'];
			$depth   = 0;

			while ( 0 !== $current ) {
				if ( ++$depth > $depth_limit ) {
					return self::error(
						sprintf( 'Cycle de parenté détecté autour de l’élément « %s ».', $node['id'] )
					);
				}

				$current = $elements[ $ids[ $current ] ]['parent'];
			}
		}

		return true;
	}

	/**
	 * Valide une liste de classes globales Bricks.
	 *
	 * @param mixed $classes    Liste brute.
	 * @param bool  $strict_bem Refuse les noms non conformes BEM.
	 *
	 * @return array<int, array<string, mixed>>|\WP_Error
	 */
	public static function global_classes( mixed $classes, bool $strict_bem = true ): array|\WP_Error {
		if ( ! is_array( $classes ) ) {
			return self::error( 'La liste des classes doit être un tableau.' );
		}

		$clean = [];
		$names = [];

		foreach ( array_values( $classes ) as $index => $class ) {
			if ( ! is_array( $class ) ) {
				return self::error( sprintf( 'Classe #%d : objet attendu.', $index ) );
			}

			$name = $class['name'] ?? '';

			if ( ! is_string( $name ) || '' === $name ) {
				return self::error( sprintf( 'Classe #%d : « name » manquant.', $index ) );
			}

			if ( $strict_bem && ! preg_match( self::BEM_PATTERN, $name ) ) {
				return self::error(
					sprintf(
						'Classe « %s » : nom non conforme BEM. Attendu bloc, bloc__element, bloc--modificateur ou bloc__element--modificateur, en minuscules.',
						$name
					)
				);
			}

			if ( isset( $names[ $name ] ) ) {
				return self::error( sprintf( 'Classe dupliquée : « %s ».', $name ) );
			}

			$names[ $name ] = true;

			$id = $class['id'] ?? '';

			if ( ! is_string( $id ) || ! preg_match( self::ID_PATTERN, $id ) ) {
				$id = Bricks_Adapter::generate_id();
			}

			$entry = [
				'id'       => $id,
				'name'     => $name,
				'settings' => is_array( $class['settings'] ?? null ) ? $class['settings'] : [],
			];

			if ( isset( $class['category'] ) && is_string( $class['category'] ) ) {
				$entry['category'] = $class['category'];
			}

			if ( isset( $class['modified'] ) && is_numeric( $class['modified'] ) ) {
				$entry['modified'] = (int) $class['modified'];
			}

			if ( isset( $class['selectors'] ) && is_array( $class['selectors'] ) ) {
				$entry['selectors'] = $class['selectors'];
			}

			$clean[] = $entry;
		}

		return $clean;
	}

	/**
	 * Valide une liste de variables globales Bricks.
	 *
	 * @param mixed $variables Liste brute.
	 *
	 * @return array<int, array<string, mixed>>|\WP_Error
	 */
	public static function variables( mixed $variables ): array|\WP_Error {
		if ( ! is_array( $variables ) ) {
			return self::error( 'La liste des variables doit être un tableau.' );
		}

		$clean = [];
		$names = [];

		foreach ( array_values( $variables ) as $index => $variable ) {
			if ( ! is_array( $variable ) ) {
				return self::error( sprintf( 'Variable #%d : objet attendu.', $index ) );
			}

			$name = $variable['name'] ?? '';

			if ( ! is_string( $name ) || ! preg_match( self::VARIABLE_PATTERN, $name ) ) {
				return self::error(
					sprintf( 'Variable #%d : nom invalide (« %s »). Attendu en kebab-case, sans les deux tirets initiaux.', $index, is_string( $name ) ? $name : '' )
				);
			}

			if ( isset( $names[ $name ] ) ) {
				return self::error( sprintf( 'Variable dupliquée : « %s ».', $name ) );
			}

			$names[ $name ] = true;

			$value = $variable['value'] ?? '';

			if ( ! is_string( $value ) && ! is_numeric( $value ) ) {
				return self::error( sprintf( 'Variable « %s » : la valeur doit être une chaîne.', $name ) );
			}

			$id = $variable['id'] ?? '';

			if ( ! is_string( $id ) || ! preg_match( self::ID_PATTERN, $id ) ) {
				$id = Bricks_Adapter::generate_id();
			}

			$entry = [
				'id'    => $id,
				'name'  => $name,
				'value' => (string) $value,
			];

			if ( isset( $variable['category'] ) && is_string( $variable['category'] ) ) {
				$entry['category'] = $variable['category'];
			}

			$clean[] = $entry;
		}

		return $clean;
	}

	/**
	 * Valide une liste de catégories (classes ou variables).
	 *
	 * @param mixed $categories Liste brute.
	 *
	 * @return array<int, array<string, string>>|\WP_Error
	 */
	public static function categories( mixed $categories ): array|\WP_Error {
		if ( ! is_array( $categories ) ) {
			return self::error( 'La liste des catégories doit être un tableau.' );
		}

		$clean = [];

		foreach ( array_values( $categories ) as $index => $category ) {
			if ( ! is_array( $category ) ) {
				return self::error( sprintf( 'Catégorie #%d : objet attendu.', $index ) );
			}

			$name = $category['name'] ?? '';

			if ( ! is_string( $name ) || '' === trim( $name ) ) {
				return self::error( sprintf( 'Catégorie #%d : « name » manquant.', $index ) );
			}

			$id = $category['id'] ?? '';

			if ( ! is_string( $id ) || ! preg_match( self::ID_PATTERN, $id ) ) {
				$id = Bricks_Adapter::generate_id();
			}

			$clean[] = [
				'id'   => $id,
				'name' => sanitize_text_field( $name ),
			];
		}

		return $clean;
	}

	private static function error( string $message, int $status = 400 ): \WP_Error {
		return new \WP_Error( 'anode_bridge_invalid', $message, [ 'status' => $status ] );
	}
}
