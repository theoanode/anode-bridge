<?php
/**
 * Tests du validateur, sans WordPress.
 *
 * Le validateur est la pièce la plus critique du pont : c'est lui qui empêche
 * d'écrire une structure Bricks corrompue en base, et qui bloque l'exécution
 * de PHP arbitraire. Il doit donc être testable sans installer WordPress.
 *
 * Lancement : php plugin/anode-bridge/tests/test-validator.php
 *
 * @package Anode\Bridge
 */

declare( strict_types = 1 );

/* --- Stubs WordPress minimaux --------------------------------------- */

define( 'ABSPATH', __DIR__ );

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct(
			private string $code = '',
			private string $message = '',
			private array $data = []
		) {}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}

		public function get_error_data(): array {
			return $this->data;
		}
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( string $value ): string {
		return trim( strip_tags( $value ) );
	}
}

/*
 * Doublures des rares fonctions dont la classe REST a besoin pour être
 * chargée. On ne teste ici que sa règle d'exposition des types de contenu :
 * le reste demande WordPress.
 */
if ( ! class_exists( 'WP_Post_Type' ) ) {
	class WP_Post_Type {
		public function __construct( public string $name = '', public $show_in_rest = false ) {}
	}
}

$GLOBALS['anode_types'] = [];

if ( ! function_exists( 'get_post_type_object' ) ) {
	function get_post_type_object( string $type ) {
		return $GLOBALS['anode_types'][ $type ] ?? null;
	}
}

foreach ( [ 'register_rest_route', 'sanitize_key', 'rest_ensure_response', 'get_permalink', 'get_post_meta', 'get_option', 'get_posts', 'add_action', 'add_filter', 'esc_url_raw', 'wp_parse_url' ] as $stub ) {
	if ( ! function_exists( $stub ) ) {
		eval( "function {$stub}() { return null; }" ); // phpcs:ignore Squiz.PHP.Eval.Discouraged
	}
}

require_once __DIR__ . '/../includes/class-bricks-adapter.php';
require_once __DIR__ . '/../includes/class-validator.php';
require_once __DIR__ . '/../includes/class-rest-content.php';

use Anode\Bridge\Validator;

/* --- Micro-harnais -------------------------------------------------- */

$passed = 0;
$failed = 0;

/**
 * @param callable(): void $fn
 */
function test( string $name, callable $fn ): void {
	global $passed, $failed;

	try {
		$fn();
		++$passed;
		echo "  ✓ {$name}\n";
	} catch ( Throwable $error ) {
		++$failed;
		echo "  ✗ {$name}\n    {$error->getMessage()}\n";
	}
}

