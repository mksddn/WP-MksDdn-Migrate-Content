<?php
/**
 * @file: EnvironmentVersionComparator.php
 * @description: Compares source (archive) vs target PHP/WordPress versions for import preflight warnings
 * @dependencies: none
 * @created: 2026-10-04
 */

namespace MksDdn\MigrateContent\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds direction-aware version mismatch warnings (major.minor comparison).
 */
class EnvironmentVersionComparator {

	/**
	 * Compare archive manifest versions against the current site.
	 *
	 * Patch-level differences (e.g. 8.2.10 vs 8.2.28) do not produce warnings.
	 * Missing or unparseable versions are skipped without warnings.
	 *
	 * @param array $manifest Archive manifest (may contain php_version / wp_version).
	 * @return string[] Translated warning messages (0–2 items).
	 */
	public function build_warnings( array $manifest ): array {
		$source_php = isset( $manifest['php_version'] ) ? trim( (string) $manifest['php_version'] ) : '';
		$source_wp  = isset( $manifest['wp_version'] ) ? trim( (string) $manifest['wp_version'] ) : '';
		$target_php = PHP_VERSION;
		$target_wp  = isset( $GLOBALS['wp_version'] ) ? (string) $GLOBALS['wp_version'] : '';

		$warnings = array();

		$php_warning = $this->compare_pair(
			'php',
			$source_php,
			$target_php
		);
		if ( '' !== $php_warning ) {
			$warnings[] = $php_warning;
		}

		$wp_warning = $this->compare_pair(
			'wp',
			$source_wp,
			$target_wp
		);
		if ( '' !== $wp_warning ) {
			$warnings[] = $wp_warning;
		}

		return $warnings;
	}

	/**
	 * Extract source/target version strings for preflight summary display.
	 *
	 * @param array $manifest Archive manifest.
	 * @return array{source_php_version:string,source_wp_version:string,target_php_version:string,target_wp_version:string}
	 */
	public function summarize( array $manifest ): array {
		return array(
			'source_php_version' => isset( $manifest['php_version'] ) ? trim( (string) $manifest['php_version'] ) : '',
			'source_wp_version'  => isset( $manifest['wp_version'] ) ? trim( (string) $manifest['wp_version'] ) : '',
			'target_php_version' => PHP_VERSION,
			'target_wp_version'  => isset( $GLOBALS['wp_version'] ) ? (string) $GLOBALS['wp_version'] : '',
		);
	}

	/**
	 * Compare one version pair and return a warning or empty string.
	 *
	 * @param string $kind        'php' or 'wp'.
	 * @param string $source_full Full source version string.
	 * @param string $target_full Full target version string.
	 * @return string
	 */
	private function compare_pair( string $kind, string $source_full, string $target_full ): string {
		$source_mm = $this->major_minor( $source_full );
		$target_mm = $this->major_minor( $target_full );

		if ( '' === $source_mm || '' === $target_mm ) {
			return '';
		}

		$cmp = version_compare( $source_mm, $target_mm );
		if ( 0 === $cmp ) {
			return '';
		}

		$source_display = '' !== $source_full ? $source_full : $source_mm;
		$target_display = '' !== $target_full ? $target_full : $target_mm;

		if ( 'php' === $kind ) {
			if ( $cmp > 0 ) {
				return sprintf(
					/* translators: 1: PHP version in archive, 2: PHP version on this site */
					__( 'PHP in the archive is newer than on this site (%1$s vs %2$s). Import may fail or behave differently.', 'mksddn-migrate-content' ),
					$source_display,
					$target_display
				);
			}

			return sprintf(
				/* translators: 1: PHP version in archive, 2: PHP version on this site */
				__( 'PHP in the archive is older than on this site (%1$s vs %2$s). Review compatibility before importing.', 'mksddn-migrate-content' ),
				$source_display,
				$target_display
			);
		}

		if ( $cmp > 0 ) {
			return sprintf(
				/* translators: 1: WordPress version in archive, 2: WordPress version on this site */
				__( 'WordPress in the archive is newer than on this site (%1$s vs %2$s). Import may fail or behave differently.', 'mksddn-migrate-content' ),
				$source_display,
				$target_display
			);
		}

		return sprintf(
			/* translators: 1: WordPress version in archive, 2: WordPress version on this site */
			__( 'WordPress in the archive is older than on this site (%1$s vs %2$s). Review compatibility before importing.', 'mksddn-migrate-content' ),
			$source_display,
			$target_display
		);
	}

	/**
	 * Normalize a version string to major.minor for comparison.
	 *
	 * @param string $version Raw version (e.g. 8.2.28, 6.7.1-alpha).
	 * @return string major.minor or empty when unparseable.
	 */
	private function major_minor( string $version ): string {
		$version = trim( $version );
		if ( '' === $version ) {
			return '';
		}

		if ( ! preg_match( '/(\d+)\.(\d+)/', $version, $matches ) ) {
			return '';
		}

		return (string) (int) $matches[1] . '.' . (string) (int) $matches[2];
	}
}
