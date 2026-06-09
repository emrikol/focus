#!/bin/bash

set -euo pipefail

# --- CONFIGURATION ---
PHP_VERSION="${PHP_VERSION:-8.2}"
WP_VERSION="${WP_VERSION:-7.0}"
DB_CONTAINER_NAME="wp-test-db-$$"
PHP_CONTAINER_NAME="wp-test-runner-$$"
DB_VOLUME_NAME="wp-test-db-volume-$$"
TEST_IMAGE_VERSION="v2"
TEST_IMAGE_NAME="focus-test-env-php${PHP_VERSION}-${TEST_IMAGE_VERSION}"
COMPOSER_CACHE_VOLUME="focus-composer-cache"
WP_TESTS_CACHE_VOLUME="focus-wp-tests-cache"
DB_ROOT_PASSWORD="password"
DB_NAME="wordpress"
DB_USER="wordpress"
DB_PASSWORD="password"
TEST_DB_PORT="3307"
DEBUG=false
SHELL_MODE=false
PHPUNIT_CONFIG="phpunit.xml.dist"
PHPUNIT_ARGS=""
PHPUNIT_GROUPS=""
RUN_ALL_TESTS=false
COVERAGE=false

# --- ARG PARSING ---
while [[ $# -gt 0 ]]; do
  case $1 in
    --debug) DEBUG=true ;;
    --shell) SHELL_MODE=true ;;
    --multisite) PHPUNIT_CONFIG="tests/phpunit/multisite.xml" ;;
    --all) RUN_ALL_TESTS=true ;;
    --ajax)
      if [[ -n "$PHPUNIT_GROUPS" ]]; then
        PHPUNIT_GROUPS="$PHPUNIT_GROUPS,ajax"
      else
        PHPUNIT_GROUPS="--group ajax"
      fi
      ;;
    --ms-files)
      if [[ -n "$PHPUNIT_GROUPS" ]]; then
        PHPUNIT_GROUPS="$PHPUNIT_GROUPS,ms-files"
      else
        PHPUNIT_GROUPS="--group ms-files"
      fi
      ;;
    --external-http)
      if [[ -n "$PHPUNIT_GROUPS" ]]; then
        PHPUNIT_GROUPS="$PHPUNIT_GROUPS,external-http"
      else
        PHPUNIT_GROUPS="--group external-http"
      fi
      ;;
    --php)
      PHP_VERSION="$2"
      shift # past argument
      ;;
    --wp)
      WP_VERSION="$2"
      shift # past argument
      ;;
    --lint)
      echo "🔍 Running PHP lint check..."
      if command -v npx &> /dev/null; then
        npx phplint '**/*.php' '!vendor/**' '!node_modules/**' || exit 1
      else
        find . -name "*.php" -not -path "./vendor/*" -exec php -l {} \; || exit 1
      fi
      echo "✅ PHP lint check passed"
      exit 0
      ;;
    --cleanup)
      echo "🧹 Cleaning up cached containers and volumes..."
      docker rm -f "$(docker ps -a --format "{{.Names}}" | grep "^wp-test-" 2>/dev/null)" 2>/dev/null || true
      docker volume rm "$(docker volume ls --format "{{.Name}}" | grep "^wp-test-db-volume-\|^focus-" 2>/dev/null)" 2>/dev/null || true
      docker image rm focus-test-env-php8.2 2>/dev/null || true
      docker images -f "reference=focus-test-env-php*" -q | xargs -r docker rmi 2>/dev/null || true
      echo "✅ Cleanup complete!"
      exit 0
      ;;
    --help|-h)
      echo "Fast WordPress Test Runner"
      echo ""
      echo "Usage: $0 [options] [phpunit-args]"
      echo ""
      echo "Test Options:"
      echo "  --all            Run ALL tests (single-site + multisite)"
      echo "  --multisite      Use multisite configuration"
      echo "  --ajax           Run tests in ajax group only"
      echo "  --ms-files       Run tests in ms-files group only"
      echo "  --external-http  Run tests in external-http group only"
      echo ""
      echo "Environment Options:"
      echo "  --php VERSION    PHP version to use (default: 8.2)"
      echo "  --wp VERSION     WordPress version to use (default: 7.0)"
      echo ""
      echo "Utility Options:"
      echo "  --lint           Run PHP syntax check only"
      echo "  --cleanup        Remove all cached containers and volumes"
      echo "  --debug          Show verbose output"
      echo "  --shell          Drop into interactive shell in test environment"
      echo "  --help, -h       Show this help"
      echo ""
      echo "PHPUnit Test Detail Options (any PHPUnit flag):"
      echo "  --verbose        Show detailed test output and skip reasons"
      echo "  --testdox        Human-readable test names with pass/fail icons"
      echo "  --stop-on-failure Stop immediately when a test fails"
      echo "  --stop-on-skipped Stop immediately when a test is skipped"
      echo "  --stop-on-error  Stop immediately when an error occurs"
      echo "  --list-tests     List all available tests without running them"
      echo "  --coverage-text  Show test coverage information (uses phpdbg in Docker)"
      echo "  --filter PATTERN Run only tests matching pattern"
      echo "  --group NAME     Run only tests in specified group"
      echo "  --exclude-group NAME Exclude tests in specified group"
      echo "  --colors=always  Force colored output (useful in CI/scripts)"
      echo ""
      echo "Examples:"
      echo "  $0                          # Run single-site tests only"
      echo "  $0 --all                    # Run ALL tests (single-site + multisite)"
      echo "  $0 --multisite              # Run multisite tests only"
      echo "  $0 --php 8.1 --wp 6.4       # Test with PHP 8.1 and WordPress 6.4"
      echo "  $0 --verbose --testdox      # Detailed output with readable test names"
      echo "  $0 --multisite --verbose    # Multisite tests with detailed output"
      echo "  $0 --filter test_cache      # Run specific tests"
      echo "  $0 --group focus            # Run tests in 'focus' group only"
      echo "  $0 --list-tests             # List all available tests"
      echo "  $0 --stop-on-failure        # Stop on first failure for debugging"
      echo "  $0 --cleanup                # Clean up Docker cache"
      echo "  $0 --shell                  # Interactive debugging"
      echo ""
      echo "For maximum test detail (recommended for troubleshooting):"
      echo "  $0 --verbose --testdox --stop-on-failure"
      echo "  $0 --multisite --verbose --testdox --stop-on-failure"
      exit 0
      ;;
    --coverage-*)
      COVERAGE=true
      PHPUNIT_ARGS="$PHPUNIT_ARGS $1"
      ;;
    --filter|--testdox)
      # Pass PHPUnit-specific args through
      PHPUNIT_ARGS="$PHPUNIT_ARGS $1"
      ;;
    *)
      PHPUNIT_ARGS="$PHPUNIT_ARGS $1"
      ;;
  esac
  shift # past argument or value
