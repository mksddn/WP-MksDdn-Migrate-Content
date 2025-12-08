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

if (php_sapi_name() !== 'cli') {
	exit(0);
}

$source_dir = $argv[1] ?? dirname(__DIR__);
$output_file = $argv[2] ?? $source_dir . '/languages/mksddn-migrate-content.pot';
$text_domain = $argv[3] ?? 'mksddn-migrate-content';

if (!is_dir($source_dir)) {
	fwrite(STDERR, "Source directory not found: {$source_dir}\n");
	exit(1);
}

$functions = [
	'__'            => 'simple',
	'_e'            => 'simple',
	'_n'            => 'plural',
	'_nx'           => 'plural_context',
	'_x'            => 'context',
	'_ex'           => 'context',
	'esc_html__'    => 'simple',
	'esc_html_e'    => 'simple',
	'esc_attr__'    => 'simple',
	'esc_attr_e'    => 'simple',
	'esc_html_x'    => 'context',
	'esc_attr_x'    => 'context',
	'_n_noop'       => 'plural',
	'_nx_noop'      => 'plural_context',
];

$entries = [];

$iterator = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator(
		$source_dir,
		RecursiveDirectoryIterator::SKIP_DOTS
	)
);

foreach ($iterator as $file_info) {
	if ($file_info->isDir()) {
		continue;
	}

	$extension = strtolower($file_info->getExtension());
	if ($extension !== 'php' && $extension !== 'inc') {
		continue;
	}

	$relative_path = ltrim(str_replace($source_dir, '', $file_info->getPathname()), '/\\');
	$contents = file_get_contents($file_info->getPathname());
	if ($contents === false) {
		continue;
	}

	$tokens = token_get_all($contents);
	$total = count($tokens);
	for ($index = 0; $index < $total; $index++) {
		$token = $tokens[$index];
		if (!is_array($token) || $token[0] !== T_STRING) {
			continue;
		}

		$function_name = $token[1];
		if (!isset($functions[$function_name])) {
			continue;
		}

		$next = $index + 1;
		while ($next < $total && is_array($tokens[$next]) && $tokens[$next][0] === T_WHITESPACE) {
			$next++;
		}

		if ($next >= $total || $tokens[$next] !== '(') {
			continue;
		}

		[$argument_tokens, $closing_index] = capture_argument_tokens($tokens, $next);
		$index = $closing_index;
		if (empty($argument_tokens)) {
			continue;
		}

		$args = split_arguments($argument_tokens);
		if (empty($args)) {
			continue;
		}

		$type = $functions[$function_name];
		$line = $token[2];
		switch ($type) {
			case 'simple':
				$msgid = tokens_to_string($args[0]);
				if ($msgid === null) {
					break;
				}
				add_entry($entries, $relative_path, $line, $msgid);
				break;

			case 'context':
				$msgid = tokens_to_string($args[0] ?? []);
				$context = tokens_to_string($args[1] ?? []);
				if ($msgid === null || $context === null) {
					break;
				}
				add_entry($entries, $relative_path, $line, $msgid, null, $context);
				break;

			case 'plural':
				$singular = tokens_to_string($args[0] ?? []);
				$plural = tokens_to_string($args[1] ?? []);
				if ($singular === null || $plural === null) {
					break;
				}
				add_entry($entries, $relative_path, $line, $singular, $plural);
				break;

			case 'plural_context':
				$singular = tokens_to_string($args[0] ?? []);
				$plural = tokens_to_string($args[1] ?? []);
				$context = tokens_to_string($args[2] ?? []);
				if ($singular === null || $plural === null || $context === null) {
					break;
				}
				add_entry($entries, $relative_path, $line, $singular, $plural, $context);
				break;
		}
	}
}

ksort($entries);

$header = build_header($text_domain);
$buffer = $header;

foreach ($entries as $entry) {
	$buffer .= "\n";
	if (!empty($entry['references'])) {
		$buffer .= '#: ' . implode(' ', array_unique($entry['references'])) . "\n";
	}

	if ($entry['context'] !== null) {
		$buffer .= 'msgctxt "' . escape_po($entry['context']) . "\"\n";
	}

	$buffer .= 'msgid "' . escape_po($entry['singular']) . "\"\n";

	if ($entry['plural'] !== null) {
		$buffer .= 'msgid_plural "' . escape_po($entry['plural']) . "\"\n";
		$buffer .= "msgstr[0] \"\"\n";
		$buffer .= "msgstr[1] \"\"\n";
	} else {
		$buffer .= "msgstr \"\"\n";
	}
}

