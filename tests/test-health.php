<?php
/**
 * Tests des sondes de santé, sans WordPress.
 *
 * Ne couvre que la règle de fraîcheur, parce que c'est la seule des sondes de
 * l'updater qui décide de quelque chose que les autres ne voient pas.
 *
 * Les trois sondes précédentes relisent le **même état stocké** — sans erreur,
 * dépôts joignables — et aucune ne le datait. Un site dont le planificateur a
 * cessé de tourner rendait donc trois voyants verts et « canal ouvert » sur un
 * canal qui ne recevait plus rien : sur un parc qui exige une signature, les
 * correctifs de sécurité s'arrêtent en silence.
 *
 * Lancement : php plugin/anode-bridge/tests/test-health.php
 *
 * @package Anode\Bridge
 */

declare( strict_types = 1 );

define( 'ABSPATH', __DIR__ );

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

/*
 * La classe déclare ses routes à l'exécution, jamais au chargement : les
 * doublures ci-dessous suffisent à la parser, et la règle testée n'en appelle
 * aucune.
 */
foreach ( [ 'register_rest_route', 'rest_ensure_response', 'get_option', 'add_action', 'rest_get_server' ] as $stub ) {
	if ( ! function_exists( $stub ) ) {
		eval( "function {$stub}() { return null; }" ); // phpcs:ignore Squiz.PHP.Eval.Discouraged
	}
}

if ( ! defined( 'Anode\Bridge\NAMESPACE_' ) ) {
	define( 'Anode\Bridge\NAMESPACE_', 'anode/v1' );
}

require_once __DIR__ . '/../includes/class-rest-health.php';

use Anode\Bridge\Rest_Health;

/* --- Micro-harnais -------------------------------------------------- */

$passed = 0;
$failed = 0;

/** @param callable(): void $fn */
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

function assert_null( $value, string $message ): void {
	assert_true( null === $value, $message . ' — obtenu : ' . var_export( $value, true ) );
}

/* --- Fraîcheur de la dernière passe --------------------------------- */

echo "\nFraîcheur de la vérification de mise à jour\n";

$maintenant = strtotime( '2026-07-31T22:00:00+00:00' );

test( 'une passe du jour ne signale rien', function () use ( $maintenant ): void {
	assert_null( Rest_Health::verification_perimee( '2026-07-31T06:00:00+00:00', $maintenant ), 'passe du matin' );
} );

test( 'la veille et l’avant-veille restent dans l’aléa d’ordonnancement', function () use ( $maintenant ): void {
	// La passe est quotidienne : un rendez-vous manqué peut n'être qu'un décalage
	// d'horaire. Crier dès le premier jour ferait un voyant rouge de routine,
	// qu'on cesserait de lire — et c'est ainsi qu'on rate le vrai.
	assert_null( Rest_Health::verification_perimee( '2026-07-30T06:00:00+00:00', $maintenant ), 'la veille' );
	assert_null( Rest_Health::verification_perimee( '2026-07-29T06:00:00+00:00', $maintenant ), 'l’avant-veille' );
} );

test( 'trois jours de silence sont signalés', function () use ( $maintenant ): void {
	$motif = Rest_Health::verification_perimee( '2026-07-28T06:00:00+00:00', $maintenant );

	assert_true( null !== $motif, 'trois jours doivent être refusés' );
	assert_true( str_contains( (string) $motif, '3 jour' ), "le motif doit dire depuis combien de temps : {$motif}" );
} );

test( 'le motif nomme la cause la plus fréquente, pas seulement le symptôme', function () use ( $maintenant ): void {
	// Un « ça ne tourne plus » sans piste renvoie à une demi-heure de recherche.
	// La cause mesurée sur la préproduction du parc est en tête de liste.
	$motif = (string) Rest_Health::verification_perimee( '2026-06-01T06:00:00+00:00', $maintenant );

	assert_true( str_contains( $motif, 'wp-cron.php' ), "le motif doit nommer wp-cron.php : {$motif}" );
} );

test( 'un mois de silence compte les jours, il ne sature pas', function () use ( $maintenant ): void {
	$motif = (string) Rest_Health::verification_perimee( '2026-06-30T22:00:00+00:00', $maintenant );

	assert_true( str_contains( $motif, '31 jour' ), "31 jours attendus : {$motif}" );
} );

test( 'un état sans date ne rend pas un second rouge pour le même fait', function () use ( $maintenant ): void {
	// « rien n'a jamais tourné » est déjà le domaine de `sonde_jeton`. Deux
	// rouges pour un seul fait feraient chercher deux causes.
	assert_null( Rest_Health::verification_perimee( null, $maintenant ), 'aucune date' );
	assert_null( Rest_Health::verification_perimee( '', $maintenant ), 'date vide' );
	assert_null( Rest_Health::verification_perimee( '   ', $maintenant ), 'date blanche' );
	assert_null( Rest_Health::verification_perimee( [ 'verifie_le' => 'x' ], $maintenant ), 'état mal formé' );
} );

test( 'une date illisible est nommée, pas prise pour une date récente', function () use ( $maintenant ): void {
	// `strtotime` rend false, qui vaut 0 une fois converti en entier : sans ce
	// cas, une date abîmée passerait pour le 1ᵉʳ janvier 1970 — donc périmée de
	// vingt mille jours, avec un motif qui ne dirait pas pourquoi.
	$motif = Rest_Health::verification_perimee( 'jamais', $maintenant );

	assert_true( null !== $motif, 'une date illisible doit être signalée' );
	assert_true( str_contains( (string) $motif, 'illisible' ), "le motif doit dire « illisible » : {$motif}" );
} );

test( 'une date dans le futur ne déclenche rien', function () use ( $maintenant ): void {
	// Décalage d'horloge entre le serveur et l'état stocké : un écart négatif
	// n'est pas un silence.
	assert_null( Rest_Health::verification_perimee( '2026-08-05T06:00:00+00:00', $maintenant ), 'date future' );
} );

echo "\n{$passed} test(s) réussi(s), {$failed} échec(s).\n\n";

exit( $failed ? 1 : 0 );
