<?php
/**
 * WP-CLI command handler
 *
 * @package MksDdn_Migrate_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access not allowed' );
}

if ( defined( 'WP_CLI' ) && constant( 'WP_CLI' ) ) {
	/**
	 * WP-CLI command class
	 */
	class MksDdn_MC_WP_CLI_Command extends WP_CLI_Command {

		/**
		 * Export site to archive file
		 *
		 * ## EXAMPLES
		 *
		 *     $ wp mksddn-mc export
		 *
		 * @subcommand export
		 * @param array $args Arguments
		 * @param array $assoc_args Associated arguments
		 */
		public function export( $args = array(), $assoc_args = array() ) {
			WP_CLI::line( __( 'Export functionality will be implemented in export module.', 'mksddn-migrate-content' ) );
		}

		/**
		 * Import site from archive file
		 *
		 * ## EXAMPLES
		 *
		 *     $ wp mksddn-mc import <file>
		 *
		 * @subcommand import
		 * @param array $args Arguments
		 * @param array $assoc_args Associated arguments
		 */
		public function import( $args = array(), $assoc_args = array() ) {
			if ( empty( $args[0] ) ) {
				WP_CLI::error( __( 'Please provide archive file path.', 'mksddn-migrate-content' ) );
			}

			$file_path = $args[0];
			if ( ! file_exists( $file_path ) ) {
				WP_CLI::error( sprintf( __( 'File not found: %s', 'mksddn-migrate-content' ), $file_path ) );
			}

			WP_CLI::line( __( 'Import functionality will be implemented in import module.', 'mksddn-migrate-content' ) );
		}

		/**
		 * List backup files
		 *
		 * ## EXAMPLES
		 *
		 *     $ wp mksddn-mc list
		 *
		 * @subcommand list
		 * @param array $args Arguments
		 * @param array $assoc_args Associated arguments
		 */
		public function list_backups( $args = array(), $assoc_args = array() ) {
			$backups_path = MKSDDN_MC_BACKUPS_PATH;

			if ( ! is_dir( $backups_path ) ) {
				WP_CLI::line( __( 'No backups directory found.', 'mksddn-migrate-content' ) );
				return;
			}

			$files = glob( $backups_path . '/*.{mksddn,migrate}', GLOB_BRACE );
			if ( empty( $files ) ) {
				WP_CLI::line( __( 'No backup files found.', 'mksddn-migrate-content' ) );
				return;
			}

			$backups = array();
			foreach ( $files as $file ) {
				$backups[] = array(
					'file' => basename( $file ),
					'size' => mksddn_mc_size_format( filesize( $file ) ),
					'date' => date( 'Y-m-d H:i:s', filemtime( $file ) ),
				);
			}

			WP_CLI\Utils\format_items( 'table', $backups, array( 'file', 'size', 'date' ) );
		}

		/**
		 * Delete backup file
		 *
		 * ## EXAMPLES
		 *
		 *     $ wp mksddn-mc delete <file>
		 *
		 * @subcommand delete
		 * @param array $args Arguments
		 * @param array $assoc_args Associated arguments
		 */
		public function delete( $args = array(), $assoc_args = array() ) {
			if ( empty( $args[0] ) ) {
				WP_CLI::error( __( 'Please provide backup file name.', 'mksddn-migrate-content' ) );
			}

			$file_name = $args[0];
			$file_path = MKSDDN_MC_BACKUPS_PATH . DIRECTORY_SEPARATOR . $file_name;

			if ( ! file_exists( $file_path ) ) {
				WP_CLI::error( sprintf( __( 'Backup file not found: %s', 'mksddn-migrate-content' ), $file_name ) );
			}

			if ( @unlink( $file_path ) ) {
				WP_CLI::success( sprintf( __( 'Backup file deleted: %s', 'mksddn-migrate-content' ), $file_name ) );
			} else {
				WP_CLI::error( sprintf( __( 'Failed to delete backup file: %s', 'mksddn-migrate-content' ), $file_name ) );
			}
		}
	}

	WP_CLI::add_command( 'mksddn-mc', 'MksDdn_MC_WP_CLI_Command' );
}

