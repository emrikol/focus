#!/usr/bin/env bash
set -euo pipefail

PLUGIN_SLUG="focus-object-cache"
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DIST_DIR="${ROOT_DIR}/dist"
BUILD_DIR="${DIST_DIR}/${PLUGIN_SLUG}"

cd "${ROOT_DIR}"

VERSION="$(
	php -r '
		$contents = file_get_contents( "focus.php" );
		if ( false === $contents || ! preg_match( "/^ \* Version:\s*(\S+)/m", $contents, $matches ) ) {
			fwrite( STDERR, "Unable to read plugin version from focus.php.\n" );
			exit( 1 );
		}
		echo $matches[1];
	'
)"

ZIP_PATH="${DIST_DIR}/${PLUGIN_SLUG}-${VERSION}.zip"

rm -rf "${BUILD_DIR}" "${ZIP_PATH}"
mkdir -p "${BUILD_DIR}/assets" "${BUILD_DIR}/includes"

cp LICENSE focus.php readme.txt readme.md "${BUILD_DIR}/"
cp assets/banner-1544x500.png assets/banner-772x250.png assets/icon-128x128.png assets/icon-256x256.png "${BUILD_DIR}/assets/"
cp includes/*.php "${BUILD_DIR}/includes/"

(
	cd "${DIST_DIR}"
	zip -qr "$(basename "${ZIP_PATH}")" "${PLUGIN_SLUG}"
)

printf 'Built %s\n' "${ZIP_PATH}"
