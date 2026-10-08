<?php
/**
 * Unit tests for typed plugin exceptions.
 *
 * @package MksDdn_Migrate_Content
 */

declare(strict_types=1);

namespace MksDdn\MigrateContent\Tests\Unit\Exceptions;

use MksDdn\MigrateContent\Exceptions\DatabaseOperationException;
use MksDdn\MigrateContent\Exceptions\ExportException;
use MksDdn\MigrateContent\Exceptions\FileOperationException;
use MksDdn\MigrateContent\Exceptions\ImportException;
use MksDdn\MigrateContent\Exceptions\ValidationException;
use WP_Mock\Tools\TestCase;

/**
 * Exception classes are instantiable.
 */
final class ExceptionsTest extends TestCase {

	public function test__exceptions__extend_exception(): void {
		$classes = array(
			ValidationException::class,
			FileOperationException::class,
			DatabaseOperationException::class,
			ImportException::class,
			ExportException::class,
		);

		foreach ( $classes as $class ) {
			$e = new $class( 'boom' );
			self::assertInstanceOf( \Exception::class, $e );
			self::assertSame( 'boom', $e->getMessage() );
		}
	}
}
