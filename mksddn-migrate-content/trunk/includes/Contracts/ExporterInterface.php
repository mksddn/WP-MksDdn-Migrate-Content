<?php
/**
 * @file: ExporterInterface.php
 * @description: Contract for export operations
 * @dependencies: Selection\ContentSelection
 * @created: 2024-01-01
 */

namespace MksDdn\MigrateContent\Contracts;

use MksDdn\MigrateContent\Selection\ContentSelection;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Contract for export operations.
 *
 * @since 1.0.0
 */
interface ExporterInterface {

	/**
	 * Export content based on selection.
	 *
	 * @param ContentSelection $selection Content selection.
	 * @param string           $format    Export format (archive|json).
	 * @return void
	 * @since 1.0.0
	 */
	public function export_selected_content( ContentSelection $selection, string $format = 'archive' ): void;
}

