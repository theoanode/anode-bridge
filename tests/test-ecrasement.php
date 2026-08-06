<?php
/**
 * Rien ne s'écrase sans qu'on le demande — la mise en page comprise.
 *
 * ## Le trou que ce banc garde fermé
 *
 * Les commandes du dépôt refusent devant un écart depuis le 06/08/2026 (§10 bis) :
 * `apply-pages`, `apply-posts`, `apply-composants`. `bricks_set_page`, non — et
 * c'était la surface la plus exposée, parce que la mise en page est ce qu'un
 * humain retouche le plus : on ouvre Bricks, on déplace un bloc, on corrige un
 * texte. La prochaine écriture l'effaçait sans un mot.
 *
 * Ce n'était pas un oubli d'implémentation : la documentation l'affirmait comme
 * une propriété de l'outil — « `bricks_set_page` écrase la zone visée » —, avec
 * pour seule parade une consigne de lire avant d'écrire. Une consigne demande ;
 * du code empêche.
 *
 * ## Pourquoi la décision se teste hors WordPress
 *
 * `verdict()` et `empreinte()` sont pures. Les éprouver sur un site demanderait
 * une base, un post, un builder — et rendrait le banc si coûteux qu'il ne
 * tournerait pas. Ce sont les trois cas de `verdict()` qui portent la règle ; le
 * reste du pont ne fait que les appliquer.
 *
 * Lancement : php plugin/anode-bridge/tests/test-ecrasement.php
 *
 * @package Anode\Bridge
 */

declare( strict_types = 1 );

/* --- Stubs WordPress minimaux --------------------------------------- */

define( 'ABSPATH', __DIR__ );

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data ) {
		return json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}
}

/*
 * `empreinte_retenue()` lit une méta. Le banc ne l'appelle pas — c'est le seul
 * point du module qui touche la base —, mais la classe doit se charger : PHP
 * n'évalue pas le corps d'une méthode non appelée, un stub serait donc du décor.
 */
if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $id, $cle, $unique = false ) {
		return '';
	}
}

require_once __DIR__ . '/../includes/class-bricks-adapter.php';

use Anode\Bridge\Bricks_Adapter;

/* --- Harnais -------------------------------------------------------- */

$passed = 0;
$failed = 0;

function test( string $nom, callable $cas ): void {
	global $passed, $failed;

	try {
		$cas();
		$passed++;
		echo "  \033[32m✓\033[0m {$nom}\n";
	} catch ( \Throwable $e ) {
		$failed++;
		echo "  \033[31m✗\033[0m {$nom}\n      {$e->getMessage()}\n";
	}
}

function assert_that( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new \RuntimeException( $message );
	}
}

/**
 * Le corps de `refus_ecrasement()`, borné par la méthode suivante.
 *
 * Lire un bloc à la taille au doigt fait mesurer le voisinage : il faut deux
 * bornes réelles, sinon le cas juge autre chose que ce qu'il annonce.
 */
function bloc_du_refus(): string {
	$source = (string) file_get_contents( __DIR__ . '/../includes/class-rest-bricks.php' );
	$i      = strpos( $source, 'function refus_ecrasement' );
	$j      = strpos( $source, 'function get_content' );

	if ( false === $i || false === $j || $j <= $i ) {
		throw new \RuntimeException( 'les bornes du bloc de refus sont introuvables' );
	}

	return substr( $source, $i, $j - $i );
}

/* ------------------------------------------------------------------ */

echo "\n\033[1mLe verdict, avant d'écrire\033[0m\n\n";

test( 'une zone vide sans empreinte s’écrit librement', function (): void {
	// Il n'y a rien à perdre. Refuser ici bloquerait toute première écriture, et
	// une garde qui empêche le travail normal est une garde qu'on désactive.
	$v = Bricks_Adapter::verdict( null, 'peu-importe', true );

	assert_that( $v['ecrire'], 'une zone vierge doit s’écrire' );
	assert_that( 'zone-vierge' === $v['motif'], 'le motif doit être nommé' );
} );

