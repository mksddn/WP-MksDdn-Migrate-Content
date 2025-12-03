<?php
/**
 * Restores full wp-content from archive.
 *
 * @package MksDdn_Migrate_Content
 */

namespace Mksddn_MC\Filesystem;

use Mksddn_MC\Database\FullDatabaseImporter;
use Mksddn_MC\Support\DomainReplacer;
use Mksddn_MC\Support\SiteUrlGuard;
use Mksddn_MC\Users\UserMergeApplier;
use WP_Error;
use ZipArchive;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Imports files to uploads/themes/plugins.
 */
class FullContentImporter {

	private FullDatabaseImporter $db_importer;
	private bool $database_imported = false;
	private array $user_merge_summary = array();

	/**
	 * Setup importer.
	 *
	 * @param FullDatabaseImporter|null $db_importer Optional database importer.
	 */
	public function __construct( ?FullDatabaseImporter $db_importer = null ) {
		$this->db_importer = $db_importer ?? new FullDatabaseImporter();
	}

	/**
	 * Extract allowed paths to wp-content and restore DB if present.
	 *
	 * @param string $archive_path Uploaded archive.
	 * @return true|WP_Error
	 */
	public function import_from( string $archive_path, ?SiteUrlGuard $url_guard = null, array $options = array() ) {
		$zip = new ZipArchive();
		if ( true !== $zip->open( $archive_path ) ) {
			return new WP_Error( 'mksddn_zip_open', __( 'Unable to open archive for import.', 'mksddn-migrate-content' ) );
		}
		$url_guard = $url_guard ?? new SiteUrlGuard();

		$this->user_merge_summary = array();
		$db_result = $this->maybe_import_database( $zip, $options );
		if ( is_wp_error( $db_result ) ) {
			$zip->close();
			return $db_result;
		}

		$files_result = $this->extract_files( $zip );
		$zip->close();

		if ( $this->database_imported ) {
			$url_guard->restore();
		}

		return $files_result;
	}

	/**
	 * Return summary for last user merge operation.
	 *
	 * @return array
	 */
	public function get_user_merge_summary(): array {
		return $this->user_merge_summary;
	}

	private function is_allowed_path( string $path, array $allowed ): bool {
		foreach ( $allowed as $root ) {
			if ( 0 === strpos( $path, $root ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Skip version-control or dot directories.
	 *
	 * @param string $path Archive path.
	 * @return bool
	 */
	private function should_skip_path( string $path ): bool {
		$ignored = array( '.git/', '.svn/', '.hg/', '.DS_Store' );
		foreach ( $ignored as $needle ) {
			if ( false !== strpos( $path, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Import database dump when present in archive payload.
	 *
	 * @param ZipArchive $zip Archive instance.
	 * @return true|WP_Error
	 */
	private function maybe_import_database( ZipArchive $zip, array $options = array() ) {
		$payload_json = $zip->getFromName( 'payload/content.json' );
		if ( false === $payload_json ) {
			return true;
		}

		$data = json_decode( $payload_json, true );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return new WP_Error( 'mksddn_mc_full_import_payload', __( 'Corrupted payload inside archive.', 'mksddn-migrate-content' ) );
		}

		if ( empty( $data['database'] ) || ! is_array( $data['database'] ) ) {
			return true;
		}

		$current_base  = function_exists( 'home_url' ) ? home_url() : (string) get_option( 'home' );
		$uploads       = wp_upload_dir();
		$current_paths = array(
			'root'    => ABSPATH,
			'content' => WP_CONTENT_DIR,
			'uploads' => isset( $uploads['basedir'] ) ? $uploads['basedir'] : WP_CONTENT_DIR . '/uploads',
		);

		$replacer = new DomainReplacer();
		$replacer->replace_dump_environment( $data['database'], $current_base, $current_paths );

		$user_merge      = isset( $options['user_merge'] ) && is_array( $options['user_merge'] ) ? $options['user_merge'] : array();
		$merge_enabled   = ! empty( $user_merge['enabled'] );
		$merge_plan      = isset( $user_merge['plan'] ) && is_array( $user_merge['plan'] ) ? $user_merge['plan'] : array();
		$user_tables     = isset( $user_merge['tables'] ) && is_array( $user_merge['tables'] ) ? $user_merge['tables'] : array();
		$user_applier    = null;
		$remote_snapshot = array();

		if ( $merge_enabled ) {
			$user_applier    = new UserMergeApplier();
			$remote_snapshot = $user_applier->extract_remote_users( $data['database'], $user_tables );
			$user_applier->strip_user_tables( $data['database'], $user_tables );
		}

		$result = $this->db_importer->import( $data['database'] );
		if ( true !== $result ) {
			return $result;
		}

			$this->database_imported = true;

		if ( $user_applier ) {
			$merge_result = $user_applier->merge( $remote_snapshot['users'], $merge_plan, $remote_snapshot['prefix'] ?? '' );
			if ( is_wp_error( $merge_result ) ) {
				return $merge_result;
			}

			$this->user_merge_summary = $merge_result;
		}

		return true;
	}

	/**
	 * Extract filesystem from archive.
	 *
	 * @param ZipArchive $zip Archive instance.
	 * @return true|WP_Error
	 */
	private function extract_files( ZipArchive $zip ) {
		$allowed_roots = array(
			'wp-content/uploads',
			'wp-content/plugins',
			'wp-content/themes',
		);

		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$stat = $zip->statIndex( $i );
			if ( ! $stat || empty( $stat['name'] ) ) {
				continue;
			}

			$name      = $stat['name'];
			$normalized = $this->normalize_archive_path( $name );

			if ( null === $normalized || $this->should_skip_path( $normalized ) ) {
				continue;
			}

			if ( ! $this->is_allowed_path( $normalized, $allowed_roots ) ) {
				continue;
			}

			$target       = trailingslashit( ABSPATH ) . $normalized;
			$is_directory = '/' === substr( $name, -1 ) || '/' === substr( $normalized, -1 );

			if ( $is_directory ) {
				wp_mkdir_p( $target );
				continue;
			}

			$dir = dirname( $target );
			wp_mkdir_p( $dir );

			$stream = $zip->getStream( $name );
			if ( ! $stream ) {
				return new WP_Error( 'mksddn_zip_stream', sprintf( __( 'Unable to read "%s" from archive.', 'mksddn-migrate-content' ), $name ) );
			}

			$file = fopen( $target, 'wb' );
			if ( ! $file ) {
				fclose( $stream );
				return new WP_Error( 'mksddn_fs_write', sprintf( __( 'Unable to write "%s". Check permissions.', 'mksddn-migrate-content' ), $target ) );
			}

			stream_copy_to_stream( $stream, $file );
			fclose( $stream );
			fclose( $file );
		}

		return true;
	}

	/**
	 * Normalize archive path by removing wrapper directories.
	 *
	 * @param string $path Raw archive path.
	 * @return string|null
	 */
	private function normalize_archive_path( string $path ): ?string {
		if ( '' === $path ) {
			return null;
		}

		// Skip manifest/payload/meta files.
		if ( 0 === strpos( $path, 'manifest' ) || 0 === strpos( $path, 'payload/' ) ) {
			return null;
		}

		if ( 0 === strpos( $path, 'files/' ) ) {
			$path = substr( $path, 6 );
		}

		$path = ltrim( $path, '/' );

		return '' === $path ? null : $path;
	}

}


