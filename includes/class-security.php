<?php
/**
 * Permissions, capability dédiée et journal d'audit.
 *
 * Le MCP est mis à disposition des clients : toute écriture passe par ici.
 * Le principe est simple — un utilisateur WordPress dédié, un mot de passe
 * d'application révocable, une capability explicite, et une trace de chaque
 * écriture.
 *
 * @package Anode\Bridge
 */

declare( strict_types = 1 );

namespace Anode\Bridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Security {

	/** Capability requise pour écrire via le pont. */
	public const CAP_WRITE = 'anode_bridge_write';

	/** Capability requise pour lire via le pont. */
	public const CAP_READ = 'anode_bridge_read';

	/** Option stockant le journal d'audit (rotatif). */
	private const LOG_OPTION = 'anode_bridge_audit_log';

	/** Nombre d'entrées conservées dans le journal. */
	private const LOG_MAX = 200;

	/**
	 * Lecture : capability dédiée, ou tout utilisateur pouvant éditer des pages.
	 */
	public static function can_read(): bool {
		return current_user_can( self::CAP_READ ) || current_user_can( 'edit_pages' );
	}

	/**
	 * Écriture de contenu (pages, articles, médias, contenu Bricks d'une page).
	 */
	public static function can_write(): bool {
		return current_user_can( self::CAP_WRITE ) || current_user_can( 'edit_pages' );
	}

	/**
	 * Écriture globale (classes, variables, design system, options, cache).
	 *
	 * Ces données sont partagées par tout le site : on exige `manage_options`.
	 */
	public static function can_manage(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Callback de permission REST : lecture.
	 */
	public static function permission_read(): bool|\WP_Error {
		return self::can_read() ? true : self::forbidden( 'lecture' );
	}

	/**
	 * Callback de permission REST : écriture de contenu.
	 */
	public static function permission_write(): bool|\WP_Error {
		return self::can_write() ? true : self::forbidden( 'écriture' );
	}

	/**
	 * Callback de permission REST : administration.
	 */
	public static function permission_manage(): bool|\WP_Error {
		return self::can_manage() ? true : self::forbidden( 'administration' );
	}

	private static function forbidden( string $scope ): \WP_Error {
		return new \WP_Error(
			'anode_bridge_forbidden',
			sprintf( 'Permissions insuffisantes pour cette opération (%s).', $scope ),
			[ 'status' => is_user_logged_in() ? 403 : 401 ]
		);
	}

	/**
	 * Donne les capabilities du pont au rôle administrateur.
	 */
	public static function grant_capability_to_admins(): void {
		$role = get_role( 'administrator' );

		if ( $role instanceof \WP_Role ) {
			$role->add_cap( self::CAP_READ );
			$role->add_cap( self::CAP_WRITE );
		}
	}

	/**
	 * Journalise une écriture (qui, quoi, quand, sur quelle cible).
	 *
	 * @param string               $action  Identifiant court de l'action (ex. « bricks.content.update »).
	 * @param array<string, mixed> $context Contexte non sensible : id de post, nombre d'éléments, etc.
	 */
	public static function log( string $action, array $context = [] ): void {
		$log = get_option( self::LOG_OPTION, [] );

		if ( ! is_array( $log ) ) {
			$log = [];
		}

		array_unshift(
			$log,
			[
				'time'    => gmdate( 'c' ),
				'user'    => get_current_user_id(),
				'login'   => wp_get_current_user()->user_login ?? '',
				'action'  => $action,
				'context' => $context,
				'ip'      => self::client_ip(),
			]
		);

		update_option( self::LOG_OPTION, array_slice( $log, 0, self::LOG_MAX ), false );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_log( int $limit = 50 ): array {
		$log = get_option( self::LOG_OPTION, [] );

		return is_array( $log ) ? array_slice( $log, 0, max( 1, $limit ) ) : [];
	}

	private static function client_ip(): string {
		$ip = $_SERVER['REMOTE_ADDR'] ?? '';

		return is_string( $ip ) ? sanitize_text_field( $ip ) : '';
	}
}