test( 'une zone remplie sans empreinte est refusée', function (): void {
	// C'est le cas qu'on oublie, et le plus dangereux : du contenu existe, nous ne
	// l'avons pas écrit, donc nous ne savons pas qui l'a fait. Écrire serait
	// effacer un travail dont on ignore l'auteur.
	$v = Bricks_Adapter::verdict( null, 'abc', false );

	assert_that( ! $v['ecrire'], 'un contenu de provenance inconnue ne s’écrase pas' );
	assert_that( 'provenance-inconnue' === $v['motif'], 'le motif doit dire pourquoi' );
} );

test( 'une zone inchangée depuis nous s’écrit', function (): void {
	$v = Bricks_Adapter::verdict( 'abc', 'abc', false );

	assert_that( $v['ecrire'], 'personne n’a touché : on écrit' );
	assert_that( 'inchangee-depuis-nous' === $v['motif'], 'le motif doit être nommé' );
} );

test( 'une zone modifiée à la main est refusée', function (): void {
	$v = Bricks_Adapter::verdict( 'abc', 'def', false );

	assert_that( ! $v['ecrire'], 'c’est toute la règle : le travail à la main ne se perd pas' );
	assert_that( 'modifiee-a-la-main' === $v['motif'], 'le motif doit être distinct de la provenance inconnue' );
} );

test( 'une zone vidée à la main est refusée aussi', function (): void {
	// Le piège : `vide` est vrai, mais une empreinte existe — quelqu'un a donc
	// supprimé le contenu. C'est une modification, pas une page neuve. Tester
	// `vide` avant l'empreinte inverserait le verdict, et l'effacement volontaire
	// d'une section serait défait à la prochaine passe.
	$v = Bricks_Adapter::verdict( 'abc', Bricks_Adapter::empreinte( [] ), true );

	assert_that( ! $v['ecrire'], 'vider une zone est une modification' );
	assert_that( 'modifiee-a-la-main' === $v['motif'], 'et elle se nomme comme telle' );
} );

echo "\n\033[1mL'empreinte\033[0m\n\n";

test( 'deux structures identiques ont la même empreinte', function (): void {
	$a = [ [ 'id' => 'x', 'name' => 'section', 'children' => [ 'y' ] ] ];
	$b = [ [ 'id' => 'x', 'name' => 'section', 'children' => [ 'y' ] ] ];

	assert_that( Bricks_Adapter::empreinte( $a ) === Bricks_Adapter::empreinte( $b ), 'même contenu, même empreinte' );
} );

test( 'l’ordre des clés ne change pas l’empreinte', function (): void {
	// Sinon la garde crierait sur une zone que personne n'a touchée — un faux
	// positif qui apprend à passer outre, ce qui est la pire façon de la perdre.
	$a = [ [ 'id' => 'x', 'name' => 'section', 'settings' => [ 'a' => 1, 'b' => 2 ] ] ];
	$b = [ [ 'name' => 'section', 'settings' => [ 'b' => 2, 'a' => 1 ], 'id' => 'x' ] ];

	assert_that( Bricks_Adapter::empreinte( $a ) === Bricks_Adapter::empreinte( $b ), 'l’ordre des clés est sans effet' );
} );

test( 'l’ordre des éléments, lui, change l’empreinte', function (): void {
	// C'est l'ordre des sections d'une page : le changer *est* une modification.
	// Un tri récursif appliqué aux listes aussi aurait effacé cette différence.
	$a = [ [ 'id' => 'x' ], [ 'id' => 'y' ] ];
	$b = [ [ 'id' => 'y' ], [ 'id' => 'x' ] ];

	assert_that( Bricks_Adapter::empreinte( $a ) !== Bricks_Adapter::empreinte( $b ), 'réordonner une page est une modification' );
} );

test( 'un texte modifié change l’empreinte', function (): void {
	$a = [ [ 'id' => 'x', 'settings' => [ 'text' => 'Bonjour' ] ] ];
	$b = [ [ 'id' => 'x', 'settings' => [ 'text' => 'Bonsoir' ] ] ];

	assert_that( Bricks_Adapter::empreinte( $a ) !== Bricks_Adapter::empreinte( $b ), 'un texte corrigé doit être vu' );
} );

