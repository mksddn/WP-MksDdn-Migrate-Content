<?php
/**
 * Recursive extension filter
 *
 * @package MksDdn_Migrate_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access not allowed' );
}

/**
 * Recursive extension filter class
 */
class MksDdn_MC_Recursive_Extension_Filter extends RecursiveFilterIterator {

	/**
	 * Allowed extensions
	 *
	 * @var array
	 */
	protected $allowed_extensions = array();

	/**
	 * Constructor
	 *
	 * @param RecursiveIterator $iterator Iterator
	 * @param array $allowed_extensions Allowed extensions
	 */
	public function __construct( RecursiveIterator $iterator, $allowed_extensions = array() ) {
		parent::__construct( $iterator );
		$this->allowed_extensions = array_map( 'strtolower', $allowed_extensions );
	}

	/**
	 * Check if current item should be accepted
	 *
	 * @return bool
	 */
	public function accept() {
		$file = $this->getInnerIterator()->current();

		if ( $file->isDir() ) {
			return true;
		}

		$extension = strtolower( $file->getExtension() );
		return in_array( $extension, $this->allowed_extensions, true );
	}

	/**
	 * Get children
	 *
	 * @return RecursiveFilterIterator
	 */
	public function getChildren() {
		return new self( $this->getInnerIterator()->getChildren(), $this->allowed_extensions );
	}
}

