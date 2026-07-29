<?php
/**
 * Endpoints médias complémentaires à wp/v2/media.
 *
 * L'API standard exige d'envoyer le binaire dans le corps de la requête, ce
 * qui est peu pratique depuis un client MCP. On ajoute le téléversement
 * depuis une URL, et la régénération des miniatures.
 *
 * @package Anode\Bridge
 */

declare( strict_types = 1 );

namespace Anode\Bridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Rest_Media {

	/** Types MIME acceptés au téléversement distant. */
	private const ALLOWED_MIME = [
		'image/jpeg',
		'image/png',
		'image/gif',
		'image/webp',
		'image/avif',
		'image/svg+xml',
		'video/mp4',
		'video/webm',
		'application/pdf',
	];

	/** Taille maximale d'un téléversement distant (20 Mo). */
	private const MAX_BYTES = 20 * 1024 * 1024;

	public function register_routes(): void {
		register_rest_route(
			NAMESPACE_,
			'/media/sideload',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'sideload' ],
				'permission_callback' => [ $this, 'can_upload' ],
				'args'                => [
					'url'         => [
						'required'    => true,
						'type'        => 'string',
						'format'      => 'uri',
						'description' => 'URL publique du fichier à importer.',
					],
					'filename'    => [ 'type' => 'string' ],
					'title'       => [ 'type' => 'string' ],
					'alt'         => [ 'type' => 'string' ],
					'caption'     => [ 'type' => 'string' ],
					'description' => [ 'type' => 'string' ],
					'post_id'     => [ 'type' => 'integer', 'default' => 0 ],
				],
			]
		);

		register_rest_route(
			NAMESPACE_,
			'/media/(?P<id>\d+)/regenerate',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'regenerate' ],
				'permission_callback' => [ $this, 'can_upload' ],
				'args'                => [
					'id' => [ 'required' => true, 'type' => 'integer' ],
				],
			]
		);
	}

	public function can_upload(): bool|\WP_Error {
		if ( current_user_can( 'upload_files' ) ) {
			return true;
		}

		return new \WP_Error(
			'anode_bridge_forbidden',
			'Permissions insuffisantes pour gérer la médiathèque.',
			[ 'status' => is_user_logged_in() ? 403 : 401 ]
		);
	}

	/**
	 * Importe un fichier distant dans la médiathèque.
	 */
	public function sideload( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$url = (string) $request->get_param( 'url' );

		if ( ! wp_http_validate_url( $url ) ) {
			return new \WP_Error( 'anode_bridge_invalid', 'URL invalide ou non autorisée.', [ 'status' => 400 ] );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		// Contrôle du poids et du type avant de rapatrier le fichier.
		$head = wp_safe_remote_head( $url, [ 'timeout' => 15, 'redirection' => 3 ] );

		if ( ! is_wp_error( $head ) ) {
			$length = (int) wp_remote_retrieve_header( $head, 'content-length' );
			$type   = strtok( (string) wp_remote_retrieve_header( $head, 'content-type' ), ';' );

			if ( $length > self::MAX_BYTES ) {
				return new \WP_Error(
					'anode_bridge_too_large',
					sprintf( 'Fichier trop volumineux (%s). Limite : %s.', size_format( $length ), size_format( self::MAX_BYTES ) ),
					[ 'status' => 413 ]
				);
			}

			if ( $type && ! in_array( $type, self::ALLOWED_MIME, true ) ) {
				return new \WP_Error(
					'anode_bridge_invalid',
					sprintf( 'Type de fichier non autorisé : %s.', $type ),
					[ 'status' => 415 ]
				);
			}
		}

		$temp = download_url( $url, 30 );

		if ( is_wp_error( $temp ) ) {
			return new \WP_Error(
				'anode_bridge_download_failed',
				sprintf( 'Téléchargement impossible : %s', $temp->get_error_message() ),
				[ 'status' => 502 ]
			);
		}

		if ( filesize( $temp ) > self::MAX_BYTES ) {
			wp_delete_file( $temp );

			return new \WP_Error(
				'anode_bridge_too_large',
				sprintf( 'Fichier trop volumineux. Limite : %s.', size_format( self::MAX_BYTES ) ),
				[ 'status' => 413 ]
			);
		}

		$filename = (string) ( $request->get_param( 'filename' ) ?: basename( wp_parse_url( $url, PHP_URL_PATH ) ?? '' ) );
		$filename = sanitize_file_name( $filename ?: 'import' );

		$checked = wp_check_filetype( $filename );

		if ( ! $checked['type'] || ! in_array( $checked['type'], self::ALLOWED_MIME, true ) ) {
			wp_delete_file( $temp );

			return new \WP_Error(
				'anode_bridge_invalid',
				sprintf( 'Extension non autorisée pour « %s ».', $filename ),
				[ 'status' => 415 ]
			);
		}

		$attachment_id = media_handle_sideload(
			[
				'name'     => $filename,
				'tmp_name' => $temp,
			],
			(int) $request->get_param( 'post_id' ),
			$request->get_param( 'title' ) ? sanitize_text_field( (string) $request->get_param( 'title' ) ) : null
		);

		if ( is_wp_error( $attachment_id ) ) {
			wp_delete_file( $temp );

			return new \WP_Error(
				'anode_bridge_upload_failed',
				sprintf( 'Import impossible : %s', $attachment_id->get_error_message() ),
				[ 'status' => 500 ]
			);
		}

		$this->apply_metadata( (int) $attachment_id, $request );

		Security::log( 'media.sideload', [ 'id' => $attachment_id, 'source' => $url ] );

		return rest_ensure_response( $this->format_attachment( (int) $attachment_id ) );
	}

	public function regenerate( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id = (int) $request->get_param( 'id' );

		if ( 'attachment' !== get_post_type( $id ) ) {
			return new \WP_Error( 'anode_bridge_not_found', sprintf( 'Aucun média avec l’identifiant %d.', $id ), [ 'status' => 404 ] );
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';

		$file = get_attached_file( $id );

		if ( ! $file || ! file_exists( $file ) ) {
			return new \WP_Error( 'anode_bridge_not_found', 'Fichier source introuvable sur le serveur.', [ 'status' => 404 ] );
		}

		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $file ) );

		Security::log( 'media.regenerate', [ 'id' => $id ] );

		return rest_ensure_response( $this->format_attachment( $id ) );
	}

	private function apply_metadata( int $attachment_id, \WP_REST_Request $request ): void {
		$alt = $request->get_param( 'alt' );

		if ( is_string( $alt ) && '' !== $alt ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $alt ) );
		}

		$fields = [];

		if ( is_string( $request->get_param( 'caption' ) ) ) {
			$fields['post_excerpt'] = sanitize_text_field( (string) $request->get_param( 'caption' ) );
		}

		if ( is_string( $request->get_param( 'description' ) ) ) {
			$fields['post_content'] = wp_kses_post( (string) $request->get_param( 'description' ) );
		}

		if ( $fields ) {
			wp_update_post( [ 'ID' => $attachment_id ] + $fields );
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	private function format_attachment( int $id ): array {
		$metadata = wp_get_attachment_metadata( $id );

		return [
			'id'        => $id,
			'title'     => get_the_title( $id ),
			'url'       => wp_get_attachment_url( $id ),
			'mime_type' => get_post_mime_type( $id ),
			'alt'       => get_post_meta( $id, '_wp_attachment_image_alt', true ),
			'width'     => $metadata['width'] ?? null,
			'height'    => $metadata['height'] ?? null,
			'sizes'     => array_keys( $metadata['sizes'] ?? [] ),
		];
	}
}
