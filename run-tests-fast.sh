#!/bin/bash

set -euo pipefail

# --- CONFIGURATION ---
PHP_VERSION="8.2"
WP_VERSION="trunk"
DB_CONTAINER_NAME="wp-test-db-$$"
PHP_CONTAINER_NAME="wp-test-runner-$$"
DB_VOLUME_NAME="wp-test-db-volume-$$"
TEST_IMAGE_NAME="focus-test-env"
COMPOSER_CACHE_VOLUME="focus-composer-cache"
WP_TESTS_CACHE_VOLUME="focus-wp-tests-cache"
DB_ROOT_PASSWORD="password"
DB_NAME="wordpress"
DB_USER="wordpress"
DB_PASSWORD="password"
TEST_DB_PORT="3307"
DEBUG=false
PHPUNIT_ARGS=""

# Parse args
for arg in "$@"; do
  case "$arg" in
    --debug) DEBUG=true ;;
    --multisite) PHPUNIT_ARGS="$PHPUNIT_ARGS -c tests/phpunit/multisite.xml" ;;
    --cleanup)
      echo "🧹 Cleaning up cached containers and volumes..."
      docker rm -f $(docker ps -a --format "{{.Names}}" | grep "^wp-test-" 2>/dev/null) 2>/dev/null || true
      docker volume rm $(docker volume ls --format "{{.Name}}" | grep "^wp-test-db-volume-\|^focus-" 2>/dev/null) 2>/dev/null || true
      docker image rm focus-test-env 2>/dev/null || true
      echo "✅ Cleanup complete!"
      exit 0
      ;;
    --help|-h)
      echo "Fast WordPress Test Runner"
      echo ""
      echo "Usage: $0 [options] [phpunit-args]"
      echo ""
      echo "Options:"
      echo "  --debug          Show verbose output"
      echo "  --multisite      Use multisite configuration"
      echo "  --cleanup        Remove all cached containers and volumes"
      echo "  --help, -h       Show this help"
      echo ""
      echo "Examples:"
      echo "  $0                    # Run all tests (fast)"
      echo "  $0 --multisite        # Run multisite tests"
      echo "  $0 --filter test_cache # Run specific tests"
      echo "  $0 --cleanup          # Clean up Docker cache"
      exit 0
      ;;
    *) PHPUNIT_ARGS="$PHPUNIT_ARGS $arg" ;;
  esac
done

# Build test image if needed
if ! docker image inspect "$TEST_IMAGE_NAME" >/dev/null 2>&1; then
  echo "🔨 Building cached test environment (one-time setup)..."
  docker build -t "$TEST_IMAGE_NAME" -f Dockerfile.test . >/dev/null
  echo "✅ Test environment cached"
fi

# Create cache volumes
docker volume create "$COMPOSER_CACHE_VOLUME" >/dev/null 2>&1 || true
docker volume create "$WP_TESTS_CACHE_VOLUME" >/dev/null 2>&1 || true

# Start database
echo "🚀 Starting database..."
docker volume create "$DB_VOLUME_NAME" >/dev/null
docker run -d --rm \
  --name "$DB_CONTAINER_NAME" \
  -e MYSQL_ROOT_PASSWORD="$DB_ROOT_PASSWORD" \
  -e MYSQL_DATABASE="$DB_NAME" \
  -e MYSQL_USER="$DB_USER" \
  -e MYSQL_PASSWORD="$DB_PASSWORD" \
  -p "$TEST_DB_PORT":3306 \
  -v "$DB_VOLUME_NAME":/var/lib/mysql \
  mariadb:10.6 \
  --default-authentication-plugin=mysql_native_password \
  >/dev/null

# Wait for DB
echo -n "⏳ Waiting for database"
for i in {1..30}; do
  if docker exec "$DB_CONTAINER_NAME" mysqladmin ping -h"127.0.0.1" -P3306 --silent >/dev/null 2>&1; then
    echo " ✅"
    break
  fi
  echo -n "."
  sleep 1
done

# Cleanup function
cleanup() {
  docker rm -f "$DB_CONTAINER_NAME" >/dev/null 2>&1 || true
  docker volume rm "$DB_VOLUME_NAME" >/dev/null 2>&1 || true
}
trap cleanup EXIT

