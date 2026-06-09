#!/usr/bin/env bash
set -euo pipefail

REMOTE="${FOCUS_DEPLOY_REMOTE:-emrikol@decarbonated.org}"
SITE_PATH="${FOCUS_DEPLOY_SITE_PATH:-/home/emrikol/yulelikelinton.com}"
BACKUP_ROOT="${FOCUS_DEPLOY_BACKUP_ROOT:-/home/emrikol/yulelikelinton.com-backups}"
BACKEND="${FOCUS_DEPLOY_BACKEND:-database}"
IDENTITY_FILE="${FOCUS_DEPLOY_IDENTITY:-${HOME}/.ssh/id_ed25519}"
REQUIRED_BRANCH="${FOCUS_DEPLOY_BRANCH:-2.0.0}"
RUN_CHECKS="${FOCUS_DEPLOY_RUN_CHECKS:-1}"
RUN_TESTS="${FOCUS_DEPLOY_RUN_TESTS:-0}"
ALLOW_DIRTY="${FOCUS_DEPLOY_ALLOW_DIRTY:-0}"
ALLOW_ANY_BRANCH="${FOCUS_DEPLOY_ALLOW_ANY_BRANCH:-0}"

PLUGIN_PATH="${SITE_PATH}/wp-content/plugins/focus-object-cache"
CONTENT_PATH="${SITE_PATH}/wp-content"
WP_CONFIG="${SITE_PATH}/wp-config.php"
SSH_OPTS=( -o IdentitiesOnly=yes -i "${IDENTITY_FILE}" )
RSYNC_SSH="ssh -o IdentitiesOnly=yes -i ${IDENTITY_FILE}"

cd "$(dirname "${BASH_SOURCE[0]}")/.."

current_branch="$(git rev-parse --abbrev-ref HEAD)"
if [[ "${ALLOW_ANY_BRANCH}" != "1" && "${current_branch}" != "${REQUIRED_BRANCH}" ]]; then
	printf 'Refusing to deploy branch %s; expected %s.\n' "${current_branch}" "${REQUIRED_BRANCH}" >&2
	printf 'Set FOCUS_DEPLOY_ALLOW_ANY_BRANCH=1 to override.\n' >&2
	exit 1
fi

if [[ "${ALLOW_DIRTY}" != "1" && -n "$(git status --porcelain)" ]]; then
	printf 'Refusing to deploy a dirty working tree.\n' >&2
	printf 'Commit or stash changes, or set FOCUS_DEPLOY_ALLOW_DIRTY=1 to override.\n' >&2
	exit 1
fi

if [[ "${RUN_CHECKS}" == "1" ]]; then
	composer lint
	composer check:cache-api
fi

if [[ "${RUN_TESTS}" == "1" ]]; then
	bash run-tests.sh --php 8.2 --wp 7.0 --all --stop-on-failure
fi

timestamp="$(date +%Y%m%d-%H%M%S)"
backup_path="${BACKUP_ROOT}/focus-${timestamp}"

ssh "${SSH_OPTS[@]}" -n "${REMOTE}" "mkdir -p '${backup_path}' '${PLUGIN_PATH}' '${CONTENT_PATH}' && cp -a '${PLUGIN_PATH}' '${backup_path}/plugin' && cp -a '${CONTENT_PATH}/object-cache.php' '${backup_path}/object-cache.php'"

rsync -az --delete --delete-excluded \
	-e "${RSYNC_SSH}" \
	--exclude .git \
	--exclude .dockerignore \
	--exclude vendor \
	--exclude tests \
	--exclude tools \
	--exclude '$WP_TESTS_DIR' \
	--exclude .phpunit.cache \
	--exclude .claude \
	--exclude .github \
	--exclude .DS_Store \
	--exclude run-tests.sh \
	--exclude Dockerfile.test \
	--exclude phpunit.xml.dist \
	--exclude .phpcs.xml.dist \
	--exclude composer.json \
	--exclude composer.lock \
	--exclude CLAUDE.md \
	--exclude DEVELOPMENT.md \
	./ "${REMOTE}:${PLUGIN_PATH}/"

ssh "${SSH_OPTS[@]}" -n "${REMOTE}" "cp '${PLUGIN_PATH}/includes/object-cache.php' '${CONTENT_PATH}/object-cache.php'"

ssh "${SSH_OPTS[@]}" "${REMOTE}" "WP_CONFIG='${WP_CONFIG}' FOCUS_BACKEND='${BACKEND}' php" <<'PHP'
<?php
declare(strict_types=1);

$path    = getenv( 'WP_CONFIG' );
$backend = getenv( 'FOCUS_BACKEND' ) ?: 'database';

if ( false === $path || '' === $path || ! is_file( $path ) || ! is_readable( $path ) || ! is_writable( $path ) ) {
	fwrite( STDERR, "wp-config.php is not readable and writable.\n" );
	exit( 1 );
}

$contents = file_get_contents( $path );
if ( false === $contents ) {
	fwrite( STDERR, "Unable to read wp-config.php.\n" );
	exit( 1 );
}

$backend = preg_replace( '/[^a-z0-9_-]/i', '', $backend ) ?: 'database';
$block   = "if ( ! defined( 'WP_FOCUS_BACKEND' ) ) {\n\tdefine( 'WP_FOCUS_BACKEND', '" . addslashes( $backend ) . "' );\n}\n";

$guard_pattern  = "/if\s*\(\s*!\s*defined\s*\(\s*['\"]WP_FOCUS_BACKEND['\"]\s*\)\s*\)\s*\{\s*define\s*\(\s*['\"]WP_FOCUS_BACKEND['\"]\s*,\s*['\"][^'\"]*['\"]\s*\)\s*;\s*\}\s*/s";
$define_pattern = "/define\s*\(\s*['\"]WP_FOCUS_BACKEND['\"]\s*,\s*['\"][^'\"]*['\"]\s*\)\s*;\s*/";

if ( preg_match( $guard_pattern, $contents ) ) {
	$contents = preg_replace( $guard_pattern, $block, $contents, 1 );
} elseif ( preg_match( $define_pattern, $contents ) ) {
	$contents = preg_replace( $define_pattern, $block, $contents, 1 );
} elseif ( false !== strpos( $contents, "/* That's all, stop editing!" ) ) {
	$contents = str_replace( "/* That's all, stop editing!", $block . "\n/* That's all, stop editing!", $contents );
} elseif ( false !== strpos( $contents, "require_once ABSPATH . 'wp-settings.php';" ) ) {
	$contents = str_replace( "require_once ABSPATH . 'wp-settings.php';", $block . "\nrequire_once ABSPATH . 'wp-settings.php';", $contents );
} else {
	$contents .= "\n" . $block;
}

if ( false === file_put_contents( $path, $contents ) ) {
	fwrite( STDERR, "Unable to write wp-config.php.\n" );
	exit( 1 );
}
PHP

ssh "${SSH_OPTS[@]}" -n "${REMOTE}" "php -l '${CONTENT_PATH}/object-cache.php' && php -l '${PLUGIN_PATH}/focus.php' && php -l '${PLUGIN_PATH}/includes/class-focus-cache.php'"

printf 'Deployed FOCUS to %s:%s\n' "${REMOTE}" "${SITE_PATH}"
printf 'Backup: %s:%s\n' "${REMOTE}" "${backup_path}"
