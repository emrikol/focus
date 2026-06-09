#!/usr/bin/env bash

set -euo pipefail

PHPUNIT_CONFIG="${PHPUNIT_CONFIG:-phpunit.xml.dist}"
WP_VERSION="${WP_VERSION:-7.0}"
WP_TESTS_CACHE_ROOT="${WP_TESTS_CACHE_ROOT:-/cache/wp-tests}"
WORDPRESS_DB_NAME="${WORDPRESS_DB_NAME:-wordpress}"
WORDPRESS_DB_USER="${WORDPRESS_DB_USER:-wordpress}"
WORDPRESS_DB_PASSWORD="${WORDPRESS_DB_PASSWORD:-password}"
WORDPRESS_DB_HOST="${WORDPRESS_DB_HOST:-127.0.0.1:3307}"

if [ -f composer.json ]; then
	composer install --no-interaction --no-progress --quiet --prefer-dist
	PHPUNIT_CMD="./vendor/bin/phpunit"
else
	PHPUNIT_CMD="phpunit"
fi

resolve_wordpress_archive() {
	local candidate_type
	local candidate_ref
	local candidate_url
	local candidates=()

	if [ "$WP_VERSION" = "trunk" ]; then
		candidates+=( "heads:trunk" )
	elif [[ "$WP_VERSION" =~ ^[0-9]+\.[0-9]+$ ]]; then
		candidates+=( "tags:$WP_VERSION.0" "heads:$WP_VERSION" "tags:$WP_VERSION" )
	else
		candidates+=( "tags:$WP_VERSION" "heads:$WP_VERSION" )
	fi

	for candidate in "${candidates[@]}"; do
		candidate_type="${candidate%%:*}"
		candidate_ref="${candidate#*:}"
		candidate_url="https://github.com/WordPress/wordpress-develop/archive/refs/$candidate_type/$candidate_ref.tar.gz"
		if curl -fsIL "$candidate_url" >/dev/null; then
			WP_REF="$candidate_type/$candidate_ref"
			WP_ARCHIVE_URL="$candidate_url"
			return
		fi
	done

	echo "Unable to resolve WordPress develop archive for version '$WP_VERSION'." >&2
	exit 1
}

resolve_wordpress_archive

WP_CACHE_KEY="$(printf '%s' "$WP_REF" | tr -c 'A-Za-z0-9_.-' '-')"
WP_TESTS_DIR="${WP_TESTS_DIR:-$WP_TESTS_CACHE_ROOT/$WP_CACHE_KEY}"
WP_TESTS_MARKER="$WP_TESTS_DIR/.wp-ref"
WP_CONFIG_SAMPLE="$WP_TESTS_DIR/wp-tests-config-sample.php"
WP_CONFIG_FILE="$WP_TESTS_DIR/wp-tests-config.php"

if [ ! -f "$WP_TESTS_MARKER" ] || [ "$(cat "$WP_TESTS_MARKER" 2>/dev/null)" != "$WP_REF" ] || [ ! -d "$WP_TESTS_DIR/includes" ] || [ ! -d "$WP_TESTS_DIR/src" ]; then
	echo "Downloading WordPress test suite for $WP_REF..."
	rm -rf "$WP_TESTS_DIR"
	mkdir -p "$WP_TESTS_DIR"
	tmp_archive="/tmp/wordpress-develop-$WP_CACHE_KEY.tar.gz"
	tmp_extract="/tmp/wordpress-develop-$WP_CACHE_KEY"
	rm -rf "$tmp_archive" "$tmp_extract"
	mkdir -p "$tmp_extract"
	curl -fsSL "$WP_ARCHIVE_URL" -o "$tmp_archive"
	tar -xzf "$tmp_archive" -C "$tmp_extract" --strip-components=1
	mv "$tmp_extract/tests/phpunit/includes" "$WP_TESTS_DIR/includes"
	mv "$tmp_extract/tests/phpunit/data" "$WP_TESTS_DIR/data"
	mv "$tmp_extract/src" "$WP_TESTS_DIR/src"
	mv "$tmp_extract/wp-tests-config-sample.php" "$WP_CONFIG_SAMPLE"
	printf '%s' "$WP_REF" > "$WP_TESTS_MARKER"
	rm -rf "$tmp_archive" "$tmp_extract"
fi

export WP_CONFIG_SAMPLE WP_CONFIG_FILE WORDPRESS_DB_NAME WORDPRESS_DB_USER WORDPRESS_DB_PASSWORD WORDPRESS_DB_HOST
php -r '
$sample = getenv( "WP_CONFIG_SAMPLE" );
$target = getenv( "WP_CONFIG_FILE" );
$config = file_get_contents( $sample );
$config = str_replace(
	array(
		"youremptytestdbnamehere",
		"yourusernamehere",
		"yourpasswordhere",
		"localhost",
	),
	array(
		getenv( "WORDPRESS_DB_NAME" ),
		getenv( "WORDPRESS_DB_USER" ),
		getenv( "WORDPRESS_DB_PASSWORD" ),
		getenv( "WORDPRESS_DB_HOST" ),
	),
	$config
);
file_put_contents( $target, $config );
'

export WP_TESTS_DIR

rm -rf "$WP_TESTS_DIR/src/wp-content/focus-object-cache"/* 2>/dev/null || true
rm -f "$WP_TESTS_DIR/src/wp-content/object-cache.php" 2>/dev/null || true

if [ "${FOCUS_SETUP_ONLY:-false}" = "true" ]; then
	{
		printf 'export WP_TESTS_DIR=%q\n' "$WP_TESTS_DIR"
		printf 'export PHPUNIT_CMD=%q\n' "$PHPUNIT_CMD"
		printf 'export PHPUNIT_CONFIG=%q\n' "$PHPUNIT_CONFIG"
	} > /tmp/focus-wp-tests-env
	echo "WP_TESTS_DIR=$WP_TESTS_DIR"
	echo "PHPUNIT_CMD=$PHPUNIT_CMD"
	exit 0
fi

if [ "${FOCUS_COVERAGE:-false}" = "true" ]; then
	if php -m | grep -qi '^pcov$'; then
		php -d pcov.enabled=1 -d pcov.directory=/app "$PHPUNIT_CMD" -c "$PHPUNIT_CONFIG" "$@"
	elif php -m | grep -qi '^xdebug$'; then
		XDEBUG_MODE=coverage "$PHPUNIT_CMD" -c "$PHPUNIT_CONFIG" "$@"
	elif command -v phpdbg >/dev/null 2>&1; then
		phpdbg -qrr "$PHPUNIT_CMD" -c "$PHPUNIT_CONFIG" "$@"
	else
		echo "Coverage requested, but no supported coverage driver was found." >&2
		exit 1
	fi
else
	"$PHPUNIT_CMD" -c "$PHPUNIT_CONFIG" "$@"
fi
