<?php
/**
 * Backups model
 *
 * @package MksDdn_Migrate_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access not allowed' );
}

/**
 * Backups class
 */
class MksDdn_MC_Backups {

	/**
	 * Get all backup files
	 *
	 * @return array
	 */
	public static function get_files() {
		$backups = array();

		if ( ! is_dir( MKSDDN_MC_BACKUPS_PATH ) ) {
			return $backups;
		}

		try {
			// Get all .mksddn and .migrate files
			$iterator = new MksDdn_MC_Recursive_Directory_Iterator( MKSDDN_MC_BACKUPS_PATH );
			$iterator = new MksDdn_MC_Recursive_Extension_Filter( $iterator, array( 'mksddn', 'migrate' ) );
			$iterator = new MksDdn_MC_Recursive_Iterator_Iterator( $iterator, RecursiveIteratorIterator::LEAVES_ONLY );

			foreach ( $iterator as $item ) {
				try {
					$file_path = $item->getPathname();
					$file_size = @filesize( $file_path );
					$file_mtime = @filemtime( $file_path );

					$backups[] = array(
						'path'     => str_replace( MKSDDN_MC_BACKUPS_PATH . DIRECTORY_SEPARATOR, '', $file_path ),
						'filename' => basename( $file_path ),
						'mtime'    => $file_mtime ? $file_mtime : 0,
						'size'     => $file_size !== false ? $file_size : null,
					);
				} catch ( Exception $e ) {
					// Skip invalid files
					continue;
				}
			}

			// Sort by modification time (newest first)
			usort( $backups, array( __CLASS__, 'compare' ) );

		} catch ( Exception $e ) {
			// Return empty array on error
		}

		return $backups;
	}

	/**
	 * Count backup files
	 *
	 * @return int
	 */
	public static function count_files() {
		return count( self::get_files() );
	}

	/**
	 * Delete backup file
	 *
	 * @param string $filename Backup filename
	 * @return bool
	 * @throws Exception
	 */
	public static function delete_file( $filename ) {
		if ( empty( $filename ) ) {
			throw new Exception( __( 'Backup filename is required.', 'mksddn-migrate-content' ) );
		}

		// Validate filename
		if ( mksddn_mc_validate_file( $filename ) !== 0 ) {
			throw new Exception( __( 'Invalid backup filename.', 'mksddn-migrate-content' ) );
		}

		$file_path = MKSDDN_MC_BACKUPS_PATH . DIRECTORY_SEPARATOR . basename( $filename );

		if ( ! file_exists( $file_path ) ) {
			throw new Exception( __( 'Backup file not found.', 'mksddn-migrate-content' ) );
		}

		if ( ! @unlink( $file_path ) ) {
			throw new Exception( __( 'Failed to delete backup file.', 'mksddn-migrate-content' ) );
		}

		return true;
	}

	/**
	 * Get backup file path
	 *
	 * @param string $filename Backup filename
	 * @return string
	 * @throws Exception
	 */
	public static function get_file_path( $filename ) {
		if ( empty( $filename ) ) {
			throw new Exception( __( 'Backup filename is required.', 'mksddn-migrate-content' ) );
		}

		// Validate filename
		if ( mksddn_mc_validate_file( $filename ) !== 0 ) {
			throw new Exception( __( 'Invalid backup filename.', 'mksddn-migrate-content' ) );
		}

		$file_path = MKSDDN_MC_BACKUPS_PATH . DIRECTORY_SEPARATOR . basename( $filename );

		if ( ! file_exists( $file_path ) ) {
			throw new Exception( __( 'Backup file not found.', 'mksddn-migrate-content' ) );
		}

		return $file_path;
	}

	/**
	 * Check if backups are downloadable
	 *
	 * @return bool
	 */
	public static function are_downloadable() {
		return true;
	}

	/**
	 * Compare backups by modification time
	 *
	 * @param array $a First backup
	 * @param array $b Second backup
	 * @return int
	 */
	public static function compare( $a, $b ) {
		if ( $a['mtime'] === $b['mtime'] ) {
			return 0;
		}
		return ( $a['mtime'] > $b['mtime'] ) ? -1 : 1;
	}
}

