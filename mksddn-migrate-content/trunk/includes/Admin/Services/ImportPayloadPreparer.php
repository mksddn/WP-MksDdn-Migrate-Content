<?php
/**
 * @file: ImportPayloadPreparer.php
 * @description: Service for preparing import payload from uploaded files
 * @dependencies: Archive\Extractor
 * @created: 2024-12-15
 */

namespace MksDdn\MigrateContent\Admin\Services;

use MksDdn\MigrateContent\Archive\Extractor;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Service for preparing import payload from uploaded files.
 *
 * @since 1.0.0
 */
class ImportPayloadPreparer {

	/**
	 * Archive extractor.
	 *
	 * @var Extractor
	 */
	private Extractor $extractor;

	/**
	 * Constructor.
	 *
	 * @param Extractor|null $extractor Archive extractor.
	 * @since 1.0.0
	 */
	public function __construct( ?Extractor $extractor = null ) {
		$this->extractor = $extractor ?? new Extractor();
	}

	/**
	 * Prepare import payload from uploaded file.
	 *
	 * @param string $extension File extension (lowercase).
	 * @param string $mime      Detected MIME type.
	 * @param string $file_path Uploaded file path.
	 * @return array|WP_Error Prepared payload data or error.
	 * @since 1.0.0
	 */
	public function prepare( string $extension, string $mime, string $file_path ): array|WP_Error {
		switch ( $extension ) {
			case 'json':
				$data = $this->read_json( $file_path );
				if ( is_wp_error( $data ) ) {
					return $data;
				}

				return array(
					'type'         => $data['type'] ?? 'page',
					'payload'      => $data,
					'media'        => $data['_mksddn_media'] ?? array(),
					'media_source' => 'json',
				);

			case 'wpbkp':
				$extracted = $this->extractor->extract( $file_path );
				if ( is_wp_error( $extracted ) ) {
					return $extracted;
				}

				return array(
					'type'         => $extracted['type'],
					'payload'      => $extracted['payload'],
					'media'        => $extracted['media'] ?? array(),
					'media_source' => 'archive',
				);
		}

		return new WP_Error( 'mksddn_mc_invalid_type', __( 'Unsupported file extension. Use .wpbkp or .json.', 'mksddn-migrate-content' ) );
	}

	/**
	 * Read and decode JSON payload.
	 *
	 * @param string $file_path Uploaded file path.
	 * @return array|WP_Error Decoded JSON data or error.
	 * @since 1.0.0
	 */
	public function read_json( string $file_path ): array|WP_Error {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file -- Local temporary file validated earlier.
		$json = file_get_contents( $file_path );
		if ( false === $json ) {
			return new WP_Error( 'mksddn_mc_json_unreadable', __( 'Unable to read JSON file.', 'mksddn-migrate-content' ) );
		}

		$data = json_decode( $json, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $data ) ) {
			return new WP_Error( 'mksddn_mc_json_invalid', __( 'Invalid JSON structure.', 'mksddn-migrate-content' ) );
		}

		return $data;
	}
}