file_put_contents($output_file, rtrim($buffer) . "\n");

exit(0);

/**
 * Capture argument tokens for a function call.
 *
 * @param array $tokens Token list.
 * @param int   $start  Index pointing at opening parenthesis.
 *
 * @return array
 */
function capture_argument_tokens(array $tokens, int $start): array {
	$buffer = [];
	$depth = 0;
	$total = count($tokens);

	for ($i = $start; $i < $total; $i++) {
		$token = $tokens[$i];
		if ($token === '(') {
			$depth++;
			if ($depth === 1) {
				continue;
			}
		}

		if ($token === ')') {
			$depth--;
			if ($depth === 0) {
				return [$buffer, $i];
			}
		}

		if ($depth >= 1) {
			$buffer[] = $token;
		}
	}

	return [$buffer, $total - 1];
}

/**
 * Split arguments by top-level commas.
 *
 * @param array $tokens
 *
 * @return array
 */
function split_arguments(array $tokens): array {
	$args = [];
	$current = [];
	$depth = 0;

	foreach ($tokens as $token) {
		if ($token === '(') {
			$depth++;
			$current[] = $token;
			continue;
		}

		if ($token === ')') {
			$depth--;
			$current[] = $token;
			continue;
		}

		if ($token === ',' && $depth === 0) {
			$args[] = $current;
			$current = [];
			continue;
		}

		$current[] = $token;
	}

	if (!empty($current)) {
		$args[] = $current;
	}

	return $args;
}

/**
 * Convert token array to a plain string if it is a concatenation of literals.
 *
 * @param array $tokens
 *
 * @return string|null
 */
function tokens_to_string(array $tokens): ?string {
	$buffer = '';
	$expect_concat = false;

	foreach ($tokens as $token) {
		if (is_array($token) && $token[0] === T_WHITESPACE) {
			continue;
		}

		if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
			$buffer .= parse_literal($token[1]);
			$expect_concat = true;
			continue;
		}

		if ($token === '.' && $expect_concat) {
			$expect_concat = false;
			continue;
		}

		return null;
	}

	return $buffer === '' ? null : $buffer;
}

/**
 * Strip quotes and escape sequences.
 *
 * @param string $literal
 *
 * @return string
 */
function parse_literal(string $literal): string {
	$quote = substr($literal, 0, 1);
	$value = substr($literal, 1, -1);

	if ($quote === '"') {
		$value = stripcslashes($value);
	} else {
		$value = str_replace(["\\\\", "\\'"], ["\\", "'"], $value);
	}

	return $value;
}

/**
 * Store translation entry.
 *
 * @param array  $entries
 * @param string $path
 * @param int    $line
 * @param string $singular
 * @param string|null $plural
 * @param string|null $context
 *
 * @return void
 */
function add_entry(array &$entries, string $path, int $line, string $singular, ?string $plural = null, ?string $context = null): void {
	$key = md5($context . "\x04" . $singular . "\x00" . ($plural ?? ''));
	if (!isset($entries[$key])) {
		$entries[$key] = [
			'singular'   => $singular,
			'plural'     => $plural,
			'context'    => $context,
			'references' => [],
		];
	}

	$entries[$key]['references'][] = "{$path}:{$line}";
}

/**
 * Escape strings for PO format.
 *
 * @param string $text
 *
 * @return string
 */
function escape_po(string $text): string {
	$replace = [
		"\r" => '',
		"\n" => "\\n",
		"\t" => "\\t",
		'"'  => '\\"',
	];

	return strtr($text, $replace);
}

/**
 * Build header template.
 *
 * @param string $domain
 *
 * @return string
 */
function build_header(string $domain): string {
	$date = gmdate('Y-m-d H:i+0000');

	$lines = [
		'#, fuzzy',
		'msgid ""',
		'msgstr ""',
		'"Project-Id-Version: MksDdn Migrate Content 1.0.0\n"',
		'"Report-Msgid-Bugs-To: https://github.com/mksddn/WP-MksDdn-Migrate-Content/issues\n"',
		'"POT-Creation-Date: ' . $date . '\n"',
		'"MIME-Version: 1.0\n"',
		'"Content-Type: text/plain; charset=UTF-8\n"',
		'"Content-Transfer-Encoding: 8bit\n"',
		'"X-Domain: ' . $domain . '\n"',
		'',
	];

	return implode("\n", $lines);
}

