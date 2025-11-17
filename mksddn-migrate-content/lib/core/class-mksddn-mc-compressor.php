<?php
/**
 * Compressor class
 *
 * @package MksDdn_Migrate_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access not allowed' );
}

/**
 * Compressor class for creating archives
 */
class MksDdn_MC_Compressor extends MksDdn_MC_Archiver {

	/**
	 * Add file to archive
	 *
	 * @param string $file_path File path
	 * @param string $archive_path Archive path
	 * @return bool
	 */
	public function add_file( $file_path, $archive_path = '' ) {
		if ( ! file_exists( $file_path ) ) {
			return false;
		}

		if ( empty( $archive_path ) ) {
			$archive_path = basename( $file_path );
		}

		$archive_path = $this->replace_directory_separator_with_forward_slash( $archive_path );

		// Write file header
		$header = $this->create_file_header( $file_path, $archive_path );
		@fwrite( $this->file_handle, $header );

		// Write file content
		$file_handle = @fopen( $file_path, 'rb' );
		if ( $file_handle ) {
			while ( ! feof( $file_handle ) ) {
				$chunk = @fread( $file_handle, 8192 );
				if ( $chunk !== false ) {
					@fwrite( $this->file_handle, $chunk );
				}
			}
			@fclose( $file_handle );
		}

		return true;
	}

	/**
	 * Add directory to archive
	 *
	 * @param string $dir_path Directory path
	 * @param string $archive_path Archive path
	 * @return bool
	 */
	public function add_directory( $dir_path, $archive_path = '' ) {
		if ( ! is_dir( $dir_path ) ) {
			return false;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir_path, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $iterator as $item ) {
			if ( $item->isFile() ) {
				$file_path    = $item->getPathname();
				$relative_path = str_replace( $dir_path . DIRECTORY_SEPARATOR, '', $file_path );

				if ( ! empty( $archive_path ) ) {
					$relative_path = $archive_path . '/' . $relative_path;
				}

				$this->add_file( $file_path, $relative_path );
			}
		}

		return true;
	}

	/**
	 * Create file header
	 *
	 * @param string $file_path File path
	 * @param string $archive_path Archive path
	 * @return string
	 */
	private function create_file_header( $file_path, $archive_path ) {
		$file_size = filesize( $file_path );
		$mtime     = filemtime( $file_path );
		$filename  = basename( $archive_path );
		$path      = dirname( $archive_path );

		// Header format: filename(255) + size(14) + mtime(12) + path(4096)
		$header = pack( 'a255', $filename );
		$header .= pack( 'a14', $file_size );
		$header .= pack( 'a12', $mtime );
		$header .= pack( 'a4096', $path );

		return $header;
	}

	/**
	 * Finalize archive
	 *
	 * @return void
	 */
	public function finalize() {
		// Write end of file marker
		$eof = pack( 'a4377', '' );
		@fwrite( $this->file_handle, $eof );
		$this->close();
	}
}

