<?php
/**
 * Tests de l'aplatissement des tokens du design system, sans WordPress.
 *
 * Ces tests existent à cause d'un bug réel : json_decode convertit les clés
 * numériques d'un objet JSON (« 600 ») en entiers PHP. Un filtre `is_string()`
 * sur la clé écartait donc silencieusement toute l'échelle de couleurs — les
 * tokens `color.brand.600` et consorts n'étaient jamais produits, et les
 * références vers eux ne se résolvaient plus.
 *
 * Lancement : php plugin/anode-bridge/tests/test-design-system.php
 *
 * @package Anode\Bridge
 */

declare( strict_types = 1 );

define( 'ABSPATH', __DIR__ );

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct(
			private string $code = '',
			private string $message = '',
			private array $data = []
		) {}

		public function get_error_message(): string {
			return $this->message;
		}
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( string $value ): string {
		return trim( strip_tags( $value ) );
	}
}

require_once __DIR__ . '/../includes/class-bricks-adapter.php';
require_once __DIR__ . '/../includes/class-validator.php';
require_once __DIR__ . '/../includes/class-rest-design-system.php';

use Anode\Bridge\Rest_Design_System;

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

/**
 * Appelle la méthode privée d'aplatissement.
 *
 * @param array<string, mixed> $tokens Tokens bruts.
 *
 * @return array<int, array<string, string>>|WP_Error
 */
function flatten( array $tokens ): array|WP_Error {
	// Depuis PHP 8.1, la réflexion accède aux méthodes privées sans
	// setAccessible() — déprécié depuis.
	return ( new ReflectionMethod( Rest_Design_System::class, 'flatten_tokens' ) )
		->invoke( new Rest_Design_System(), $tokens );
}

/**
 * @param array<int, array<string, string>> $flat Tokens aplatis.
 *
 * @return array<string, string>
 */
function by_name( array $flat ): array {
	return array_column( $flat, 'value', 'name' );
}

echo "\nAplatissement des tokens\n";

test(
	'les clés numériques d’une échelle sont conservées',
	function (): void {
		// json_decode en tableau associatif : « 600 » devient l'entier 600.
		$tokens = json_decode( '{"color":{"brand":{"50":"#eef4ff","500":"#2f63f6","600":"#1c46db"}}}', true );
		$flat   = flatten( $tokens );

		assert_true( is_array( $flat ), 'aplatissement échoué' );

		$values = by_name( $flat );

		assert_true( isset( $values['color-brand-50'] ), 'color-brand-50 absent — les clés numériques sont ignorées' );
		assert_true( isset( $values['color-brand-500'] ), 'color-brand-500 absent' );
		assert_true( '#1c46db' === ( $values['color-brand-600'] ?? '' ), 'color-brand-600 absent ou incorrect' );
		assert_true( count( $flat ) === 3, 'trois tokens attendus, ' . count( $flat ) . ' obtenus' );
	}
);

test(
	'une référence vers un token à clé numérique se résout',
	function (): void {
		$tokens = json_decode(
			'{"color":{"brand":{"600":"#1c46db"},"feedback":{"info":"{color.brand.600}"}}}',
			true
		);

		$flat = flatten( $tokens );

		assert_true( is_array( $flat ), $flat instanceof WP_Error ? $flat->get_error_message() : 'aplatissement échoué' );

		$values = by_name( $flat );

		assert_true(
			'#1c46db' === ( $values['color-feedback-info'] ?? '' ),
			'référence non résolue : ' . ( $values['color-feedback-info'] ?? 'absente' )
		);
	}
);

test(
	'les références en chaîne se résolvent de proche en proche',
	function (): void {
		$tokens = json_decode(
			'{"color":{"neutral":{"900":"#161a21"},"text":{"strong":"{color.neutral.900}"},'
			. '"heading":{"base":"{color.text.strong}"}}}',
			true
		);

		$values = by_name( flatten( $tokens ) );

		assert_true( '#161a21' === ( $values['color-heading-base'] ?? '' ), 'résolution en chaîne échouée' );
	}
);

