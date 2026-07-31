<?php
/**
 * État de santé des extensions maison — chargement, version, fonctionnement.
 *
 * WordPress ne sait pas gérer les mu-plugins rangés en sous-dossier : il ne les
 * liste pas, ne dit pas s'ils ont été chargés, et n'affiche jamais leur version.
 * Un composant peut donc être **présent sur le disque et totalement inerte** —
 * chargeur absent, copie dans `plugins/` qu'on croit tester, erreur fatale
 * avalée par le silence de la production.
 *
 * Cette route répond à trois questions distinctes, et l'ordre compte :
 *
 *   1. le composant est-il **chargé** ? — un symbole de son espace de noms existe
 *      en mémoire. C'est la seule preuve : un fichier lisible ne prouve rien.
 *   2. quelle **version** tourne ? — relue dans l'en-tête du fichier chargé, pas
 *      dans le dépôt.
 *   3. **fait-il son travail** ? — une sonde par composant, qui vérifie ce qui
 *      casserait en silence : un dossier de cache non inscriptible, un `id` de
 *      champ SEO non exposé à l'API, un slug de connexion resté par défaut.
 *
 * @package Anode\Bridge
 */

declare( strict_types = 1 );

namespace Anode\Bridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Rest_Health {

	/**
	 * Composants attendus, et le symbole qui prouve leur chargement.
	 *
	 * Le symbole est choisi dans l'espace de noms du composant : il ne peut pas
	 * être défini par autre chose. `anode-bridge` n'y figure pas — s'il ne
	 * répondait pas, il n'y aurait pas de réponse du tout.
	 *
	 * @var array<string, array{symbole: string, genre: string}>
	 */
	private const COMPOSANTS = [
		'anode-hardening'    => [ 'symbole' => 'Anode\Hardening\login_slug', 'genre' => 'function' ],
		'anode-seo'          => [ 'symbole' => 'Anode\Seo\Seo', 'genre' => 'class' ],
		'anode-forms'        => [ 'symbole' => 'Anode\Forms\definitions_dir', 'genre' => 'function' ],
		'anode-redirections' => [ 'symbole' => 'Anode\Redirections\table_path', 'genre' => 'function' ],
		'anode-updater'      => [ 'symbole' => 'Anode\Updater\Updater', 'genre' => 'class' ],
	];

