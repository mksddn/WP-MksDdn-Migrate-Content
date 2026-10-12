<?php
/**
 * Export → prepare → import harness for selected-content integration tests.
 *
 * @package MksDdn_Migrate_Content
 */

declare(strict_types=1);

namespace MksDdn\MigrateContent\Tests\Support;

use MksDdn\MigrateContent\Admin\Services\ImportPayloadPreparer;
use MksDdn\MigrateContent\Archive\Extractor;
use MksDdn\MigrateContent\Export\ExportHandler;
use MksDdn\MigrateContent\Import\ImportHandler;
use MksDdn\MigrateContent\Selection\ContentSelection;
use WP_Error;
use ZipArchive;

/**
 * Runs real Packer archives through ImportPayloadPreparer and ImportHandler.
 */
final class SelectedExportImportHarness {

	/** @var string */
	private string $tmpdir;

	/** @var list<string> */
	private array $files = array();

	public function __construct( string $tmpdir ) {
		$this->tmpdir = $tmpdir;
	}

	/**
	 * Export selection to a temp file (.wpbkp or .json).
	 *
	 * @param ContentSelection $selection Selection.
	 * @param string           $format    archive|json.
	 * @param bool             $collect_media Whether to embed media binaries.
	 * @return string|WP_Error Absolute path.
	 */
	public function export_file( ContentSelection $selection, string $format = 'archive', bool $collect_media = true ) {
		$handler = new ExportHandler();
		$handler->set_collect_media( $collect_media );
		$file = $handler->create_selected_export_file( $selection, $format );
		if ( is_wp_error( $file ) ) {
			return $file;
		}

		// Packer uses wp_tempnam() without a stable extension; importers key off .wpbkp/.json.
		$extension = ( 'json' === $format ) ? 'json' : 'wpbkp';
		$dest      = $this->tmpdir . '/export-' . uniqid( '', true ) . '.' . $extension;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- move Packer temp into test tmpdir with extension.
		if ( ! rename( $file, $dest ) ) {
			if ( ! copy( $file, $dest ) ) {
				return new WP_Error( 'mksddn_mc_test_copy', 'Unable to move export file into test tmpdir.' );
			}
			unlink( $file );
		}

		$this->files[] = $dest;
		return $dest;
	}

	/**
	 * Prepare + import an export file. Wipes nothing — caller deletes source posts first.
	 *
	 * @param string $file_path Absolute export path.
	 * @return true|WP_Error
	 */
	public function import_file( string $file_path ) {
		$extension = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );
		$mime      = 'json' === $extension ? 'application/json' : 'application/zip';

		$preparer = new ImportPayloadPreparer();
		$prepared = $preparer->prepare( $extension, $mime, $file_path );
		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}

		$payload              = $prepared['payload'];
		$payload_type         = $prepared['type'];
		$payload['type']      = $payload_type;
		$payload['_mksddn_media'] = $payload['_mksddn_media'] ?? $prepared['media'];

		$handler = new ImportHandler();
		if ( 'archive' === $prepared['media_source'] ) {
			$extractor = new Extractor();
			$handler->set_media_file_loader(
				static function ( string $archive_path ) use ( $extractor, $file_path ) {
					return $extractor->extract_media_file( $archive_path, $file_path );
				}
			);
		}

		if ( 'bundle' === $payload_type ) {
			$ok = $handler->import_bundle( $payload );
			return $ok ? true : new WP_Error( 'mksddn_mc_import_failed', $handler->get_last_error() );
		}

		$result = $handler->import_single_page( $payload );
		if ( false === $result ) {
			return new WP_Error( 'mksddn_mc_import_failed', $handler->get_last_error() );
		}

		return true;
	}

	/**
	 * Export then import in one step.
	 *
	 * @param ContentSelection $selection Selection.
	 * @param string           $format    archive|json.
	 * @param bool             $collect_media Media flag.
	 * @return string|WP_Error Export path on success.
	 */
	public function export_then_import( ContentSelection $selection, string $format = 'archive', bool $collect_media = true ) {
		$file = $this->export_file( $selection, $format, $collect_media );
		if ( is_wp_error( $file ) ) {
			return $file;
		}

		$imported = $this->import_file( $file );
		if ( is_wp_error( $imported ) ) {
			return $imported;
		}

		return $file;
	}

	/**
	 * Corrupt payload/content.json inside a Packer archive so checksum validation fails.
	 *
	 * @param string $archive_path Absolute .wpbkp path.
	 * @return true|WP_Error
	 */
	public function tamper_payload_checksum( string $archive_path ) {
		$zip = new ZipArchive();
		if ( true !== $zip->open( $archive_path ) ) {
			return new WP_Error( 'mksddn_mc_test_zip', 'Unable to open archive for tampering.' );
		}

		$payload = $zip->getFromName( 'payload/content.json' );
		if ( false === $payload ) {
			$zip->close();
			return new WP_Error( 'mksddn_mc_test_zip', 'payload/content.json missing.' );
		}

		$zip->addFromString( 'payload/content.json', $payload . "\n" );
		$zip->close();

		return true;
	}

	/**
	 * Prepare only (no import) — for checksum / preflight assertions.
	 *
	 * @param string $file_path Absolute path.
	 * @return array|WP_Error
	 */
	public function prepare_only( string $file_path ) {
		$extension = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );
		$mime      = 'json' === $extension ? 'application/json' : 'application/zip';
		return ( new ImportPayloadPreparer() )->prepare( $extension, $mime, $file_path );
	}
}