echo "📦 Running tests..."

# Run tests with cached environment
docker run --rm --name "$PHP_CONTAINER_NAME" \
  -v "$PWD":/app \
  -w /app \
  --network host \
  -v "$COMPOSER_CACHE_VOLUME":/cache/composer \
  -v "$WP_TESTS_CACHE_VOLUME":/cache/wp-tests \
  -e WORDPRESS_DB_HOST=127.0.0.1:"$TEST_DB_PORT" \
  -e WORDPRESS_DB_NAME="$DB_NAME" \
  -e WORDPRESS_DB_USER="$DB_USER" \
  -e WORDPRESS_DB_PASSWORD="$DB_PASSWORD" \
  -e COMPOSER_CACHE_DIR=/cache/composer \
  "$TEST_IMAGE_NAME" bash -c "
    set -euo pipefail
    
    # Fast composer install with cache
    if [ -f composer.json ]; then
      composer install --no-interaction --no-progress --quiet --prefer-dist
      PHPUNIT_CMD='./vendor/bin/phpunit'
    else
      PHPUNIT_CMD='phpunit'
    fi
    
    # Use cached WordPress tests or download once
    WP_TESTS_DIR=/cache/wp-tests/wordpress-tests-lib
    if [ ! -d \"\$WP_TESTS_DIR/includes\" ]; then
      echo '📥 Downloading WordPress tests (cached for future runs)...'
      mkdir -p \$WP_TESTS_DIR
      if [ \"$WP_VERSION\" = \"trunk\" ]; then
        WP_TESTS_TAG=\"trunk\"
      else
        WP_TESTS_TAG=\"tags/$WP_VERSION\"
      fi
      svn export --quiet https://develop.svn.wordpress.org/\$WP_TESTS_TAG/tests/phpunit/includes/ \$WP_TESTS_DIR/includes
      svn export --quiet https://develop.svn.wordpress.org/\$WP_TESTS_TAG/tests/phpunit/data/ \$WP_TESTS_DIR/data
      svn export --quiet https://develop.svn.wordpress.org/\$WP_TESTS_TAG/src/ \$WP_TESTS_DIR/src
      svn export --quiet https://develop.svn.wordpress.org/\$WP_TESTS_TAG/wp-tests-config-sample.php wp-tests-config.php
    else
      cp /cache/wp-tests/wp-tests-config.php wp-tests-config.php 2>/dev/null || svn export --quiet https://develop.svn.wordpress.org/trunk/wp-tests-config-sample.php wp-tests-config.php
    fi
    
    # Configure WordPress tests
    sed -i \"s/youremptytestdbnamehere/$DB_NAME/\" wp-tests-config.php
    sed -i \"s/yourusernamehere/$DB_USER/\" wp-tests-config.php
    sed -i \"s/yourpasswordhere/$DB_PASSWORD/\" wp-tests-config.php
    sed -i \"s|localhost|127.0.0.1:$TEST_DB_PORT|\" wp-tests-config.php
    mv wp-tests-config.php \$WP_TESTS_DIR/wp-tests-config.php
    cp \$WP_TESTS_DIR/wp-tests-config.php /cache/wp-tests/ 2>/dev/null || true
    
    export WP_TESTS_DIR=\$WP_TESTS_DIR
    
    # Clean any cached test data that could contaminate results
    echo '🧹 Cleaning test cache...'
    rm -rf \$WP_TESTS_DIR/src/wp-content/focus-object-cache/* 2>/dev/null || true
    rm -f \$WP_TESTS_DIR/src/wp-content/object-cache.php 2>/dev/null || true
    
    echo
    echo '📋 ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━'
    echo '📋 ✅ PHPUnit Test Results'
    echo '📋 ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━'
    \$PHPUNIT_CMD $PHPUNIT_ARGS
    PHPUNIT_EXIT=\$?
    echo
    echo '📋 ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━'
    if [ \$PHPUNIT_EXIT -eq 0 ]; then
      echo '📋 ✅ PHPUnit Tests Complete'
    else
      echo '📋 ❌ PHPUnit Tests Failed'
    fi
    echo '📋 ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━'
    exit \$PHPUNIT_EXIT
  "