test( 'une valeur profondément enfouie change l’empreinte', function (): void {
	// Une correction dans Bricks touche souvent un réglage à quatre niveaux de
	// profondeur. Une empreinte qui ne descendrait pas jusque-là passerait à côté
	// de la modification la plus fréquente.
	$faire = static fn ( string $v ): array => [
		[ 'settings' => [ '_padding' => [ 'top' => [ 'raw' => $v ] ] ] ],
	];

	assert_that(
		Bricks_Adapter::empreinte( $faire( '10px' ) ) !== Bricks_Adapter::empreinte( $faire( '12px' ) ),
		'la comparaison doit être récursive'
	);
} );

echo "\n\033[1mLa garde est posée là où l'on écrit\033[0m\n\n";

test( 'toute écriture retient son empreinte', function (): void {
	$source = (string) file_get_contents( __DIR__ . '/../includes/class-bricks-adapter.php' );

	// Posée dans `set_elements` et non dans la route : sinon un appelant qui écrit
	// sans passer par elle laisserait la garde crier au passage suivant, sur une
	// page que personne n'a touchée.
	$i = strpos( $source, 'function set_elements' );
	$j = strpos( $source, 'function announce_change' );

	assert_that( false !== $i && false !== $j && $j > $i, 'les deux méthodes doivent exister' );

	/*
	 * On cherche **l'appel**, pas le nom de la constante.
	 *
	 * Le premier jet cherchait « META_EMPREINTE » dans la fenêtre : le commentaire
	 * qui explique la mécanique en contient le nom, et le cas passait au vert avec
	 * l'écriture retirée. Il jugeait de la prose. Vu par mutation, pas à la
	 * relecture — un banc qu'on n'a pas vu tomber ne prouve rien.
	 */
	assert_that(
		1 === preg_match(
			'/update_post_meta\(\s*\$post_id,\s*self::META_EMPREINTE/',
			substr( $source, $i, $j - $i )
		),
		'set_elements doit retenir l’empreinte de ce qu’il écrit, sinon la garde n’a rien à comparer'
	);
} );

test( 'les deux routes d’écriture de mise en page interrogent la garde', function (): void {
	$source = (string) file_get_contents( __DIR__ . '/../includes/class-rest-bricks.php' );

	// Une page et un template sont la même chose pour un humain : le même builder,
	// le même travail. Garder l'une et pas l'autre déplace le trou au lieu de le
	// fermer — et un gabarit d'archive corrigé à la main ne se refait pas deux fois.
	$appels = substr_count( $source, 'refus_ecrasement(' );

	assert_that(
		$appels >= 3,
		"la garde doit être définie et appelée par les deux écritures — trouvé {$appels} occurrence(s)"
	);

	assert_that(
		substr_count( $source, "'overwrite' => [" ) + substr_count( $source, "'overwrite'  => [" ) >= 2,
		'les deux routes doivent déclarer overwrite, sinon get_param rend null et la garde ne se lève jamais'
	);
} );

/*
 * Une garde posée sur une seule surface déplace le trou, elle ne le ferme pas.
 *
 * Cinq écritures peuvent défaire un travail fait à la main : une mise en page, un
 * template, une classe globale, une variable, le CSS personnalisé global, et la
 * définition d'un composant. Ce cas refuse qu'une seule soit oubliée — c'est le
 * même raisonnement que « aucun outil d'écriture n'a été oublié » côté commandes,
 * transposé aux routes.
 */
