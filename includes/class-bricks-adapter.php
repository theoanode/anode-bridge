<?php
/**
 * Adaptateur Bricks : accès normalisé aux données du builder.
 *
 * Toutes les clés de stockage Bricks sont centralisées ici. Si Bricks change
 * une clé dans une version future, ce fichier est le seul à modifier.
 *
 * @package Anode\Bridge
 */

declare( strict_types = 1 );

namespace Anode\Bridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Bricks_Adapter {

	/* --- Métadonnées de post (Bricks 1.5+) --- */
	public const META_CONTENT     = '_bricks_page_content_2';
	public const META_HEADER      = '_bricks_page_header_2';
	public const META_FOOTER      = '_bricks_page_footer_2';
	public const META_SETTINGS    = '_bricks_page_settings';
	public const META_EDITOR_MODE = '_bricks_editor_mode';

	/* --- Templates --- */
	public const POST_TYPE_TEMPLATE = 'bricks_template';
	public const META_TEMPLATE_TYPE = '_bricks_template_type';
	public const META_TEMPLATE_SETTINGS = '_bricks_template_settings';

	/* --- Options globales --- */
	public const OPT_GLOBAL_CLASSES            = 'bricks_global_classes';
	public const OPT_GLOBAL_CLASSES_CATEGORIES = 'bricks_global_classes_categories';
	public const OPT_GLOBAL_CLASSES_TIMESTAMP  = 'bricks_global_classes_timestamp';
	public const OPT_GLOBAL_CLASSES_USER       = 'bricks_global_classes_user';
	public const OPT_GLOBAL_VARIABLES          = 'bricks_global_variables';
	public const OPT_GLOBAL_VARIABLES_CATEGORIES = 'bricks_global_variables_categories';
	public const OPT_COLOR_PALETTE             = 'bricks_color_palette';
	public const OPT_THEME_STYLES              = 'bricks_theme_styles';
	public const OPT_GLOBAL_SETTINGS           = 'bricks_global_settings';
	public const OPT_BREAKPOINTS               = 'bricks_breakpoints';
	public const OPT_COMPONENTS                = 'bricks_components';

	/** Zones éditables d'un post. */
	public const AREAS = [
		'content' => self::META_CONTENT,
		'header'  => self::META_HEADER,
		'footer'  => self::META_FOOTER,
	];

	/**
	 * Le thème Bricks est-il actif ?
	 */
	public static function is_available(): bool {
		return class_exists( '\Bricks\Database' );
	}

	/**
	 * Types de contenu éditables avec Bricks.
	 *
	 * @return array<int, string>
	 */
	public static function editable_post_types(): array {
		$types = [ 'page', 'post', self::POST_TYPE_TEMPLATE ];

		if ( self::is_available() && class_exists( '\Bricks\Database' ) ) {
			$setting = \Bricks\Database::get_setting( 'postTypes', [] );

			if ( is_array( $setting ) && $setting ) {
				$types = array_values( array_unique( array_merge( $types, $setting ) ) );
			}
		}

		return $types;
	}

	/**
	 * Lit la structure d'éléments d'une zone.
	 *
	 * @param int    $post_id ID du post.
	 * @param string $area    content | header | footer.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_elements( int $post_id, string $area = 'content' ): array {
		$meta_key = self::AREAS[ $area ] ?? self::META_CONTENT;
		$elements = get_post_meta( $post_id, $meta_key, true );

		return is_array( $elements ) ? $elements : [];
	}

	/**
	 * Écrit la structure d'éléments d'une zone, puis régénère le CSS.
	 *
	 * @param int                             $post_id  ID du post.
	 * @param array<int, array<string, mixed>> $elements Structure validée.
	 * @param string                          $area     content | header | footer.
	 */
	public static function set_elements( int $post_id, array $elements, string $area = 'content' ): void {
		$meta_key = self::AREAS[ $area ] ?? self::META_CONTENT;

		update_post_meta( $post_id, $meta_key, $elements );

		// Bascule le post en mode Bricks si ce n'est pas déjà le cas.
		if ( 'content' === $area && 'bricks' !== get_post_meta( $post_id, self::META_EDITOR_MODE, true ) ) {
			update_post_meta( $post_id, self::META_EDITOR_MODE, 'bricks' );
		}

		self::regenerate_css( $post_id );
	}