	public function register_routes(): void {
		register_rest_route(
			NAMESPACE_,
			'/health',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_health' ],
				'permission_callback' => [ Security::class, 'permission_read' ],
			]
		);
	}

	public function get_health(): \WP_REST_Response {
		$mu = defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';

		$composants = [];
		$problemes  = [];

		/*
		 * Le chargeur d'abord : sans lui, tout le reste est sur le disque et rien
		 * n'est en mémoire. Le diagnostic « six composants absents » vient presque
		 * toujours de ce seul fichier.
		 */
		$chargeur = $mu . '/anode-loader.php';
		$loader   = [
			'present' => is_readable( $chargeur ),
			/*
			 * Chemin relatif à `wp-content` : la route est ouverte en lecture, et
			 * l'arborescence d'installation d'un serveur n'a pas à en sortir. Le
			 * chemin complet ne dit rien de plus à qui diagnostique.
			 */
			'path'    => 'mu-plugins/anode-loader.php',
		];

		if ( ! $loader['present'] ) {
			$problemes[] = 'Le chargeur anode-loader.php est absent : aucun mu-plugin en sous-dossier n’est chargé.';
		}

		foreach ( self::COMPOSANTS as $nom => $attendu ) {
			$dossier = $mu . '/' . $nom;
			$fichier = $dossier . '/' . $nom . '.php';
			$charge  = 'class' === $attendu['genre']
				? class_exists( $attendu['symbole'] )
				: function_exists( $attendu['symbole'] );

			$entree = [
				'name'      => $nom,
				'present'   => is_readable( $fichier ),
				'loaded'    => $charge,
				'version'   => $this->version( $fichier ),
				'checks'    => $charge ? $this->sondes( $nom ) : [],
			];

			if ( ! $entree['present'] ) {
				$problemes[] = sprintf( '%s : absent de mu-plugins/.', $nom );
			} elseif ( ! $charge ) {
				$problemes[] = sprintf(
					'%s : présent mais **non chargé** — %s introuvable en mémoire. Erreur fatale avalée, ou chargeur muet.',
					$nom,
					$attendu['symbole']
				);
			}

			foreach ( $entree['checks'] as $sonde ) {
				if ( ! $sonde['ok'] ) {
					$problemes[] = sprintf( '%s : %s', $nom, $sonde['detail'] );
				}
			}

			$composants[] = $entree;
		}

		// Le pont lui-même : il répond, donc il tourne.
		$composants[] = [
			'name'    => 'anode-bridge',
			'present' => true,
			'loaded'  => true,
			'version' => ANODE_BRIDGE_VERSION,
			'checks'  => $this->sondes( 'anode-bridge' ),
		];

		/*
		 * Une copie dans `plugins/` est inerte : le chargeur ne lit que les
		 * sous-dossiers de `mu-plugins/`. On croit alors tester une version qui
		 * n'est jamais exécutée — l'erreur a déjà fait perdre une demi-journée.
		 */
		$doublons = [];

		/*
		 * `array_keys(…) + […]` est une **union de tableaux**, pas une
		 * concaténation : les deux listes sont indexées depuis 0, la collision
		 * d'indice écartait « anode-bridge » — le seul composant qu'on installe
		 * parfois d'abord en extension, donc celui qui a le plus de chances
		 * d'avoir une copie inerte dans plugins/.
		 */
		foreach ( array_merge( array_keys( self::COMPOSANTS ), [ 'anode-bridge' ] ) as $nom ) {
			if ( is_dir( WP_PLUGIN_DIR . '/' . $nom ) ) {
				$doublons[]  = $nom;
				$problemes[] = sprintf(
					'%s : une copie existe dans plugins/. Elle est **inerte** — le chargeur ne lit que mu-plugins/.',
					$nom
				);
			}
		}

		$fatales = $this->fatales_recentes();

		if ( $fatales ) {
			$problemes[] = sprintf( '%d erreur(s) fatale(s) mentionnant « anode » dans le journal de débogage.', count( $fatales ) );
		}

		/*
		 * Une ligne de debug.log cite le chemin absolu du fichier, la trace
		 * d'appel et parfois un extrait de requête : c'est de la reconnaissance
		 * offerte à qui n'a que la lecture. Le **nombre** reste dans les
		 * problèmes — il suffit à savoir qu'il faut regarder — mais le contenu
		 * n'est servi qu'à l'administration.
		 */
		$journal = Security::can_manage() ? $fatales : [];

		return rest_ensure_response(
			[
				'ok'          => ! $problemes,
				/*
				 * Le masquage de l'URL de connexion pouvait s'éteindre selon
				 * l'environnement. Sans cette information, un test de bout en bout
				 * conclut soit à un défaut, soit — bien pire — à un succès qui ne
				 * prouve rien : « la page n'est pas en cache » est vrai quand le
				 * cache est éteint.
				 */
				'environment' => wp_get_environment_type(),
				'features'    => [
					'login_masking' => function_exists( 'Anode\Hardening\login_masking_enabled' )
						&& \Anode\Hardening\login_masking_enabled(),
				],
				'loader'      => $loader,
				'components'  => $composants,
				'shadowed'    => $doublons,
				'fatals'      => $journal,
				'problems'    => $problemes,
			]
		);
	}

	/**
	 * Version déclarée dans l'en-tête du fichier réellement installé.
	 *
	 * C'est de là que découlent le tag, l'archive et la comparaison faite par
	 * `anode-updater` : lire ailleurs donnerait une réponse qui n'engage rien.
	 */
	private function version( string $fichier ): ?string {
		if ( ! is_readable( $fichier ) ) {
			return null;
		}

		$tete = (string) file_get_contents( $fichier, false, null, 0, 4096 );

		return preg_match( '/^\s*\*?\s*Version:\s*([0-9][0-9.]*)/mi', $tete, $trouve )
			? $trouve[1]
			: null;
	}

	/**
	 * Sondes fonctionnelles d'un composant.
	 *
	 * Chacune vise ce qui **casse en silence** : le composant est chargé, la page
	 * s'affiche, et la fonction ne se fait pas. Une sonde qui se contenterait de
	 * vérifier une constante ne vaudrait pas la ligne qu'elle occupe.
	 *
	 * @return list<array{id: string, ok: bool, detail: string}>
	 */
	private function sondes( string $nom ): array {
		switch ( $nom ) {
			case 'anode-forms':
				return $this->sondes_forms();

			case 'anode-hardening':
				return $this->sondes_hardening();

			case 'anode-seo':
				return $this->sondes_seo();

			case 'anode-redirections':
				return $this->sondes_redirections();

			case 'anode-updater':
				return $this->sondes_updater();

			case 'anode-bridge':
				return $this->sondes_bridge();
		}

		return [];
	}

	/** @return list<array{id: string, ok: bool, detail: string}> */
	private function sondes_forms(): array {
		$dossier     = \Anode\Forms\definitions_dir();
		// Relatif au thème, comme les autres : la route est ouverte en lecture.
		$court       = 'theme/' . basename( dirname( $dossier ) ) . '/' . basename( $dossier );
		$definitions = is_dir( $dossier ) ? (array) glob( $dossier . '/*.json' ) : [];
		$invalides   = [];

		foreach ( $definitions as $chemin ) {
			$decode = json_decode( (string) file_get_contents( $chemin ), true );

			if ( ! is_array( $decode ) || ! isset( $decode['screens'] ) ) {
				$invalides[] = basename( $chemin );
			}
		}

		$routes = rest_get_server()->get_routes( NAMESPACE_ );

		return [
			$this->sonde(
				'forms-definitions',
				(bool) $definitions,
				sprintf( 'aucune définition de formulaire dans %s — un bloc data-form n’afficherait rien.', $court ),
				sprintf( '%d définition(s) de formulaire lisibles', count( $definitions ) )
			),
			$this->sonde(
				'forms-valides',
				! $invalides,
				sprintf( 'définition(s) illisibles ou sans « screens » : %s.', implode( ', ', $invalides ) ),
				'toutes les définitions ont des écrans'
			),
			$this->sonde(
				'forms-route',
				(bool) preg_grep( '#/form/#', array_keys( $routes ) ),
				'la route /form/<nom> n’est pas enregistrée — le navigateur ne pourra pas lire la définition.',
				'route /form/<nom> enregistrée'
			),
			$this->sonde_fichiers_proteges(),
			$this->sonde_relais_declares(),
		];
	}

	/**
	 * Chaque formulaire a-t-il un relais où envoyer ?
	 *
	 * Le site n'enregistre rien : le webhook est la seule sortie d'une demande.
	 * Sans lui, chaque envoi est refusé — la personne le voit tout de suite, ce
	 * qui vaut mieux qu'un silence, mais le formulaire est inutilisable.
	 *
	 * C'est le défaut le plus probable d'une mise en ligne : la définition est
	 * livrée avec un `webhook` vide, et personne ne s'en aperçoit avant la
	 * première demande perdue.
	 *
	 * @return array<string, mixed>
	 */
	private function sonde_relais_declares(): array {
		if ( ! function_exists( 'Anode\Forms\definitions_dir' ) ) {
			return $this->sonde( 'forms-relais', true, '', 'moteur de formulaire absent' );
		}

		$sans = [];

		foreach ( glob( \Anode\Forms\definitions_dir() . '/*.json' ) ?: [] as $fichier ) {
			$definition = json_decode( (string) file_get_contents( $fichier ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

			if ( ! is_array( $definition ) ) {
				continue;
			}

			$urls = array_filter(
				array_map(
					'trim',
					array_merge(
						[ (string) ( $definition['webhook'] ?? '' ) ],
						array_map( 'strval', (array) ( $definition['webhooks'] ?? [] ) )
					)
				)
			);

			if ( ! $urls ) {
				$sans[] = basename( $fichier, '.json' );
			}
		}

		if ( ! $sans ) {
			return $this->sonde( 'forms-relais', true, '', 'tous les formulaires ont un relais' );
		}

		/*
		 * Hors production, l'absence de relais reste attendue : un blueprint neuf
		 * n'a pas d'adresse où envoyer, et rendre la santé rouge en permanence
		 * ferait une alerte qu'on ne lit plus.
		 *
		 * Mais le détail ne dit plus « à renseigner avant la mise en ligne » : ces
		 * formulaires **refusent déjà** les envois, sur tous les environnements,
		 * et c'est voulu. La formulation précédente laissait croire que la
		 * préproduction acceptait les demandes en attendant — elle les acceptait
		 * en effet, et les jetait en répondant « merci ». Le comportement a été
		 * corrigé ; ce message était la seule trace qui l'annonçait de travers.
		 */
		$production = 'production' === wp_get_environment_type();

		return $this->sonde(
			'forms-relais',
			! $production,
			sprintf(
				'aucun webhook déclaré pour : %s. Le site n’enregistre rien — chaque envoi de ces formulaires est refusé.',
				implode( ', ', $sans )
			),
			sprintf(
				'aucun webhook pour %s : leurs envois sont refusés, ce qui est attendu hors production',
				implode( ', ', $sans )
			)
		);
	}

	/**
	 * Une définition déclare-t-elle un champ de type `file` ?
	 */
	private function un_formulaire_attend_un_fichier(): bool {
		if ( ! function_exists( 'Anode\Forms\definitions_dir' ) ) {
			return false;
		}

		foreach ( (array) glob( \Anode\Forms\definitions_dir() . '/*.json' ) as $chemin ) {
			$brut = json_decode( (string) file_get_contents( $chemin ), true );

			if ( ! is_array( $brut ) ) {
				continue;
			}

			$champs = array_merge(
				is_array( $brut['fields'] ?? null ) ? $brut['fields'] : [],
				...array_map(
					static fn ( $ecran ): array => is_array( $ecran['fields'] ?? null ) ? $ecran['fields'] : [],
					is_array( $brut['screens'] ?? null ) ? $brut['screens'] : []
				)
			);

			foreach ( $champs as $champ ) {
				if ( is_array( $champ ) && 'file' === ( $champ['type'] ?? '' ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Le dossier des pièces jointes est-il réellement hors de portée du web ?
	 *
	 * Le composant y pose un `.htaccess` qui refuse tout — mais **nginx ne le lit
	 * pas**, ni Caddy, ni LiteSpeed en mode natif. Sur ces serveurs, une pièce
	 * jointe reste servie à qui connaît son adresse. Le nom du fichier est tiré au
	 * sort sur 62^16, ce qui rend l'adresse indevinable ; c'est une protection
	 * réelle, mais c'en est **une seule**, et l'affirmer sans la mesurer serait
	 * exactement le genre de promesse qu'on découvre fausse le jour où elle
	 * compte.
	 *
	 * La sonde demande donc au serveur un fichier témoin du dossier, et rapporte
	 * ce qu'il en fait. La réponse est mise en cache un jour : c'est un réglage de
	 * serveur, il ne change pas d'une heure à l'autre.
	 *
	 * @return array{id: string, ok: bool, detail: string}
	 */
	private function sonde_fichiers_proteges(): array {
		/*
		 * Sans champ de fichier déclaré, rien n'est jamais déposé : la question ne
		 * se pose pas, et l'échec ferait crier au loup sur la totalité du parc.
		 * On la pose le jour où un formulaire attend une pièce jointe.
		 */
		if ( ! $this->un_formulaire_attend_un_fichier() ) {
			return $this->sonde(
				'forms-fichiers-proteges',
				true,
				'',
				'aucun formulaire n’attend de pièce jointe'
			);
		}

		$cache = get_transient( 'anode_bridge_fichiers_proteges' );

		if ( is_array( $cache ) ) {
			return $cache;
		}

		/*
		 * Le dossier et son témoin sont posés avant de mesurer : ils ne sont créés
		 * qu'au premier envoi, et un 404 sur un fichier absent ne dit rien de la
		 * protection — il l'annoncerait même comme acquise.
		 */
		if ( function_exists( 'Anode\Forms\proteger_dossier' ) ) {
			\Anode\Forms\proteger_dossier();
		}

		// Le témoin porte l'extension d'une vraie pièce jointe : un serveur refuse
		// volontiers un `.php` tout en servant les `.pdf` du même dossier.
		$base = (string) ( wp_upload_dir()['baseurl'] ?? '' ) . '/anode-formulaires/temoin-anode.pdf';

		$reponse = wp_remote_get(
			$base,
			[
				'timeout'   => 5,
				'sslverify' => false,
				// Un cache de page ne doit pas répondre à la place du serveur.
				'headers'   => [ 'Cache-Control' => 'no-cache' ],
			]
		);

		$code = is_wp_error( $reponse ) ? 0 : (int) wp_remote_retrieve_response_code( $reponse );

		/*
		 * 403 et 404 sont l'un et l'autre corrects : le premier est la règle
		 * serveur, le second un dossier que rien n'expose. Un 200 dit que le
		 * dossier est servi, et que seule l'imprévisibilité du nom protège.
		 */
		$sonde = $this->sonde(
			'forms-fichiers-proteges',
			200 !== $code,
			sprintf(
				'le dossier des pièces jointes est servi par le serveur web (HTTP %d) : seule l’imprévisibilité du nom protège. '
				. 'Ajoutez une règle serveur — voir docs/formulaires.md.',
				$code
			),
			0 === $code
				? 'dossier des pièces jointes injoignable — protection en place'
				: sprintf( 'dossier des pièces jointes refusé par le serveur (HTTP %d)', $code )
		);

		set_transient( 'anode_bridge_fichiers_proteges', $sonde, DAY_IN_SECONDS );

		return $sonde;
	}

	/** @return list<array{id: string, ok: bool, detail: string}> */
	private function sondes_hardening(): array {
		$slug = \Anode\Hardening\login_slug();

		return [
			$this->sonde(
				'hardening-login',
				'' !== $slug && 'wp-login' !== $slug && 'wp-login.php' !== $slug,
				'l’URL de connexion est restée celle de WordPress : le durcissement ne masque rien.',
				sprintf( 'URL de connexion déplacée (/%s)', $slug )
			),
			$this->sonde(
				'hardening-editeur',
				defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT,
				'DISALLOW_FILE_EDIT n’est pas actif : l’éditeur de fichiers du tableau de bord reste ouvert.',
				'éditeur de fichiers désactivé'
			),
			$this->sonde(
				'hardening-entetes',
				(bool) has_filter( 'wp_headers' ),
				'aucun filtre wp_headers : les en-têtes de sécurité ne sont pas émis.',
				'en-têtes de sécurité branchés'
			),
		];
	}

	/** @return list<array{id: string, ok: bool, detail: string}> */
	private function sondes_seo(): array {
		/*
		 * `Seo::FIELDS` liste des **suffixes** : la clé enregistrée porte le
		 * préfixe. Interroger le suffixe nu renvoie « absent » pour les cinq
		 * champs, sur un site où ils fonctionnent parfaitement.
		 */
		$champs   = array_keys( \Anode\Seo\Seo::FIELDS );
		$manquant = [];

		foreach ( $champs as $champ ) {
			if ( ! registered_meta_key_exists( 'post', \Anode\Seo\Seo::PREFIX . $champ, 'page' ) ) {
				$manquant[] = $champ;
			}
		}

		/*
		 * `anode-seo` s'efface d'elle-même si une extension SEO tierce est active.
		 * Le signaler comme un défaut serait faux : c'est le comportement voulu.
		 */
		$tierce = defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) || class_exists( 'All_in_One_SEO_Pack' );

		return [
			$this->sonde(
				'seo-champs',
				$tierce || ! $manquant,
				sprintf(
					'champ(s) SEO non exposés à l’API REST : %s. Les scripts écriraient dans le vide.',
					implode( ', ', $manquant )
				),
				$tierce
					? 'en retrait : une extension SEO tierce est active'
					: sprintf( '%d champs SEO exposés à l’API', count( $champs ) )
			),
			$this->sonde(
				'seo-titre',
				$tierce || (bool) has_filter( 'pre_get_document_title' ) || (bool) has_filter( 'document_title_parts' ),
				'aucun filtre de titre : la balise <title> reste celle de WordPress.',
				$tierce ? 'titre laissé à l’extension tierce' : 'titre piloté par anode-seo'
			),
			/*
			 * Un domaine de recette traité en production.
			 *
			 * `wp_get_environment_type()` rend « production » quand rien n'est
			 * déclaré, et c'est l'état par défaut d'une installation neuve. Sur une
			 * préproduction, cette valeur ouvre le `robots.txt`, retire le
			 * `noindex`, et fait poser un an de HSTS. Mesuré en ligne : une
			 * préproduction du parc était dans cet état, et son seul filet était
			 * `blog_public`, une case décochable depuis les réglages de WordPress.
			 *
			 * La sonde ne se fie donc pas à la déclaration : elle la confronte au
			 * domaine, qui est le seul indice disponible quand personne n'a rien
			 * dit.
			 */
			/*
			 * `is_callable` et non `method_exists` : celle-ci répond « oui » pour
			 * une méthode privée, et c'est ce qui a fait tomber le point de santé
			 * en erreur fatale sur un site où `anode-seo` était d'une version
			 * antérieure. Un contrôle de santé est ce qu'on appelle quand quelque
			 * chose va mal — il ne doit jamais être la chose qui casse. Un
			 * composant plus ancien rend donc la sonde muette, pas mortelle.
			 */
			$this->sonde(
				'seo-environnement',
				! is_callable( [ \Anode\Seo\Seo::class, 'host_looks_transient' ] )
					|| ! \Anode\Seo\Seo::host_looks_transient()
					|| 'production' !== wp_get_environment_type(),
				sprintf(
					'le domaine « %s » est un domaine de recette, mais l’environnement vaut « production » : robots.txt ouvert, aucun noindex, HSTS posé pour un an. Déclarer WP_ENVIRONMENT_TYPE dans wp-config.php.',
					(string) wp_parse_url( home_url(), PHP_URL_HOST )
				),
				is_callable( [ \Anode\Seo\Seo::class, 'host_looks_transient' ] )
					? sprintf( 'environnement « %s », cohérent avec le domaine', wp_get_environment_type() )
					: 'non mesuré : anode-seo est d’une version antérieure à 1.3.0'
			),
		];
	}

	/** @return list<array{id: string, ok: bool, detail: string}> */
	private function sondes_redirections(): array {
		$table = \Anode\Redirections\table_path();

		// Relatif au thème : le détail est servi en lecture seule, et
		// l'arborescence du serveur n'a pas à en sortir.
		$court = 'theme/' . basename( dirname( $table ) ) . '/' . basename( $table );

		/*
		 * Pas de table = pas d'ancienne URL à reprendre. C'est le cas d'un site
		 * neuf, et ce n'est pas un défaut. Une table présente mais illisible,
		 * si : les anciennes adresses tomberaient en 404 sans un mot.
		 */
		$existe = file_exists( $table );

		return [
			$this->sonde(
				'redirections-table',
				! $existe || is_readable( $table ),
				sprintf( 'la table %s existe mais n’est pas lisible : les anciennes URL tombent en 404.', $court ),
				$existe ? 'table de redirections lisible' : 'aucune table — site sans ancienne URL à reprendre'
			),
			$this->sonde(
				'redirections-branchees',
				! $existe || (bool) has_action( 'template_redirect' ),
				'la table existe mais aucun template_redirect n’est branché.',
				'redirections branchées'
			),
		];
	}

	/** @return list<array{id: string, ok: bool, detail: string}> */
	private function sondes_updater(): array {
		$liste = dirname( __DIR__, 2 ) . '/mu-plugins/anode-updater/composants.json';
		$mu    = ( defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins' )
			. '/anode-updater/composants.json';
		$etat  = get_option( 'anode_updater_etat', [] );

		return [
			$this->sonde(
				'updater-liste',
				is_readable( $mu ) || is_readable( $liste ),
				'composants.json est introuvable : l’updater ne sait ni où publier ni où chercher.',
				'liste des composants lisible'
			),
			$this->sonde(
				'updater-derniere-verif',
				! is_array( $etat ) || empty( $etat['erreur'] ),
				sprintf(
					'la dernière vérification de mise à jour a échoué : %s',
					is_array( $etat ) ? (string) ( $etat['erreur'] ?? '' ) : ''
				),
				'dernière vérification sans erreur'
			),
		];
	}

	/** @return list<array{id: string, ok: bool, detail: string}> */
	private function sondes_bridge(): array {
		$routes = array_keys( rest_get_server()->get_routes( NAMESPACE_ ) );

		/*
		 * Une route manquante ne se voit qu'au moment où un outil l'appelle, et
		 * l'erreur dit « 404 » — pas « votre pont est à moitié enregistré ».
		 */
		$attendues = [ '/bricks/classes', '/bricks/components', '/bricks/custom-css', '/site', '/design-system' ];
		$absentes  = [];

		foreach ( $attendues as $route ) {
			if ( ! in_array( '/' . NAMESPACE_ . $route, $routes, true ) ) {
				$absentes[] = $route;
			}
		}

		return [
			$this->sonde(
				'bridge-routes',
				! $absentes,
				sprintf( 'route(s) non enregistrées : %s.', implode( ', ', $absentes ) ),
				sprintf( '%d routes enregistrées', count( $routes ) )
			),
			$this->sonde(
				'bridge-bricks',
				Bricks_Adapter::is_available(),
				'Bricks est introuvable : aucune mise en page ne peut être lue ni écrite.',
				'Bricks accessible'
			),
		];
	}

	/**
	 * @return array{id: string, ok: bool, detail: string}
	 */
	private function sonde( string $id, bool $ok, string $echec, string $succes ): array {
		return [
			'id'     => $id,
			'ok'     => $ok,
			'detail' => $ok ? $succes : $echec,
		];
	}

	/**
	 * Erreurs fatales récentes mentionnant le code maison.
	 *
	 * Une fatale dans un mu-plugin ne s'affiche pas en production : le composant
	 * disparaît, et la page continue de se rendre sans lui. C'est exactement le
	 * cas que la sonde « chargé » attrape — celle-ci en donne la cause.
	 *
	 * @return list<string>
	 */
	private function fatales_recentes(): array {
		$journal = WP_CONTENT_DIR . '/debug.log';

		if ( ! is_readable( $journal ) ) {
			return [];
		}

		$taille = (int) filesize( $journal );
		$depuis = max( 0, $taille - 200000 );
		$texte  = (string) file_get_contents( $journal, false, null, $depuis );
		$lignes = [];

		foreach ( explode( "\n", $texte ) as $ligne ) {
			if ( false !== stripos( $ligne, 'fatal error' ) && false !== stripos( $ligne, 'anode' ) ) {
				$lignes[] = trim( $ligne );
			}
		}

		// Les dernières seulement : un journal ancien noierait le diagnostic.
		return array_slice( $lignes, -10 );
	}
}