test( 'les cinq surfaces d’écriture interrogent toutes la garde', function (): void {
	$source = (string) file_get_contents( __DIR__ . '/../includes/class-rest-bricks.php' );

	$attendus = [
		'mise en page et template' => '$this->refus_ecrasement(',
		'classes globales'         => '$this->refus_classes(',
		'variables'                => '$this->refus_variables(',
		'CSS personnalisé global'  => "Empreintes::refus( [ 'CSS personnalisé global' ]",
		'composants'              => 'Empreintes::refus( [ $label ]',
	];

	$oubliees = [];

	foreach ( $attendus as $quoi => $motif ) {
		if ( false === strpos( $source, $motif ) ) {
			$oubliees[] = $quoi;
		}
	}

	assert_that(
		! $oubliees,
		'surface(s) sans garde : ' . implode( ', ', $oubliees ) . ' — une seule suffit à perdre un travail'
	);

	/*
	 * `ds_apply` écrit les variables par **sa propre route**. La garde de
	 * `/bricks/variables` ne la couvre donc pas — et c'est le chemin normal, celui
	 * qu'on emploie tous les jours. Une garde qu'un chemin contourne est une garde
	 * absente pour ce chemin.
	 */
	$ds = (string) file_get_contents( __DIR__ . '/../includes/class-rest-design-system.php' );

	assert_that(
		false !== strpos( $ds, 'Empreintes::refus(' ),
		'ds_apply doit refuser d’écraser une variable retouchée : il n’emprunte pas la route gardée'
	);
	assert_that(
		false !== strpos( $ds, 'Empreintes::retenir(' ),
		'et retenir ce qu’il écrit, sinon il refusera perpétuellement ce qu’il vient de poser'
	);

} );

test( 'chaque écriture gardée retient aussi son empreinte', function (): void {
	// Refuser sans retenir produit un refus perpétuel : la garde crie au passage
	// suivant sur ce qu'on vient d'écrire soi-même. C'est la même erreur qu'une
	// garde absente, vue de l'utilisateur — sauf qu'elle apprend à passer outre.
	$source = (string) file_get_contents( __DIR__ . '/../includes/class-rest-bricks.php' );

	assert_that( substr_count( $source, 'Empreintes::retenir(' ) >= 4, 'les surfaces globales doivent retenir' );

	$adaptateur = (string) file_get_contents( __DIR__ . '/../includes/class-bricks-adapter.php' );

	assert_that(
		1 === preg_match( '/update_post_meta\(\s*\$post_id,\s*self::META_EMPREINTE/', $adaptateur ),
		'la mise en page retient la sienne dans une méta du post'
	);
} );

test( 'le magasin d’empreintes n’a qu’une copie du verdict', function (): void {
	// Deux copies de la même décision divergent au premier correctif — c'est ce qui
	// est arrivé au reset de cohabitation, corrigé dans une feuille et resté fautif
	// dans le CSS global, où c'est lui qui agissait.
	$magasin = (string) file_get_contents( __DIR__ . '/../includes/class-empreintes.php' );

	assert_that(
		false !== strpos( $magasin, 'Bricks_Adapter::verdict(' ),
		'le magasin doit appeler le verdict, pas le réimplémenter'
	);
	assert_that(
		false === strpos( $magasin, "'provenance-inconnue' =>" ),
		'aucune seconde implémentation du verdict dans le magasin'
	);
} );

test( 'le refus répond 409, et non 403', function (): void {
	$bloc = bloc_du_refus();

	// Il ne manque aucun droit : il y a un désaccord d'état. Un 403 enverrait
	// chercher une permission, c'est-à-dire le seul endroit où il n'y a rien à
	// corriger — le travers déjà payé avec le CDN qui filtrait les PUT.
	//
	// Le bloc se **borne**, il ne se devine pas : un premier jet prenait 3000
	// octets à partir du début de la méthode, débordait sur `get_content()`, et
	// tombait sur le 403 parfaitement légitime qu'elle porte. Une fenêtre au doigt
	// mesure autre chose que ce qu'on croit.
	assert_that( false !== strpos( $bloc, "'status'             => 409" ), 'le refus doit être un 409' );
	assert_that( false === strpos( $bloc, '403' ), 'un 403 enverrait chercher une permission' );
} );

test( 'le refus nomme ses sorties', function (): void {
	$bloc = bloc_du_refus();

	// Un refus qui ne dit pas comment avancer se contourne au hasard.
	assert_that( false !== strpos( $bloc, 'Rien n’a été écrit' ), 'dire ce qui n’a pas eu lieu' );
	assert_that( false !== strpos( $bloc, 'overwrite: true' ), 'nommer la sortie explicite' );
	assert_that( false !== strpos( $bloc, 'bricks_get_page' ), 'nommer la façon de lire l’état réel' );
} );

echo "\n{$passed} test(s) réussi(s), {$failed} échec(s).\n";
exit( $failed ? 1 : 0 );