test(
	'les métadonnées « $… » ne deviennent pas des tokens',
	function (): void {
		$tokens = json_decode( '{"$name":"Charte","$version":"1.0.0","space":{"s":"1rem"}}', true );
		$flat   = flatten( $tokens );

		assert_true( count( $flat ) === 1, 'seul « space-s » devait être produit' );
		assert_true( 'space-s' === $flat[0]['name'], 'nom inattendu : ' . $flat[0]['name'] );
	}
);

test(
	'la convention { "$value": … } termine une branche',
	function (): void {
		$tokens = json_decode( '{"space":{"s":{"$value":"1rem","$type":"dimension"}}}', true );
		$values = by_name( flatten( $tokens ) );

		assert_true( '1rem' === ( $values['space-s'] ?? '' ), 'la valeur $value n’a pas été lue' );
	}
);

test(
	'une référence circulaire est refusée',
	function (): void {
		$tokens = json_decode( '{"a":{"one":"{a.two}"},"b":{"two":"{a.one}"}}', true );
		$result = flatten( $tokens );

		assert_true( $result instanceof WP_Error, 'une boucle de références aurait dû être refusée' );
	}
);

test(
	'une référence vers un token inexistant est refusée avec son nom',
	function (): void {
		$tokens = json_decode( '{"color":{"text":"{color.fantome}"}}', true );
		$result = flatten( $tokens );

		assert_true( $result instanceof WP_Error, 'une référence morte aurait dû être refusée' );
		assert_true(
			str_contains( $result->get_error_message(), 'color-text' ),
			'le message doit nommer le token fautif : ' . $result->get_error_message()
		);
	}
);

test(
	'le groupe de premier niveau est conservé pour la catégorisation',
	function (): void {
		$tokens = json_decode( '{"color":{"brand":{"500":"#2f63f6"}},"space":{"s":"1rem"}}', true );
		$flat   = flatten( $tokens );

		$groups = array_column( $flat, 'group', 'name' );

		assert_true( 'color' === $groups['color-brand-500'], 'groupe attendu « color »' );
		assert_true( 'space' === $groups['space-s'], 'groupe attendu « space »' );
	}
);

test(
	'les valeurs numériques et booléennes sont converties en chaînes',
	function (): void {
		$tokens = json_decode( '{"font":{"weight":{"bold":700},"variable":true}}', true );
		$values = by_name( flatten( $tokens ) );

		assert_true( '700' === ( $values['font-weight-bold'] ?? '' ), 'valeur numérique non convertie' );
		assert_true( 'true' === ( $values['font-variable'] ?? '' ), 'valeur booléenne non convertie' );
	}
);

test(
	'le fichier de tokens du blueprint s’aplatit intégralement',
	function (): void {
		$path = __DIR__ . '/../../../blueprint/design-system/tokens.json';

		if ( ! is_readable( $path ) ) {
			throw new RuntimeException( "tokens.json introuvable : {$path}" );
		}

		$flat = flatten( json_decode( (string) file_get_contents( $path ), true ) );

		assert_true(
			is_array( $flat ),
			$flat instanceof WP_Error ? $flat->get_error_message() : 'aplatissement échoué'
		);

		assert_true( count( $flat ) > 100, 'au moins 100 tokens attendus, ' . count( $flat ) . ' obtenus' );

		// Aucune référence ne doit subsister après résolution.
		foreach ( $flat as $token ) {
			assert_true(
				! str_contains( $token['value'], '{' ),
				"référence non résolue sur « {$token['name']} » : {$token['value']}"
			);
		}
	}
);

echo "\n{$passed} test(s) réussi(s), {$failed} échec(s).\n";

exit( $failed > 0 ? 1 : 0 );
