<?php
/**
 * Recursive iterator iterator wrapper
 *
 * @package MksDdn_Migrate_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access not allowed' );
}

/**
 * Recursive iterator iterator class
 */
class MksDdn_MC_Recursive_Iterator_Iterator extends RecursiveIteratorIterator {

	/**
	 * Constructor
	 *
	 * @param RecursiveIterator $iterator Iterator
	 * @param int $mode Mode
	 * @param int $flags Flags
	 */
	public function __construct( RecursiveIterator $iterator, $mode = RecursiveIteratorIterator::LEAVES_ONLY, $flags = 0 ) {
		parent::__construct( $iterator, $mode, $flags );
	}
}

