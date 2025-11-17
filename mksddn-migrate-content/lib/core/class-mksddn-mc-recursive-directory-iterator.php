<?php
/**
 * Recursive directory iterator
 *
 * @package MksDdn_Migrate_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access not allowed' );
}

/**
 * Recursive directory iterator class
 */
class MksDdn_MC_Recursive_Directory_Iterator extends RecursiveDirectoryIterator {

	/**
	 * Constructor
	 *
	 * @param string $path Directory path
	 * @param int $flags Flags
	 */
	public function __construct( $path, $flags = RecursiveDirectoryIterator::SKIP_DOTS ) {
		parent::__construct( $path, $flags );
	}
}

