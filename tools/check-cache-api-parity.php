<?php
/**
 * Checks FOCUS object-cache API parity against a WordPress core source tree.
 *
 * @package FOCUS
 */

declare(strict_types=1);

$root       = dirname( __DIR__ );
$wp_version = getenv( 'WP_VERSION' ) ?: '7.0';
$wp_src_dir = getenv( 'WP_SOURCE_DIR' ) ?: null;
$wp_tests   = getenv( 'WP_TESTS_DIR' ) ?: null;

if ( ! $wp_src_dir && $wp_tests && is_dir( $wp_tests . '/src/wp-includes' ) ) {
	$wp_src_dir = $wp_tests . '/src';
}

$focus_file = $root . '/includes/object-cache.php';

try {
	$core_files = get_core_sources( $wp_version, $wp_src_dir );
} catch ( RuntimeException $exception ) {
	fwrite( STDERR, $exception->getMessage() . PHP_EOL );
	exit( 1 );
}

$focus_source = file_get_contents( $focus_file );
if ( false === $focus_source ) {
	fwrite( STDERR, "Unable to read {$focus_file}" . PHP_EOL );
	exit( 1 );
}

$focus_functions = parse_functions( $focus_source );
$focus_methods   = parse_class_methods( $focus_source, 'WP_Object_Cache' );

$core_functions = array();
foreach ( array( 'cache.php', 'cache-compat.php' ) as $file ) {
	$core_functions += parse_functions( $core_files[ $file ] );
}

$core_methods = parse_class_methods( $core_files['class-wp-object-cache.php'], 'WP_Object_Cache' );

$errors = array();

foreach ( $core_functions as $name => $core_function ) {
	if ( 0 !== strpos( $name, 'wp_cache_' ) ) {
		continue;
	}

	if ( ! isset( $focus_functions[ $name ] ) ) {
		$errors[] = "Missing global function {$name}().";
		continue;
	}

	$errors = array_merge(
		$errors,
		compare_signatures( "function {$name}()", $core_function, $focus_functions[ $name ], false )
	);
}

foreach ( $core_methods as $name => $core_method ) {
	if ( 'public' !== $core_method['visibility'] ) {
		continue;
	}

	if ( ! isset( $focus_methods[ $name ] ) ) {
		$errors[] = "Missing WP_Object_Cache::{$name}().";
		continue;
	}

	$errors = array_merge(
		$errors,
		compare_signatures( "WP_Object_Cache::{$name}()", $core_method, $focus_methods[ $name ], false )
	);
}

if ( $errors ) {
	fwrite( STDERR, "Object cache API parity check failed against WordPress {$wp_version}:" . PHP_EOL );
	foreach ( $errors as $error ) {
		fwrite( STDERR, " - {$error}" . PHP_EOL );
	}
	exit( 1 );
}

printf(
	"Object cache API parity check passed against WordPress %s: %d globals, %d public methods.%s",
	$wp_version,
	count( array_filter( array_keys( $core_functions ), static fn ( string $name ): bool => 0 === strpos( $name, 'wp_cache_' ) ) ),
	count( array_filter( $core_methods, static fn ( array $method ): bool => 'public' === $method['visibility'] ) ),
	PHP_EOL
);

/**
 * Gets WordPress core cache source files.
 *
 * @param string      $wp_version WordPress version or branch.
 * @param string|null $wp_src_dir Optional local WordPress src directory.
 * @return array<string,string>
 */
function get_core_sources( string $wp_version, ?string $wp_src_dir ): array {
	$files = array(
		'cache.php',
		'cache-compat.php',
		'class-wp-object-cache.php',
	);

	$sources = array();
	foreach ( $files as $file ) {
		if ( $wp_src_dir ) {
			$path = rtrim( $wp_src_dir, '/\\' ) . '/wp-includes/' . $file;
			if ( ! is_readable( $path ) ) {
				throw new RuntimeException( "Unable to read {$path}" );
			}

			$contents = file_get_contents( $path );
		} else {
			$url      = "https://raw.githubusercontent.com/WordPress/wordpress-develop/{$wp_version}/src/wp-includes/{$file}";
			$contents = file_get_contents( $url );
		}

		if ( false === $contents ) {
			throw new RuntimeException( "Unable to read WordPress core source for {$file}" );
		}

		$sources[ $file ] = $contents;
	}

	return $sources;
}

