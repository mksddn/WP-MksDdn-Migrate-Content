<?php
/**
 * Unit tests for ViewRenderer.
 *
 * @package MksDdn_Migrate_Content
 */

declare(strict_types=1);

namespace MksDdn\MigrateContent\Tests\Unit\Core;

use MksDdn\MigrateContent\Core\View\ViewRenderer;
use MksDdn\MigrateContent\Tests\Support\ArchiveFixtureBuilder;
use WP_Mock\Tools\TestCase;

/**
 * Template existence and escaped missing-template output.
 */
final class ViewRendererTest extends TestCase {

	/** @var string */
	private string $views;

	public function setUp(): void {
		parent::setUp();
		$this->views = sys_get_temp_dir() . '/mksddn-mc-views-' . uniqid( '', true );
		mkdir( $this->views . '/admin', 0777, true );
		file_put_contents(
			$this->views . '/admin/probe.php',
			'<?php echo esc_html( $label ?? "" ); ?>'
		);
	}

	public function tearDown(): void {
		ArchiveFixtureBuilder::rrmdir( $this->views );
		parent::tearDown();
	}

	public function test__render_string__includes_template_and_escapes_missing(): void {
		$renderer = new ViewRenderer( trailingslashit( $this->views ) );

		self::assertTrue( $renderer->template_exists( 'admin/probe.php' ) );
		self::assertSame( 'Hello &amp; Co', $renderer->render_string( 'admin/probe.php', array( 'label' => 'Hello & Co' ) ) );

		self::assertFalse( $renderer->template_exists( 'admin/missing.php' ) );
		$missing = $renderer->render_string( 'admin/missing.php' );
		self::assertStringContainsString( 'notice-error', $missing );
		self::assertStringContainsString( 'admin/missing.php', $missing );
	}
}
