<?php
/**
 * Extractor class
 *
 * @package MksDdn_Migrate_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access not allowed' );
}

/**
 * Extractor class for extracting archives
 */
class MksDdn_MC_Extractor extends MksDdn_MC_Archiver {

	/**
	 * Extract file from archive
	 *
	 * @param string $destination_path Destination path
	 * @return bool
	 */
	public function extract_file( $destination_path ) {
		$header = $this->read_file_header();
		if ( false === $header ) {
			return false;
		}

		$file_size = (int) trim( $header['size'] );
		if ( $file_size <= 0 ) {
			return false;
		}

		$directory = dirname( $destination_path );
		if ( ! is_dir( $directory ) ) {
			wp_mkdir_p( $directory );
		}

		$file_handle = @fopen( $destination_path, 'wb' );
		if ( ! $file_handle ) {
			return false;
		}

		$bytes_written = 0;
		$chunk_size    = 8192;

		while ( $bytes_written < $file_size ) {
			$remaining = $file_size - $bytes_written;
			$read_size = min( $chunk_size, $remaining );
			$chunk     = @fread( $this->file_handle, $read_size );

			if ( false === $chunk ) {
				@fclose( $file_handle );
				return false;
			}

			@fwrite( $file_handle, $chunk );
			$bytes_written += strlen( $chunk );
		}

		@fclose( $file_handle );

		if ( ! empty( $header['mtime'] ) ) {
			@touch( $destination_path, (int) trim( $header['mtime'] ) );
		}

		return true;
	}

	/**
	 * Read file header
	 *
	 * @return array|false
	 */
	private function read_file_header() {
		$header_data = @fread( $this->file_handle, 4377 );
		if ( false === $header_data || strlen( $header_data ) < 4377 ) {
			return false;
		}

		$header = unpack( 'a255filename/a14size/a12mtime/a4096path', $header_data );

		// Check for end of file marker
		if ( trim( $header['filename'] ) === '' && trim( $header['size'] ) === '' ) {
			return false;
		}

		return $header;
	}

	/**
	 * List files in archive
	 *
	 * @return array
	 */
	public function list_files() {
		$files = array();
		$offset = 0;

		$this->set_file_pointer( $offset );

		while ( true ) {
			$current_offset = $this->get_file_pointer();
			$header = $this->read_file_header();

			if ( false === $header ) {
				break;
			}

			$file_size = (int) trim( $header['size'] );
			$path      = trim( $header['path'] );
			$filename  = trim( $header['filename'] );

			if ( ! empty( $path ) && $path !== '.' ) {
				$file_path = $path . '/' . $filename;
			} else {
				$file_path = $filename;
			}

			$files[] = array(
				'path'   => $file_path,
				'size'   => $file_size,
				'mtime'  => trim( $header['mtime'] ),
				'offset' => $current_offset,
			);

			// Skip file content
			$this->set_file_pointer( $current_offset + 4377 + $file_size );
		}

		return $files;
	}
}

