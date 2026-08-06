<?php
/**
 * Ce que nous avons écrit, pour les surfaces qui ne sont pas des posts.
 *
 * ## Pourquoi une seconde mécanique
 *
 * L'empreinte d'une mise en page vit dans une méta du post — c'est le bon endroit,
 * elle disparaît avec lui. Les classes globales, les variables, le CSS
 * personnalisé et les composants ne sont pas des posts : ils vivent dans des
 * options. Il leur faut donc un magasin, et une clé par chose gardée.
 *
 * Le **verdict**, lui, ne change pas : il est écrit une fois dans
 * `Bricks_Adapter::verdict()`, éprouvé par mutation, et appelé d'ici. Deux copies
 * de la même décision divergeraient au premier correctif — c'est exactement ce qui
 * est arrivé au reset de cohabitation, corrigé dans une feuille et resté fautif
 * dans le CSS global.
 *
 * ## Ce que la granularité change
 *
 * Une classe se garde **une par une**. `bricks_upsert_classes` remplace les
 * réglages d'une classe sans les fusionner : envoyer une classe avec un seul
 * réglage efface tous les autres, et l'appel répond « 1 mise(s) à jour ». Une
 * empreinte par classe permet de refuser **cette** classe-là sans bloquer les
 * quarante autres d'une même passe — sinon la garde empêche le travail normal, et
 * une garde qui empêche le travail normal est une garde qu'on désactive.
 *
 * Les variables et le CSS global, eux, se gardent en bloc : ils sont écrits en
 * bloc, et une source de vérité unique les produit.
 *
 * @package Anode\Bridge
 */

declare( strict_types = 1 );

namespace Anode\Bridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Empreintes {

	/**
	 * L'option qui les porte toutes.
	 *
	 * `get_site_option` plutôt que `get_option` : sur une installation multisite,
	 * les options Bricks gardées ici sont celles du réseau.
	 */
	public const OPTION = 'anode_bridge_empreintes';

	/** @var array<string, string>|null */
	private static ?array $cache = null;

	/** @return array<string, string> */
	private static function toutes(): array {
		if ( null === self::$cache ) {
			$brutes      = get_site_option( self::OPTION, [] );
			self::$cache = is_array( $brutes ) ? array_filter( $brutes, 'is_string' ) : [];
		}

		return self::$cache;
	}

	/** L'empreinte retenue pour une clé, ou null si nous n'y avons jamais écrit. */
	public static function retenue( string $cle ): ?string {
		$toutes = self::toutes();

		return isset( $toutes[ $cle ] ) && '' !== $toutes[ $cle ] ? $toutes[ $cle ] : null;
	}

	/**
	 * Retient ce qu'on vient d'écrire.
	 *
	 * @param array<string, string> $couples clé → empreinte
	 */
	public static function retenir( array $couples ): void {
		if ( ! $couples ) {
			return;
		}

		$toutes = self::toutes();

		foreach ( $couples as $cle => $empreinte ) {
			$toutes[ (string) $cle ] = (string) $empreinte;
		}

		self::$cache = $toutes;

		update_site_option( self::OPTION, $toutes );
	}

	/** Oublie des clés — après une suppression, sinon l'empreinte survit à sa chose. */
	public static function oublier( array $cles ): void {
		$toutes = self::toutes();
		$change = false;

		foreach ( $cles as $cle ) {
			if ( isset( $toutes[ (string) $cle ] ) ) {
				unset( $toutes[ (string) $cle ] );
				$change = true;
			}
		}

		if ( ! $change ) {
			return;
		}

		self::$cache = $toutes;

		update_site_option( self::OPTION, $toutes );
	}

	/**
	 * Le verdict pour une chose, en une ligne.
	 *
	 * @param mixed $servi La valeur actuellement en place.
	 * @return array{ecrire: bool, motif: string}
	 */
	public static function verdict( string $cle, $servi ): array {
		$vide = ! $servi || ( is_array( $servi ) && ! $servi );

		return Bricks_Adapter::verdict(
			self::retenue( $cle ),
			Bricks_Adapter::empreinte( is_array( $servi ) ? $servi : [ $servi ] ),
			$vide
		);
	}

	/**
	 * Le refus, rédigé une fois pour toutes les surfaces globales.
	 *
	 * Un **409** : il ne manque aucun droit, il y a un désaccord d'état. Un 403
	 * enverrait chercher une permission — le seul endroit où il n'y a rien à
	 * corriger.
	 *
	 * @param array<int, string> $noms   Ce qui a été touché à la main.
	 * @param string             $quoi   « classe(s) », « variable(s) »…
	 * @param string             $motif  Le motif du verdict, pour la conduite à tenir.
	 */
	public static function refus( array $noms, string $quoi, string $motif ): \WP_Error {
		$explication = 'provenance-inconnue' === $motif
			? sprintf(
				'%s existe(nt) déjà sans que nous l’ayons écrit(e) : %s. Leur provenance est '
					. 'inconnue, donc écrire serait effacer un travail dont on ignore l’auteur.',
				ucfirst( $quoi ),
				implode( ', ', $noms )
			)
			: sprintf(
				'%s a/ont été modifié(e)s depuis notre dernière écriture — dans le builder, '
					. 'ou à la main : %s. Écrire maintenant effacerait ce travail.',
				ucfirst( $quoi ),
				implode( ', ', $noms )
			);

		return new \WP_Error(
			'anode_bridge_ecrasement',
			$explication . ' Rien n’a été écrit.'
				. ' Trois sorties : relire l’état réel et repartir de là ;'
				. ' écraser en le demandant (overwrite: true) ;'
				. ' ou ne rien faire — l’écart reste, et il est nommé.',
			[
				'status' => 409,
				'motif'  => $motif,
				'noms'   => array_values( $noms ),
			]
		);
	}
}