done

# --- DOCKER VALIDATION ---
check_docker_installed() {
  if ! command -v docker &> /dev/null; then
    echo "❌ Docker is not installed. Please install Docker first."
    echo "   Visit: https://docs.docker.com/get-docker/"
    exit 1
  fi
}

check_docker_running() {
  if ! docker info &> /dev/null; then
    return 1
  fi
  return 0
}

start_docker_if_needed() {
  if check_docker_running; then
    if [ "$DEBUG" = true ]; then
      echo "✅ Docker daemon is already running"
    fi
    return 0
  fi
  
  echo "🐳 Docker daemon not running. Attempting to start..."
  
  # Try to start Docker based on platform
  if [[ "$OSTYPE" == "darwin"* ]]; then
    # macOS - try to start Docker Desktop
    if [ -d "/Applications/Docker.app" ]; then
      echo "   Starting Docker Desktop..."
      open -a Docker
    elif [ -d "/System/Applications/Docker.app" ]; then
      echo "   Starting Docker Desktop..."
      open -a Docker
    else
      echo "❌ Docker Desktop not found in Applications. Please start Docker manually."
      exit 1
    fi
  elif [[ "$OSTYPE" == "linux-gnu"* ]]; then
    # Linux - try to start docker service
    if command -v systemctl &> /dev/null; then
      echo "   Starting Docker service with systemctl..."
      sudo systemctl start docker || {
        echo "❌ Failed to start Docker service. Please start Docker manually."
        exit 1
      }
    else
      echo "❌ Unable to start Docker automatically. Please start Docker manually."
      exit 1
    fi
  else
    echo "❌ Unsupported platform for automatic Docker startup. Please start Docker manually."
    exit 1
  fi
  
  # Wait for Docker to be ready
  echo -n "⏳ Waiting for Docker daemon"
  for _ in {1..180}; do
    if check_docker_running; then
      echo " ✅"
      break
    fi
    echo -n "."
    sleep 1
  done
  
  if ! check_docker_running; then
    echo ""
    echo "❌ Docker failed to start within 3 minutes. Please start Docker manually."
    exit 1
  fi
}