	/**
	 * Régénère les fichiers CSS de Bricks.
	 *
	 * Bricks peut servir son CSS depuis des fichiers statiques : sans cette
	 * étape, une modification faite via l'API n'apparaît pas en front.
	 *
	 * @param int|null $post_id Régénère seulement ce post si le CSS est en mode fichier.
	 */
	public static function regenerate_css( ?int $post_id = null ): void {
		if ( ! class_exists( '\Bricks\Assets_Files' ) ) {
			return;
		}

		// En mode « external files », Bricks écrit un fichier CSS par post.
		$css_loading = class_exists( '\Bricks\Database' )
			? \Bricks\Database::get_setting( 'cssLoading', '' )
			: '';

		if ( 'file' !== $css_loading ) {
			return;
		}

		\Bricks\Assets_Files::regenerate_css_files();
	}

	/* ------------------------------------------------------------------ */
	/* Classes globales                                                    */
	/* ------------------------------------------------------------------ */

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_global_classes(): array {
		$classes = get_option( self::OPT_GLOBAL_CLASSES, [] );

		return is_array( $classes ) ? array_values( $classes ) : [];
	}

	/**
	 * Remplace intégralement les classes globales.
	 *
	 * @param array<int, array<string, mixed>> $classes Classes validées.
	 */
	public static function set_global_classes( array $classes ): void {
		update_option( self::OPT_GLOBAL_CLASSES, array_values( $classes ), false );

		// Bricks compare ces deux valeurs pour détecter les conflits d'édition
		// simultanée dans le builder : on les met à jour comme le ferait le builder.
		update_option( self::OPT_GLOBAL_CLASSES_TIMESTAMP, time(), false );
		update_option( self::OPT_GLOBAL_CLASSES_USER, get_current_user_id(), false );

		self::regenerate_css();
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_class_categories(): array {
		$categories = get_option( self::OPT_GLOBAL_CLASSES_CATEGORIES, [] );

		return is_array( $categories ) ? array_values( $categories ) : [];
	}

	/**
	 * @param array<int, array<string, mixed>> $categories Catégories validées.
	 */
	public static function set_class_categories( array $categories ): void {
		update_option( self::OPT_GLOBAL_CLASSES_CATEGORIES, array_values( $categories ), false );
	}

	/* ------------------------------------------------------------------ */
	/* Variables globales                                                  */
	/* ------------------------------------------------------------------ */

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_variables(): array {
		$variables = get_option( self::OPT_GLOBAL_VARIABLES, [] );

		return is_array( $variables ) ? array_values( $variables ) : [];
	}

	/**
	 * @param array<int, array<string, mixed>> $variables Variables validées.
	 */
	public static function set_variables( array $variables ): void {
		update_option( self::OPT_GLOBAL_VARIABLES, array_values( $variables ), false );
		self::regenerate_css();
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_variable_categories(): array {
		$categories = get_option( self::OPT_GLOBAL_VARIABLES_CATEGORIES, [] );

		return is_array( $categories ) ? array_values( $categories ) : [];
	}

	/**
	 * @param array<int, array<string, mixed>> $categories Catégories validées.
	 */
	public static function set_variable_categories( array $categories ): void {
		update_option( self::OPT_GLOBAL_VARIABLES_CATEGORIES, array_values( $categories ), false );
	}

	/* ------------------------------------------------------------------ */
	/* Palette de couleurs                                                 */
	/* ------------------------------------------------------------------ */

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_color_palette(): array {
		$palette = get_option( self::OPT_COLOR_PALETTE, [] );

		return is_array( $palette ) ? array_values( $palette ) : [];
	}

	/**
	 * @param array<int, array<string, mixed>> $palette Palettes validées.
	 */
	public static function set_color_palette( array $palette ): void {
		update_option( self::OPT_COLOR_PALETTE, array_values( $palette ), false );
	}

	/**
	 * Génère un identifiant au format Bricks (6 caractères alphanumériques).
	 */
	public static function generate_id(): string {
		return substr( str_replace( [ '.', '-' ], '', uniqid( '', true ) ), -6 );
	}
}
