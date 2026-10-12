<?php
/**
 * Unit tests for ErrorHandler user-facing messages.
 *
 * @package MksDdn_Migrate_Content
 */

declare(strict_types=1);

namespace MksDdn\MigrateContent\Tests\Unit\Services;

use MksDdn\MigrateContent\Exceptions\ExportException;
use MksDdn\MigrateContent\Exceptions\ValidationException;
use MksDdn\MigrateContent\Services\ErrorHandler;
use WP_Error;
use WP_Mock\Tools\TestCase;

/**
 * Maps known error codes and exception classes to stable user strings.
 */
final class ErrorHandlerTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
		if ( ! defined( 'MKSDDN_MC_DISABLE_LOGGING' ) ) {
			define( 'MKSDDN_MC_DISABLE_LOGGING', true );
		}
	}

	public function test__get_user_message__maps_known_codes(): void {
		$handler = new ErrorHandler();
		$message = $handler->get_user_message( new WP_Error( 'mksddn_mc_file_missing', 'raw' ) );
		self::assertSame( 'No file uploaded.', $message );

		$unknown = $handler->get_user_message( new WP_Error( 'custom_code', 'Custom detail' ) );
		self::assertSame( 'Custom detail', $unknown );
	}

	public function test__get_exception_message__maps_known_classes(): void {
		$handler = new ErrorHandler();
		$mapped  = $handler->get_exception_message( new ValidationException( 'bad field' ) );
		self::assertStringContainsString( 'Validation failed', $mapped );
		self::assertStringContainsString( 'bad field', $mapped );

		$export = $handler->get_exception_message( new ExportException( 'disk full' ) );
		self::assertStringContainsString( 'Export operation failed', $export );
	}

}
