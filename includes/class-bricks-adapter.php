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
	 * Préfixe de l'empreinte de ce que **nous** avons écrit, par zone.
	 *
	 * ## Pourquoi elle existe
	 *
	 * `set_elements()` remplace la zone visée. C'est cohérent avec le reste du
	 * dispositif — on édite une source de vérité, on régénère, on applique — et
	 * c'est **le trou du dispositif**, parce que la mise en page est justement ce
	 * qu'un humain retouche le plus : on ouvre Bricks, on déplace un bloc, on
	 * corrige un texte. La prochaine écriture l'effaçait sans un mot.
	 *
	 * Les commandes du dépôt ont leur garde depuis le 06/08/2026 (§10 bis) :
	 * `apply-pages`, `apply-posts` et `apply-composants` refusent devant un écart.
	 * Celle-ci manquait, et c'était la plus importante : un outil MCP, appelable
	 * sans passer par aucun script.
	 *
	 * ## Ce qu'une empreinte permet de demander
	 *
	 * Comparer au dépôt ne marche pas : `bricks_set_page` reçoit un arbre
	 * quelconque, qui ne vient pas forcément d'un fichier versionné. La seule
	 * question posable est donc **« la zone a-t-elle changé depuis notre dernière
	 * écriture ? »** — et elle demande de retenir ce qu'on a écrit.
	 *
	 * D'où trois cas, et le troisième est celui qu'on oublie :
	 *
	 * | État | Conduite |
	 * |---|---|
	 * | empreinte présente et **égale** au contenu servi | personne n'a touché depuis nous : on écrit |
	 * | empreinte présente et **différente** | quelqu'un a touché : **on refuse**, sauf demande explicite |
	 * | **aucune empreinte**, et la zone porte déjà du contenu | on ne sait pas d'où il vient : **on refuse**. Écrire serait effacer un travail dont on ignore l'auteur |
	 *
	 * Une zone vide sans empreinte s'écrit librement : il n'y a rien à perdre.
	 *
	 * ## Le sens de l'erreur qu'elle peut faire
	 *
	 * Un faux positif — refuser alors que rien n'a bougé — coûte une commande de
	 * plus. Un faux négatif coûte un travail. L'empreinte penche donc du côté du
	 * refus : elle porte sur les octets réellement stockés, sans tolérance.
	 */
	public const META_EMPREINTE = '_anode_empreinte_';

	/**
	 * Empreinte stable d'une structure d'éléments.
	 *
	 * Les clés associatives sont triées **récursivement** avant l'encodage : PHP et
	 * `json_decode` ne garantissent pas l'ordre des clés d'un objet, et un simple
	 * réordonnancement ferait crier la garde sur une zone que personne n'a touchée.
	 * Les listes, elles, gardent leur ordre — c'est l'ordre des éléments d'une page,
	 * et le changer *est* une modification.
	 */
	public static function empreinte( array $elements ): string {
		return hash( 'sha256', (string) wp_json_encode( self::canoniser( $elements ) ) );
	}

	/**
	 * @param mixed $valeur
	 * @return mixed
	 */
	private static function canoniser( $valeur ) {
		if ( ! is_array( $valeur ) ) {
			return $valeur;
		}

		$trie = array_is_list( $valeur );

		$valeur = array_map( [ self::class, 'canoniser' ], $valeur );

		if ( ! $trie ) {
			ksort( $valeur );
		}

		return $valeur;
	}

	/** L'empreinte retenue pour une zone, ou null si nous n'y avons jamais écrit. */
	public static function empreinte_retenue( int $post_id, string $area ): ?string {
		$retenue = get_post_meta( $post_id, self::META_EMPREINTE . $area, true );

		return is_string( $retenue ) && '' !== $retenue ? $retenue : null;
	}

	/**
	 * Le verdict, avant d'écrire.
	 *
	 * Rendu séparé de l'écriture à dessein : c'est une décision, elle se lit et
	 * s'éprouve sans base de données ni requête HTTP.
	 *
	 * @param ?string $retenue  Empreinte de notre dernière écriture, ou null.
	 * @param string  $servie   Empreinte du contenu actuellement en place.
	 * @param bool    $vide     La zone est-elle vide ?
	 * @return array{ecrire: bool, motif: string}
	 */
	public static function verdict( ?string $retenue, string $servie, bool $vide ): array {
		if ( null === $retenue ) {
			return $vide
				? [ 'ecrire' => true, 'motif' => 'zone-vierge' ]
				: [ 'ecrire' => false, 'motif' => 'provenance-inconnue' ];
		}

		return $retenue === $servie
			? [ 'ecrire' => true, 'motif' => 'inchangee-depuis-nous' ]
			: [ 'ecrire' => false, 'motif' => 'modifiee-a-la-main' ];
	}

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

		/*
		 * On retient ce qu'on vient d'écrire, dans le même geste. C'est ce qui
		 * permettra à la prochaine écriture de savoir si quelqu'un est passé entre
		 * les deux — voir META_EMPREINTE.
		 *
		 * Posée ici et pas dans la route : toute écriture doit la mettre à jour, y
		 * compris celle d'un template ou celle d'un futur appelant. Une écriture qui
		 * oublierait l'empreinte laisserait la garde crier au prochain passage sur
		 * une page que personne n'a touchée — un faux positif qui apprend à passer
		 * outre, ce qui est la pire façon de perdre une garde.
		 */
		update_post_meta( $post_id, self::META_EMPREINTE . $area, self::empreinte( $elements ) );

		// Bascule le post en mode Bricks si ce n'est pas déjà le cas.
		if ( 'content' === $area && 'bricks' !== get_post_meta( $post_id, self::META_EDITOR_MODE, true ) ) {
			update_post_meta( $post_id, self::META_EDITOR_MODE, 'bricks' );
		}

		self::regenerate_css( $post_id );
		self::announce_change( $post_id );
	}

	/**
	 * Annonce qu'une page a changé, pour que les caches s'invalident.
	 *
	 * **`update_post_meta` ne déclenche pas `save_post`.** Une mise en page écrite
	 * par l'API modifiait donc le site sans que rien ne l'apprenne : le cache de
	 * page continuait de servir l'ancienne version, et la correction n'apparaissait
	 * pas. Le symptôme est le pire possible — on croit que l'écriture a échoué, on
	 * la refait, et le résultat ne change toujours pas.
	 *
	 * Ce n'est pas un défaut du cache : c'est l'écriture qui était muette. Aucun
	 * cache, maison ou serveur, ne pouvait faire mieux.
	 *
	 * Les trois gestes, du plus général au plus particulier :
	 *
	 * 1. `clean_post_cache()` — le cache d'objets et les transitoires du noyau ;
	 * 2. `anode/bricks/page_updated` — le point d'accroche du projet, pour un
	 *    composant qui voudrait s'y brancher ;
	 * 3. la purge du cache de l'hébergeur, **ciblée sur la page**.
	 *
	 * Ce dernier point n'est pas un détail. Purger le cache entier du serveur à
	 * chaque écriture de page — ce que faisait la première version — vide le
	 * travail de tout le site pour un titre corrigé : sur une écriture par lot,
	 * une trentaine de pages, le cache ne se reconstitue jamais. On ne retombe
	 * donc sur la purge totale que si le cache en place n'offre rien de plus fin.
	 *
	 * On ne déclenche **pas** `save_post` artificiellement : tout ce qui y est
	 * accroché partirait — révisions, notifications, réindexations — pour une
	 * écriture qui ne touche qu'une méta.
	 */
	private static function announce_change( int $post_id ): void {
		clean_post_cache( $post_id );

		/**
		 * Une page Bricks vient d'être réécrite par l'API.
		 *
		 * @param int $post_id
		 */
		do_action( 'anode/bricks/page_updated', $post_id );

		// LiteSpeed — cache serveur d'Hostinger : purge de la seule page.
		if ( has_action( 'litespeed_purge_post' ) ) {
			do_action( 'litespeed_purge_post', $post_id );

			return;
		}

		// nginx FastCGI via nginx-helper : il ne sait purger qu'une URL, ou tout.
		if ( has_action( 'rt_nginx_helper_purge_url' ) ) {
			do_action( 'rt_nginx_helper_purge_url', get_permalink( $post_id ), true );

			return;
		}

		if ( has_action( 'rt_nginx_helper_purge_all' ) ) {
			do_action( 'rt_nginx_helper_purge_all' );
		}
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
	 * Remet une option en chargement automatique si elle en a été sortie.
	 *
	 * Une version antérieure du pont passait `false` en troisième argument
	 * d'`update_option`, ce qui écrit `off` — une valeur **explicite**, que le
	 * noyau ne réévalue plus jamais. Bricks lisant ces options à chaque requête du
	 * site, chacune coûtait dès lors une requête SQL par page vue.
	 *
	 * L'écriture est directe : `update_option` ne sait pas changer l'autoload sans
	 * changer la valeur, et la relire pour la réécrire ferait un aller-retour
	 * inutile sur une option volumineuse.
	 *
	 * Faite à chaque écriture, mais sans coût : la clause `autoload IN` ne touche
	 * aucune ligne sur un site déjà correct.
	 */
	private static function reparer_autoload(): void {
		global $wpdb;

		/*
		 * Toutes les options que Bricks charge dans `Database::load_data()`, donc à
		 * chaque requête du site. Chacune sortie du chargement automatique coûte une
		 * requête SQL par page vue — sept options, sept requêtes, sans que rien ne
		 * le signale.
		 */
		$options = [
			self::OPT_GLOBAL_CLASSES,
			self::OPT_GLOBAL_CLASSES_CATEGORIES,
			self::OPT_GLOBAL_VARIABLES,
			self::OPT_GLOBAL_VARIABLES_CATEGORIES,
			self::OPT_GLOBAL_SETTINGS,
			self::OPT_BREAKPOINTS,
			self::OPT_COMPONENTS,
			self::OPT_COLOR_PALETTE,
			self::OPT_THEME_STYLES,
		];

		$trous = implode( ', ', array_fill( 0, count( $options ), '%s' ) );

		// Une seule requête, idempotente : la clause `autoload IN` fait qu'un site
		// déjà correct n'écrit rien.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE `{$wpdb->options}` SET autoload = 'on' WHERE option_name IN ( {$trous} ) AND autoload IN ( 'off', 'no' )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				...$options
			)
		);
	}

	/**
	 * Remplace intégralement les classes globales.
	 *
	 * Le troisième argument d'`update_option` vaut `null` — « ne change pas
	 * l'autoload » — et non `false`. Bricks charge ses classes globales à chaque
	 * requête du site pour émettre leur CSS : les sortir du chargement automatique
	 * ajoutait une requête SQL à chaque page vue, sans que rien ne le signale.
	 * Une écriture n'a pas à décider de la façon dont l'option est ensuite lue.
	 *
	 * Mais `null` **ne répare pas** un site déjà passé par la version fautive :
	 * le noyau ne réévalue l'autoload que si la valeur enregistrée vaut `auto`,
	 * `auto-on` ou `auto-off`, or `update_option( …, false )` avait écrit `off`,
	 * une valeur explicite. D'où la remise en chargement automatique ci-dessous,
	 * faite une fois, à la première écriture qui suit la mise à jour.
	 *
	 * @param array<int, array<string, mixed>> $classes Classes validées.
	 */
	public static function set_global_classes( array $classes ): void {
		self::reparer_autoload();

		update_option( self::OPT_GLOBAL_CLASSES, array_values( $classes ), null );

		// Bricks compare ces deux valeurs pour détecter les conflits d'édition
		// simultanée dans le builder : on les met à jour comme le ferait le builder.
		update_option( self::OPT_GLOBAL_CLASSES_TIMESTAMP, time(), null );
		update_option( self::OPT_GLOBAL_CLASSES_USER, get_current_user_id(), null );

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
		update_option( self::OPT_GLOBAL_CLASSES_CATEGORIES, array_values( $categories ), null );
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
		// `null` : l'autoload reste tel quel — Bricks lit ses variables à chaque
		// requête pour émettre les `var(--…)` du site.
		self::reparer_autoload();

		update_option( self::OPT_GLOBAL_VARIABLES, array_values( $variables ), null );
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
		update_option( self::OPT_GLOBAL_VARIABLES_CATEGORIES, array_values( $categories ), null );
	}

	/* ------------------------------------------------------------------ */
	/* Composants                                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * Composants Bricks (Bricks 1.12+).
	 *
	 * Un composant est un sous-arbre d'éléments défini une seule fois, plus une
	 * liste de propriétés qui en rendent certains réglages variables. Chaque page
	 * n'en garde qu'une instance — un élément portant `cid` — si bien qu'une
	 * modification de structure se répercute partout d'un coup.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_components(): array {
		$components = get_option( self::OPT_COMPONENTS, [] );

		return is_array( $components ) ? array_values( $components ) : [];
	}

	/**
	 * Remplace intégralement les composants.
	 *
	 * @param array<int, array<string, mixed>> $components Composants validés.
	 */
	public static function set_components( array $components ): void {
		// `null` : l'autoload reste tel quel — un composant est rendu à chaque
		// requête sur les pages qui en portent une instance.
		update_option( self::OPT_COMPONENTS, array_values( $components ), null );

		/*
		 * Bricks garde les composants dans son cache de données globales, chargé
		 * une fois par requête. Sans cette remise à jour, une écriture suivie
		 * d'un rendu dans la même requête rendrait l'état précédent.
		 */
		if ( class_exists( '\Bricks\Database' ) && is_array( \Bricks\Database::$global_data ) ) {
			\Bricks\Database::$global_data['components'] = array_values( $components );
		}

		self::regenerate_css();
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
		update_option( self::OPT_COLOR_PALETTE, array_values( $palette ), null );
	}

	/**
	 * Génère un identifiant au format Bricks (6 caractères alphanumériques).
	 */
	public static function generate_id(): string {
		return substr( str_replace( [ '.', '-' ], '', uniqid( '', true ) ), -6 );
	}
}