check_docker_installed
start_docker_if_needed

# Build test image if needed  
TEST_IMAGE_NAME="focus-test-env-php${PHP_VERSION}-${TEST_IMAGE_VERSION}"
if ! docker image inspect "$TEST_IMAGE_NAME" >/dev/null 2>&1; then
  echo "🔨 Building cached test environment for PHP $PHP_VERSION (one-time setup)..."
  docker build --build-arg PHP_VERSION="$PHP_VERSION" -t "$TEST_IMAGE_NAME" -f Dockerfile.test . >/dev/null
  echo "✅ Test environment cached"
fi

# Create cache volumes
docker volume create "$COMPOSER_CACHE_VOLUME" >/dev/null 2>&1 || true
docker volume create "$WP_TESTS_CACHE_VOLUME" >/dev/null 2>&1 || true

# --- VALIDATE TEST SETUP ---
if [ "$DEBUG" = true ]; then
  echo "🔍 Validating PHPUnit test setup..."
fi
if [ ! -f "phpunit.xml" ] && [ ! -f "phpunit.xml.dist" ]; then
  echo "❌ No phpunit.xml or phpunit.xml.dist found in current directory."
  exit 1
fi

if [ ! -d "tests" ]; then
  echo "❌ No /tests directory found."
  exit 1
fi

# Validate multisite configuration if --multisite flag was used
if [[ "$PHPUNIT_CONFIG" == *"multisite.xml"* ]]; then
  if [ ! -f "tests/phpunit/multisite.xml" ]; then
    echo "❌ Multisite configuration file not found: tests/phpunit/multisite.xml"
    echo "   The --multisite flag requires this configuration file to exist."
    exit 1
  fi
  if [ "$DEBUG" = true ]; then
    echo "✅ Multisite configuration found: tests/phpunit/multisite.xml"
  fi
fi

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
for _ in {1..30}; do
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

# --- HANDLE SHELL MODE ---
if [ "$SHELL_MODE" = true ]; then
  echo "🐚 Starting interactive debugging shell..."
  echo "📦 Setting up test environment in Docker (PHP $PHP_VERSION)..."
  
  docker run -it --rm --name "$PHP_CONTAINER_NAME" \
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
    -e FOCUS_COVERAGE="$COVERAGE" \
    -e WP_VERSION="$WP_VERSION" \
    -e PHPUNIT_CONFIG="$PHPUNIT_CONFIG" \
    "$TEST_IMAGE_NAME" bash -c "
    set -euo pipefail
    FOCUS_SETUP_ONLY=true bash tools/run-wp-phpunit.sh
    source /tmp/focus-wp-tests-env

    echo
    echo '🎯 DEBUG ENVIRONMENT READY!'
    echo '================================'
    echo 'PHPUnit command: '\"\$PHPUNIT_CMD\"
    echo
    echo 'Available commands:'
    echo '  '\$PHPUNIT_CMD'                           # Run PHPUnit'
    echo '  '\$PHPUNIT_CMD' tests/test-simple.php     # Run specific test file'
    echo '  '\$PHPUNIT_CMD' --list-tests              # List all available tests'
    echo '  php -l filename.php                     # Check PHP syntax'
    echo '  mysql -h127.0.0.1 -P$TEST_DB_PORT -u$DB_USER -p$DB_PASSWORD $DB_NAME  # Connect to test DB'
    echo '  exit                                    # Exit shell'
    echo
    echo 'Environment variables:'
    echo '  WP_TESTS_DIR='\"\$WP_TESTS_DIR\"
    echo '  WORDPRESS_DB_HOST=127.0.0.1:$TEST_DB_PORT'
    echo '  WORDPRESS_DB_NAME=$DB_NAME'
    echo '  PHPUNIT_CONFIG=$PHPUNIT_CONFIG'
    echo
    echo '🐚 Dropping into interactive shell...'
    echo 'Type \"exit\" to return to host system.'
    echo
    
    exec bash
  "
  exit 0
