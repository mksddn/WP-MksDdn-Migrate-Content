<?php
/**
 * Registers chunking services.
 *
 * @package MksDdn_Migrate_Content
 */

namespace Mksddn_MC\Chunking;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ChunkServiceProvider {

	public static function init(): void {
		static $booted = false;

		if ( $booted ) {
			return;
		}

		$booted = true;
		new ChunkRestController();
	}
}

