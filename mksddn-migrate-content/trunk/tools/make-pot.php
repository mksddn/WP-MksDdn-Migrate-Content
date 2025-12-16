#!/usr/bin/env php
<?php
/**
 * Lightweight gettext extractor for MksDdn Migrate Content.
 *
 * Generates a .pot file without relying on wp-cli, so localization
 * can be refreshed inside constrained environments (CI, air-gapped dev boxes).
 *
 * Usage:
 *   php tools/make-pot.php [sourceDir] [outputFile] [textDomain]
 */

namespace MksDdn\MigrateContent\Tools;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

call_user_func(
	static function (): void {
		if ( php_sapi_name() !== 'cli' ) {
			exit( 0 );
		}

		$source_dir  = $GLOBALS['argv'][1] ?? dirname( __DIR__ );
		$output_file = $GLOBALS['argv'][2] ?? $source_dir . '/languages/mksddn-migrate-content.pot';
		$text_domain = $GLOBALS['argv'][3] ?? 'mksddn-migrate-content';

		if ( ! is_dir( $source_dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI helper cannot bootstrap WP_Filesystem.
			fwrite( STDERR, "Source directory not found: {$source_dir}\n" );
			exit( 1 );
		}

		$generator = new PotGenerator( $source_dir, $output_file, $text_domain );
		$generator->run();
	}
);

/**
 * Standalone POT generator (WP-independent).
 */
final class PotGenerator {

	private string $sourceDir;

	private string $outputFile;

	private string $textDomain;

	/**
	 * Map of gettext helpers and their types.
	 *
	 * @var array<string,string>
	 */
	private array $functionMap = array(
		'__'         => 'simple',
		'_e'         => 'simple',
		'_n'         => 'plural',
		'_nx'        => 'plural_context',
		'_x'         => 'context',
		'_ex'        => 'context',
		'esc_html__' => 'simple',
		'esc_html_e' => 'simple',
		'esc_attr__' => 'simple',
		'esc_attr_e' => 'simple',
		'esc_html_x' => 'context',
		'esc_attr_x' => 'context',
		'_n_noop'    => 'plural',
		'_nx_noop'   => 'plural_context',
	);

	/**
	 * Collected translation entries.
	 *
	 * @var array<string,array>
	 */
	private array $entries = array();

	public function __construct( string $sourceDir, string $outputFile, string $textDomain ) {
		$this->sourceDir  = rtrim( $sourceDir, '/\\' );
		$this->outputFile = $outputFile;
		$this->textDomain = $textDomain;
	}

	public function run(): void {
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator(
				$this->sourceDir,
				RecursiveDirectoryIterator::SKIP_DOTS
			)
		);

		foreach ( $iterator as $fileInfo ) {
			$this->scanFile( $fileInfo );
		}

		ksort( $this->entries );

		$buffer = $this->buildHeader();

		foreach ( $this->entries as $entry ) {
			$buffer .= "\n";

			if ( ! empty( $entry['references'] ) ) {
				$buffer .= '#: ' . implode( ' ', array_unique( $entry['references'] ) ) . "\n";
			}

			if ( null !== $entry['context'] ) {
				$buffer .= 'msgctxt "' . $this->escapePo( $entry['context'] ) . "\"\n";
			}

			$buffer .= 'msgid "' . $this->escapePo( $entry['singular'] ) . "\"\n";

			if ( null !== $entry['plural'] ) {
				$buffer .= 'msgid_plural "' . $this->escapePo( $entry['plural'] ) . "\"\n";
				$buffer .= "msgstr[0] \"\"\n";
				$buffer .= "msgstr[1] \"\"\n";
			} else {
				$buffer .= "msgstr \"\"\n";
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- runs before WP bootstrap.
		file_put_contents( $this->outputFile, rtrim( $buffer ) . "\n" );
	}

	private function scanFile( SplFileInfo $fileInfo ): void {
		if ( $fileInfo->isDir() ) {
			return;
		}

		$extension = strtolower( $fileInfo->getExtension() );
		if ( 'php' !== $extension && 'inc' !== $extension ) {
			return;
		}

		$contents = file_get_contents( $fileInfo->getPathname() );
		if ( false === $contents ) {
			return;
		}

		$relative = ltrim( str_replace( $this->sourceDir, '', $fileInfo->getPathname() ), '/\\' );
		$tokens   = token_get_all( $contents );
		$total    = count( $tokens );

		for ( $index = 0; $index < $total; $index++ ) {
			$token = $tokens[ $index ];
			if ( ! is_array( $token ) || T_STRING !== $token[0] ) {
				continue;
			}

			$functionName = $token[1];
			if ( ! isset( $this->functionMap[ $functionName ] ) ) {
				continue;
			}

			$next = $index + 1;
			while ( $next < $total && is_array( $tokens[ $next ] ) && T_WHITESPACE === $tokens[ $next ][0] ) {
				$next++;
			}

			if ( $next >= $total || '(' !== $tokens[ $next ] ) {
				continue;
			}

			list( $argumentTokens, $closingIndex ) = $this->captureArgumentTokens( $tokens, $next );
			$index = $closingIndex;

			if ( empty( $argumentTokens ) ) {
				continue;
			}

			$args = $this->splitArguments( $argumentTokens );
			if ( empty( $args ) ) {
				continue;
			}

			$line = (int) $token[2];
			$type = $this->functionMap[ $functionName ];

			switch ( $type ) {
				case 'simple':
					$msgid = $this->tokensToString( $args[0] );
					if ( null !== $msgid ) {
						$this->addEntry( $relative, $line, $msgid );
					}
					break;

				case 'context':
					$msgid   = $this->tokensToString( $args[0] ?? array() );
					$context = $this->tokensToString( $args[1] ?? array() );
					if ( null !== $msgid && null !== $context ) {
						$this->addEntry( $relative, $line, $msgid, null, $context );
					}
					break;

				case 'plural':
					$singular = $this->tokensToString( $args[0] ?? array() );
					$plural   = $this->tokensToString( $args[1] ?? array() );
					if ( null !== $singular && null !== $plural ) {
						$this->addEntry( $relative, $line, $singular, $plural );
					}
					break;

				case 'plural_context':
					$singular = $this->tokensToString( $args[0] ?? array() );
					$plural   = $this->tokensToString( $args[1] ?? array() );
					$context  = $this->tokensToString( $args[2] ?? array() );
					if ( null !== $singular && null !== $plural && null !== $context ) {
						$this->addEntry( $relative, $line, $singular, $plural, $context );
					}
					break;
			}
		}
	}

	/**
	 * Capture tokens for all arguments starting from "("
	 *
	 * @param array<int,mixed> $tokens Token list.
	 * @param int              $start  Index of opening parenthesis.
	 *
	 * @return array{0:array,1:int}
	 */
	private function captureArgumentTokens( array $tokens, int $start ): array {
		$buffer = array();
		$depth  = 0;
		$total  = count( $tokens );

		for ( $i = $start; $i < $total; $i++ ) {
			$token = $tokens[ $i ];

			if ( '(' === $token ) {
				$depth++;
				if ( 1 === $depth ) {
					continue;
				}
			}

			if ( ')' === $token ) {
				$depth--;
				if ( 0 === $depth ) {
					return array( $buffer, $i );
				}
			}

			if ( $depth >= 1 ) {
				$buffer[] = $token;
			}
		}

		return array( $buffer, $total - 1 );
	}

	/**
	 * Split argument tokens by commas.
	 *
	 * @param array<int,mixed> $tokens Tokens inside parentheses.
	 *
	 * @return array<int,array>
	 */
	private function splitArguments( array $tokens ): array {
		$args    = array();
		$current = array();
		$depth   = 0;

		foreach ( $tokens as $token ) {
			if ( '(' === $token ) {
				$depth++;
				$current[] = $token;
				continue;
			}

			if ( ')' === $token ) {
				$depth--;
				$current[] = $token;
				continue;
			}

			if ( ',' === $token && 0 === $depth ) {
				$args[]  = $current;
				$current = array();
				continue;
			}

			$current[] = $token;
		}

		if ( ! empty( $current ) ) {
			$args[] = $current;
		}

		return $args;
	}

	/**
	 * Convert token sequence to string if literal.
	 *
	 * @param array<int,mixed> $tokens Token list.
	 */
	private function tokensToString( array $tokens ): ?string {
		$buffer       = '';
		$expectConcat = false;

		foreach ( $tokens as $token ) {
			if ( is_array( $token ) && T_WHITESPACE === $token[0] ) {
				continue;
			}

			if ( is_array( $token ) && T_CONSTANT_ENCAPSED_STRING === $token[0] ) {
				$buffer      .= $this->parseLiteral( $token[1] );
				$expectConcat = true;
				continue;
			}

			if ( '.' === $token && $expectConcat ) {
				$expectConcat = false;
				continue;
			}

			return null;
		}

		return '' === $buffer ? null : $buffer;
	}

	/**
	 * Decode PHP literal.
	 */
	private function parseLiteral( string $literal ): string {
		$quote = substr( $literal, 0, 1 );
		$value = substr( $literal, 1, -1 );

		if ( '"' === $quote ) {
			$value = stripcslashes( $value );
		} else {
			$value = str_replace( array( '\\', "\\'" ), array( '\\', "'" ), $value );
		}

		return $value;
	}

	/**
	 * Store entry in map.
	 */
	private function addEntry( string $path, int $line, string $singular, ?string $plural = null, ?string $context = null ): void {
		$key = md5( $context . "\x04" . $singular . "\x00" . ( $plural ?? '' ) );

		if ( ! isset( $this->entries[ $key ] ) ) {
			$this->entries[ $key ] = array(
				'singular'   => $singular,
				'plural'     => $plural,
				'context'    => $context,
				'references' => array(),
			);
		}

		$this->entries[ $key ]['references'][] = $path . ':' . $line;
	}

	/**
	 * Escape string for PO format.
	 */
	private function escapePo( string $text ): string {
		return strtr(
			$text,
			array(
				"\r" => '',
				"\n" => "\\n",
				"\t" => "\\t",
				'"'   => '\\"',
			)
		);
	}

	/**
	 * Build header block.
	 */
	private function buildHeader(): string {
		$date = gmdate( 'Y-m-d H:i+0000' );

		$lines = array(
			'#, fuzzy',
			'msgid ""',
			'msgstr ""',
			'"Project-Id-Version: MksDdn Migrate Content\\n"',
			'"Report-Msgid-Bugs-To: https://github.com/mksddn/WP-MksDdn-Migrate-Content/issues\\n"',
			'"POT-Creation-Date: ' . $date . '\\n"',
			'"MIME-Version: 1.0\\n"',
			'"Content-Type: text/plain; charset=UTF-8\\n"',
			'"Content-Transfer-Encoding: 8bit\\n"',
			'"X-Domain: ' . $this->textDomain . '\\n"',
			'',
		);

		return implode( "\n", $lines );
	}
}
