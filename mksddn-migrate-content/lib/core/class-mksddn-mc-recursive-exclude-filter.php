<?php
/**
 * Recursive exclude filter
 *
 * @package MksDdn_Migrate_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access not allowed' );
}

/**
 * Recursive exclude filter class
 */
class MksDdn_MC_Recursive_Exclude_Filter extends RecursiveFilterIterator {

	/**
	 * Excluded paths
	 *
	 * @var array
	 */
	protected $excluded_paths = array();

	/**
	 * Constructor
	 *
	 * @param RecursiveIterator $iterator Iterator
	 * @param array $excluded_paths Excluded paths
	 */
	public function __construct( RecursiveIterator $iterator, $excluded_paths = array() ) {
		parent::__construct( $iterator );
		$this->excluded_paths = $excluded_paths;
	}

	/**
	 * Check if current item should be accepted
	 *
	 * @return bool
	 */
	#[\ReturnTypeWillChange]
	public function accept() {
		$path = $this->getInnerIterator()->getPathname();

		foreach ( $this->excluded_paths as $excluded_path ) {
			if ( strpos( $path, $excluded_path ) !== false ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Get children
	 *
	 * @return RecursiveFilterIterator|null
	 */
	#[\ReturnTypeWillChange]
	public function getChildren() {
		return new self( $this->getInnerIterator()->getChildren(), $this->excluded_paths );
	}
}