function assert_true( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function assert_error( mixed $value, string $needle, string $message ): void {
	assert_true( $value instanceof WP_Error, $message . ' (aucune erreur renvoyée)' );
	assert_true(
		str_contains( $value->get_error_message(), $needle ),
		$message . " (message inattendu : « {$value->get_error_message()} »)"
	);
}

/* --- Structure d'éléments ------------------------------------------- */

echo "\nValidation de structure Bricks\n";

test(
	'une structure valide est acceptée et normalisée',
	function (): void {
		$result = Validator::elements(
			[
				[ 'id' => 'aaa111', 'name' => 'section', 'parent' => 0, 'children' => [ 'bbb222' ], 'settings' => [] ],
				[ 'id' => 'bbb222', 'name' => 'heading', 'parent' => 'aaa111', 'children' => [], 'settings' => [ 'text' => 'Bonjour' ] ],
			]
		);

		assert_true( is_array( $result ), 'tableau attendu' );
		assert_true( count( $result ) === 2, 'deux éléments attendus' );
		assert_true( 0 === $result[0]['parent'], 'la racine a pour parent l’entier 0' );
		assert_true( 'Bonjour' === $result[1]['settings']['text'], 'les réglages sont conservés' );
	}
);

test(
	'une structure vide est acceptée (page vidée)',
	function (): void {
		assert_true( [] === Validator::elements( [] ), 'un tableau vide doit être accepté' );
	}
);

test(
	'un parent inexistant est refusé',
	function (): void {
		$result = Validator::elements(
			[
				[ 'id' => 'aaa111', 'name' => 'section', 'parent' => 0, 'children' => [] ],
				[ 'id' => 'bbb222', 'name' => 'heading', 'parent' => 'zzz999', 'children' => [] ],
			]
		);

		assert_error( $result, 'n’existe pas', 'un parent orphelin doit être refusé' );
	}
);

test(
	'un enfant inexistant est refusé',
	function (): void {
		$result = Validator::elements(
			[ [ 'id' => 'aaa111', 'name' => 'section', 'parent' => 0, 'children' => [ 'fantome' ] ] ]
		);

		assert_error( $result, 'n’existe pas', 'un enfant fantôme doit être refusé' );
	}
);

test(
	'une relation parent/enfant incohérente est refusée',
	function (): void {
		$result = Validator::elements(
			[
				[ 'id' => 'aaa111', 'name' => 'section', 'parent' => 0, 'children' => [ 'bbb222' ] ],
				[ 'id' => 'bbb222', 'name' => 'heading', 'parent' => 0, 'children' => [] ],
			]
		);

		assert_error( $result, 'Incohérence', 'un enfant dont le parent ne correspond pas doit être refusé' );
	}
);

test(
	'un cycle de parenté est détecté',
	function (): void {
		$result = Validator::elements(
			[
				[ 'id' => 'aaa111', 'name' => 'section', 'parent' => 'bbb222', 'children' => [ 'bbb222' ] ],
				[ 'id' => 'bbb222', 'name' => 'block', 'parent' => 'aaa111', 'children' => [ 'aaa111' ] ],
			]
		);

		assert_true( $result instanceof WP_Error, 'un cycle doit être refusé' );
	}
);

test(
	'une structure sans racine est refusée',
	function (): void {
		$result = Validator::elements(
			[ [ 'id' => 'aaa111', 'name' => 'section', 'parent' => 'aaa111', 'children' => [] ] ]
		);

		assert_true( $result instanceof WP_Error, 'une structure sans racine doit être refusée' );
	}
);

test(
	'un identifiant dupliqué est refusé',
	function (): void {
		$result = Validator::elements(
			[
				[ 'id' => 'aaa111', 'name' => 'section', 'parent' => 0, 'children' => [] ],
				[ 'id' => 'aaa111', 'name' => 'block', 'parent' => 0, 'children' => [] ],
			]
		);

		assert_error( $result, 'dupliqué', 'les identifiants doivent être uniques' );
	}
);

test(
	'un identifiant ou un nom invalide est refusé',
	function (): void {
		assert_error(
			Validator::elements( [ [ 'id' => 'a', 'name' => 'section', 'parent' => 0 ] ] ),
			'invalide',
			'un identifiant trop court doit être refusé'
		);

		assert_error(
			Validator::elements( [ [ 'id' => 'aaa111', 'name' => '<script>', 'parent' => 0 ] ] ),
			'invalide',
			'un nom d’élément non alphanumérique doit être refusé'
		);
	}
);

echo "\nSécurité\n";

test(
	'un élément code exécutant du PHP est refusé',
	function (): void {
		$result = Validator::elements(
			[
				[
					'id'       => 'aaa111',
					'name'     => 'code',
					'parent'   => 0,
					'children' => [],
					'settings' => [ 'executeCode' => true, 'code' => 'system("rm -rf /");' ],
				],
			]
		);

		assert_error( $result, 'interdite', 'executeCode doit être bloqué' );
		assert_true( 403 === ( $result->get_error_data()['status'] ?? 0 ), 'le statut doit être 403' );
	}
);

test(
	'une signature de code fournie par l’API est retirée',
	function (): void {
		$result = Validator::elements(
			[
				[
					'id'       => 'aaa111',
					'name'     => 'code',
					'parent'   => 0,
					'children' => [],
					'settings' => [ 'code' => '<p>Bonjour</p>', 'signature' => 'contrefaçon' ],
				],
			]
		);

		assert_true( is_array( $result ), 'un bloc de code sans exécution reste autorisé' );
		assert_true( ! isset( $result[0]['settings']['signature'] ), 'la signature ne doit jamais être propagée' );
		assert_true( '<p>Bonjour</p>' === $result[0]['settings']['code'], 'le code non exécuté est conservé' );
	}
);

/**
 * Construit un élément « html » portant le balisage donné.
 *
 * @return array<int, array<string, mixed>>
 */
function html_element( string $markup ): array {
	return [
		[
			'id'       => 'aaa111',
			'name'     => 'html',
			'parent'   => 0,
			'children' => [],
			'settings' => [ 'html' => $markup ],
		],
	];
}

test(
	'un SVG en ligne est accepté dans un élément html',
	function (): void {
		$svg    = '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M4 6l6 4 6-4" stroke="currentColor"/></svg>';
		$result = Validator::elements( html_element( $svg ) );

		assert_true( is_array( $result ), 'un SVG décoratif doit passer' );
		assert_true( $svg === $result[0]['settings']['html'], 'le balisage est conservé tel quel' );
	}
);

test(
	'un élément html contenant du script est refusé',
	function (): void {
		$result = Validator::elements( html_element( '<div><script>alert(1)</script></div>' ) );

		assert_error( $result, 'script', 'une balise script doit être refusée' );
		assert_true( 403 === ( $result->get_error_data()['status'] ?? 0 ), 'le statut doit être 403' );
	}
);

test(
	'un gestionnaire d’événement en attribut est refusé',
	function (): void {
		$result = Validator::elements( html_element( '<svg onload="alert(1)"></svg>' ) );

		assert_error( $result, 'gestionnaires', 'un attribut on… doit être refusé' );
	}
);

test(
	'une URL javascript: est refusée',
	function (): void {
		$result = Validator::elements( html_element( '<a href="javascript:alert(1)">Lien</a>' ) );

		assert_error( $result, 'javascript', 'une URL javascript: doit être refusée' );
	}
);

test(
	'une ressource distante est refusée dans un élément html',
	function (): void {
		$result = Validator::elements( html_element( '<img src="https://cdn.exemple.com/pixel.gif" alt="">' ) );

		assert_error( $result, 'zéro dépendance', 'une ressource distante doit être refusée' );
	}
);

test(
	'une iframe est refusée dans un élément html',
	function (): void {
		$result = Validator::elements( html_element( '<iframe src="/interne"></iframe>' ) );

		assert_error( $result, 'iframe', 'une iframe doit être refusée, même locale' );
	}
);

test(
	'du PHP est refusé dans un élément html',
	function (): void {
		$result = Validator::elements( html_element( '<?php system("id"); ?>' ) );

		assert_error( $result, 'PHP', 'du PHP doit être refusé' );
	}
);

test(
	'une signature imbriquée dans une requête est retirée',
	function (): void {
		$result = Validator::elements(
			[
				[
					'id'       => 'aaa111',
					'name'     => 'div',
					'parent'   => 0,
					'children' => [],
					'settings' => [ 'query' => [ 'objectType' => 'post', 'signature' => 'contrefaçon' ] ],
				],
			]
		);

		assert_true( is_array( $result ), 'structure attendue' );
		assert_true( ! isset( $result[0]['settings']['query']['signature'] ), 'la signature de requête doit être retirée' );
	}
);

/* --- Classes globales ----------------------------------------------- */

echo "\nTypes de contenu exposés\n";

test(
	'un type déclaré show_in_rest est servi',
	function (): void {
		$GLOBALS['anode_types']['evenement'] = new \WP_Post_Type( 'evenement', true );

		$resultat = \Anode\Bridge\Rest_Content::readable_post_type( 'evenement' );

		assert_true( $resultat instanceof \WP_Post_Type, 'un type éditorial doit passer' );
	}
);

test(
	'un type privé est refusé, pas servi à moitié',
	function (): void {
		// Le cas réel : les demandes reçues par formulaire, dont le titre
		// portait l'adresse du demandeur. Un compte d'édition les listait.
		$GLOBALS['anode_types']['anode_soumission'] = new \WP_Post_Type( 'anode_soumission', false );

		$resultat = \Anode\Bridge\Rest_Content::readable_post_type( 'anode_soumission' );

		assert_error( $resultat, 'n’est pas exposé', 'un type privé doit être refusé' );
		assert_true( 403 === ( $resultat->get_error_data()['status'] ?? 0 ), 'le statut doit être 403' );
	}
);

test(
	'un type inconnu répond 404',
	function (): void {
		$resultat = \Anode\Bridge\Rest_Content::readable_post_type( 'type_qui_nexiste_pas' );

		assert_error( $resultat, 'inconnu', 'un type inconnu doit être signalé comme tel' );
		assert_true( 404 === ( $resultat->get_error_data()['status'] ?? 0 ), 'le statut doit être 404' );
	}
);

echo "\nClasses globales BEM\n";

test(
	'les noms BEM canoniques sont acceptés',
	function (): void {
		$result = Validator::global_classes(
			[
				[ 'name' => 'c-hero' ],
				[ 'name' => 'c-hero__title' ],
				[ 'name' => 'c-hero--dark' ],
				[ 'name' => 'c-hero__title--large' ],
				[ 'name' => 'l-grid-3' ],
				[ 'name' => 'u-visually-hidden' ],
			]
		);

		assert_true( is_array( $result ), 'les six formes canoniques doivent être acceptées' );
		assert_true( count( $result ) === 6, 'six classes attendues' );

		foreach ( $result as $class ) {
			assert_true( isset( $class['id'] ) && strlen( $class['id'] ) >= 3, 'un identifiant est généré' );
		}
	}
);

test(
	'les noms non conformes BEM sont refusés',
	function (): void {
		foreach ( [ 'C-Hero', 'c hero', 'c-hero__a__b', 'c-hero--a--b', '1-bloc' ] as $name ) {
			assert_error(
				Validator::global_classes( [ [ 'name' => $name ] ] ),
				'BEM',
				"« {$name} » aurait dû être refusé"
			);
		}
	}
);

test(
	'le mode non strict accepte les classes héritées',
	function (): void {
		$result = Validator::global_classes( [ [ 'name' => 'legacyClass' ] ], false );

		assert_true( is_array( $result ), 'le mode non strict doit accepter les noms hérités' );
	}
);

test(
	'une classe dupliquée est refusée',
	function (): void {
		assert_error(
			Validator::global_classes( [ [ 'name' => 'c-hero' ], [ 'name' => 'c-hero' ] ] ),
			'dupliquée',
			'les doublons doivent être refusés'
		);
	}
);

/* --- Variables ------------------------------------------------------ */

echo "\nVariables globales\n";

test(
	'les noms de variables en kebab-case sont acceptés',
	function (): void {
		$result = Validator::variables(
			[
				[ 'name' => 'color-primary', 'value' => '#2f6df6' ],
				[ 'name' => 'space-l', 'value' => '2rem' ],
			]
		);

		assert_true( is_array( $result ) && count( $result ) === 2, 'deux variables attendues' );
		assert_true( '#2f6df6' === $result[0]['value'], 'la valeur est conservée' );
	}
);

test(
	'un nom de variable invalide est refusé',
	function (): void {
		foreach ( [ '--color-primary', 'Color-Primary', 'color_primary', '1-color' ] as $name ) {
			assert_error(
				Validator::variables( [ [ 'name' => $name, 'value' => '#fff' ] ] ),
				'invalide',
				"« {$name} » aurait dû être refusé"
			);
		}
	}
);

test(
	'une variable dupliquée est refusée',
	function (): void {
		assert_error(
			Validator::variables(
				[
					[ 'name' => 'color-primary', 'value' => '#fff' ],
					[ 'name' => 'color-primary', 'value' => '#000' ],
				]
			),
			'dupliquée',
			'les doublons doivent être refusés'
		);
	}
);

test(
	'une valeur numérique est convertie en chaîne',
	function (): void {
		$result = Validator::variables( [ [ 'name' => 'line-height-base', 'value' => 1.5 ] ] );

		assert_true( is_array( $result ), 'structure attendue' );
		assert_true( '1.5' === $result[0]['value'], 'la valeur doit être convertie en chaîne' );
	}
);

echo "\n{$passed} test(s) réussi(s), {$failed} échec(s).\n";

exit( $failed > 0 ? 1 : 0 );