fi

echo "📦 Running tests..."

# Handle --all flag to run both single-site and multisite tests
if [ "$RUN_ALL_TESTS" = true ]; then
  echo "🚀 Running COMPLETE test suite (single-site + multisite)..."
  echo ""
  
  # First run single-site tests
  echo "🏢 Single-Site Tests"
  echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
  
  # Run single-site tests (default phpunit.xml.dist)
  docker run --rm --name "$PHP_CONTAINER_NAME-single" \
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
    -e FOCUS_COVERAGE="$COVERAGE" \
    -e WP_VERSION="$WP_VERSION" \
    -e PHPUNIT_CONFIG="phpunit.xml.dist" \
    "$TEST_IMAGE_NAME" bash -c "set -o pipefail; bash tools/run-wp-phpunit.sh $PHPUNIT_ARGS $PHPUNIT_GROUPS --colors=always 2>&1 | tee /app/phpunit-single-output.txt"
  SINGLE_SITE_EXIT=$?
  
  # Clean up output file
  rm -f phpunit-single-output.txt 2>/dev/null || true
  
  echo ""
  echo ""
  echo "🌐 Multisite Tests"
  echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
  
  # Run multisite tests
  docker run --rm --name "$PHP_CONTAINER_NAME-multi" \
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
    -e FOCUS_COVERAGE="$COVERAGE" \
    -e WP_VERSION="$WP_VERSION" \
    -e PHPUNIT_CONFIG="tests/phpunit/multisite.xml" \
    "$TEST_IMAGE_NAME" bash -c "set -o pipefail; bash tools/run-wp-phpunit.sh $PHPUNIT_ARGS $PHPUNIT_GROUPS --colors=always 2>&1 | tee /app/phpunit-multisite-output.txt"
  MULTISITE_EXIT=$?
  
  # Clean up output file
  rm -f phpunit-multisite-output.txt 2>/dev/null || true
  
  # Overall results
  echo ""
  echo ""
  echo "📊 Complete Test Suite Results"
  echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
  
  if [ $SINGLE_SITE_EXIT -eq 0 ] && [ $MULTISITE_EXIT -eq 0 ]; then
    echo "✅ All Tests Complete - Single-Site ✅ + Multisite ✅"
    PHPUNIT_EXIT=0
  else
    echo "❌ Some Tests Failed:"
    if [ $SINGLE_SITE_EXIT -ne 0 ]; then
      echo "  ❌ Single-Site Tests Failed"
    else
      echo "  ✅ Single-Site Tests Passed"
    fi
    if [ $MULTISITE_EXIT -ne 0 ]; then
      echo "  ❌ Multisite Tests Failed"  
    else
      echo "  ✅ Multisite Tests Passed"
    fi
    PHPUNIT_EXIT=1
  fi
  exit $PHPUNIT_EXIT
fi

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
  -e FOCUS_COVERAGE="$COVERAGE" \
  -e WP_VERSION="$WP_VERSION" \
  -e PHPUNIT_CONFIG="$PHPUNIT_CONFIG" \
  "$TEST_IMAGE_NAME" bash -c "set -o pipefail; bash tools/run-wp-phpunit.sh $PHPUNIT_ARGS $PHPUNIT_GROUPS --colors=always 2>&1 | tee /app/phpunit-output.txt"

# Clean up output file
rm -f phpunit-output.txt 2>/dev/null || true