/**
 * Parses top-level functions from PHP source.
 *
 * @param string $source PHP source.
 * @return array<string,array>
 */
function parse_functions( string $source ): array {
	$functions = array();

	foreach ( token_get_all( $source ) as $index => $token ) {
		if ( ! is_array( $token ) || T_FUNCTION !== $token[0] ) {
			continue;
		}

		$name_token = next_named_token( token_get_all( $source ), $index + 1 );
		if ( ! $name_token || ! is_array( $name_token ) || T_STRING !== $name_token[0] ) {
			continue;
		}
	}

	preg_match_all(
		'/^(?:if\s*\([^{]+{\s*)?\s*function\s+(&\s*)?([A-Za-z_][A-Za-z0-9_]*)\s*\(([^)]*)\)\s*(?::\s*([^{;]+))?/m',
		$source,
		$matches,
		PREG_SET_ORDER
	);

	foreach ( $matches as $match ) {
		$functions[ $match[2] ] = array(
			'name'       => $match[2],
			'params'     => parse_params( $match[3] ),
			'returnType' => normalize_type( $match[4] ?? '' ),
			'visibility' => 'global',
		);
	}

	return $functions;
}

/**
 * Parses public/protected/private methods from a class body.
 *
 * @param string $source PHP source.
 * @param string $class_name Class name.
 * @return array<string,array>
 */
function parse_class_methods( string $source, string $class_name ): array {
	$methods = array();
	$body    = extract_class_body( $source, $class_name );

	if ( '' === $body ) {
		return $methods;
	}

	preg_match_all(
		'/^\s*(public|protected|private)?\s*(?:static\s+)?function\s+(&\s*)?([A-Za-z_][A-Za-z0-9_]*)\s*\(([^)]*)\)\s*(?::\s*([^{;]+))?/m',
		$body,
		$matches,
		PREG_SET_ORDER
	);

	foreach ( $matches as $match ) {
		$visibility             = $match[1] ?: 'public';
		$methods[ $match[3] ] = array(
			'name'       => $match[3],
			'params'     => parse_params( $match[4] ),
			'returnType' => normalize_type( $match[5] ?? '' ),
			'visibility' => $visibility,
		);
	}

	return $methods;
}

/**
 * Extracts class body source.
 *
 * @param string $source PHP source.
 * @param string $class_name Class name.
 * @return string Class body, or empty string.
 */
function extract_class_body( string $source, string $class_name ): string {
	$pattern = '/class\s+' . preg_quote( $class_name, '/' ) . '\b[^{]*{/m';
	if ( ! preg_match( $pattern, $source, $match, PREG_OFFSET_CAPTURE ) ) {
		return '';
	}

	$start = $match[0][1] + strlen( $match[0][0] );
	$depth = 1;
	$length = strlen( $source );

	for ( $i = $start; $i < $length; $i++ ) {
		if ( '{' === $source[ $i ] ) {
			++$depth;
		} elseif ( '}' === $source[ $i ] ) {
			--$depth;
			if ( 0 === $depth ) {
				return substr( $source, $start, $i - $start );
			}
		}
	}

	return '';
}

/**
 * Parses a parameter list.
 *
 * @param string $params_source Parameter list source.
 * @return array<int,array>
 */
