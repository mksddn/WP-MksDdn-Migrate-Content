<?php
/**
 * Base archiver class
 *
 * @package MksDdn_Migrate_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access not allowed' );
}

/**
 * Base archiver abstract class
 */
abstract class MksDdn_MC_Archiver {

	/**
	 * Archive file path
	 *
	 * @var string
	 */
	protected $file_name;

	/**
	 * File handle
	 *
	 * @var resource
	 */
	protected $file_handle;

	/**
	 * Constructor
	 *
	 * @param string $file_name Archive file path
	 * @param bool   $write     Write mode
	 */
	public function __construct( $file_name, $write = false ) {
		$this->file_name = $file_name;

		if ( $write ) {
			$this->file_handle = @fopen( $file_name, 'cb' );
			if ( false === $this->file_handle ) {
				throw new Exception( sprintf( __( 'Could not open file for writing. File: %s', 'mksddn-migrate-content' ), $file_name ) );
			}
			@fseek( $this->file_handle, 0, SEEK_END );
		} else {
			$this->file_handle = @fopen( $file_name, 'rb' );
			if ( false === $this->file_handle ) {
				throw new Exception( sprintf( __( 'Could not open file for reading. File: %s', 'mksddn-migrate-content' ), $file_name ) );
			}
		}
	}

	/**
	 * Set file pointer
	 *
	 * @param int $offset Offset
	 * @return void
	 */
	public function set_file_pointer( $offset ) {
		@fseek( $this->file_handle, $offset, SEEK_SET );
	}

	/**
	 * Get file pointer
	 *
	 * @return int|false
	 */
	public function get_file_pointer() {
		return @ftell( $this->file_handle );
	}

	/**
	 * Close archive
	 *
	 * @return void
	 */
	public function close() {
		if ( is_resource( $this->file_handle ) ) {
			@fclose( $this->file_handle );
		}
	}

	/**
	 * Replace forward slash with directory separator
	 *
	 * @param string $path Path
	 * @return string
	 */
	protected function replace_forward_slash_with_directory_separator( $path ) {
		return str_replace( '/', DIRECTORY_SEPARATOR, $path );
	}

	/**
	 * Replace directory separator with forward slash
	 *
	 * @param string $path Path
	 * @return string
	 */
	protected function replace_directory_separator_with_forward_slash( $path ) {
		return str_replace( DIRECTORY_SEPARATOR, '/', $path );
	}
}