function parse_params( string $params_source ): array {
	$params_source = trim( $params_source );
	if ( '' === $params_source ) {
		return array();
	}

	$params = array();
	foreach ( explode( ',', $params_source ) as $position => $param_source ) {
		$param_source = trim( $param_source );
		if ( '' === $param_source ) {
			continue;
		}

		$has_default = false !== strpos( $param_source, '=' );
		$before_default = trim( explode( '=', $param_source, 2 )[0] );
		$by_ref = false !== strpos( $before_default, '&' );
		$variadic = false !== strpos( $before_default, '...' );
		$clean = str_replace( array( '&', '...' ), '', $before_default );

		if ( ! preg_match( '/^(?:(.+?)\s+)?\$([A-Za-z_][A-Za-z0-9_]*)$/', trim( $clean ), $match ) ) {
			$name = 'param' . $position;
			$type = '';
		} else {
			$type = normalize_type( $match[1] ?? '' );
			$name = $match[2];
		}

		$params[] = array(
			'name'       => $name,
			'type'       => $type,
			'required'   => ! $has_default,
			'byRef'      => $by_ref,
			'variadic'   => $variadic,
		);
	}

	return $params;
}

/**
 * Normalizes a type declaration for comparison.
 *
 * @param string $type Type declaration.
 * @return string Normalized type.
 */
function normalize_type( string $type ): string {
	$type = trim( $type );
	$type = preg_replace( '/\s+/', '', $type );

	return strtolower( (string) $type );
}

/**
 * Compares two function or method signatures.
 *
 * @param string $label Label for errors.
 * @param array  $core Core signature.
 * @param array  $focus FOCUS signature.
 * @param bool   $allow_extra_required Whether extra required params are allowed.
 * @return string[] Error messages.
 */
function compare_signatures( string $label, array $core, array $focus, bool $allow_extra_required ): array {
	$errors = array();

	$core_required  = count( array_filter( $core['params'], static fn ( array $param ): bool => $param['required'] ) );
	$focus_required = count( array_filter( $focus['params'], static fn ( array $param ): bool => $param['required'] ) );

	if ( $focus_required > $core_required && ! $allow_extra_required ) {
		$errors[] = "{$label} requires {$focus_required} params; core requires {$core_required}.";
	}

	if ( count( $focus['params'] ) < count( $core['params'] ) ) {
		$errors[] = "{$label} accepts " . count( $focus['params'] ) . ' params; core accepts ' . count( $core['params'] ) . '.';
	}

	foreach ( $core['params'] as $index => $core_param ) {
		if ( ! isset( $focus['params'][ $index ] ) ) {
			continue;
		}

		$focus_param = $focus['params'][ $index ];
		if ( $core_param['byRef'] !== $focus_param['byRef'] ) {
			$errors[] = "{$label} parameter \${$core_param['name']} by-reference mismatch.";
		}

		if ( $core_param['variadic'] !== $focus_param['variadic'] ) {
			$errors[] = "{$label} parameter \${$core_param['name']} variadic mismatch.";
		}

		if ( '' === $core_param['type'] ) {
			if ( '' !== $focus_param['type'] ) {
				$errors[] = "{$label} parameter \${$core_param['name']} is stricter than core: {$focus_param['type']}.";
			}
		} elseif ( '' !== $focus_param['type'] && $core_param['type'] !== $focus_param['type'] ) {
			$errors[] = "{$label} parameter \${$core_param['name']} type mismatch: core {$core_param['type']}, FOCUS {$focus_param['type']}.";
		}
	}

	if ( '' === $core['returnType'] && '' !== $focus['returnType'] ) {
		$errors[] = "{$label} declares return type {$focus['returnType']}; core has no return type.";
	} elseif ( '' !== $core['returnType'] && '' !== $focus['returnType'] && $core['returnType'] !== $focus['returnType'] ) {
		$errors[] = "{$label} return type mismatch: core {$core['returnType']}, FOCUS {$focus['returnType']}.";
	}

	return $errors;
}

/**
 * Returns next named token.
 *
 * @param array<int,mixed> $tokens Tokens.
 * @param int             $start Start index.
 * @return mixed|null Token.
 */
function next_named_token( array $tokens, int $start ): mixed {
	$count = count( $tokens );
	for ( $i = $start; $i < $count; $i++ ) {
		$token = $tokens[ $i ];
		if ( is_array( $token ) && in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
			continue;
		}

		return $token;
	}

	return null;
}
