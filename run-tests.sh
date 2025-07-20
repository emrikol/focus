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
TMP_DIR=$(mktemp -d)
WITH_WP_ENV=false  # Always true for WordPress plugins - auto-detected
DOCKER_WAS_STOPPED=false
DEBUG=false
SHELL_MODE=false
PHPUNIT_CONFIG=""
PHPUNIT_GROUPS=""
ARGS=""
CI_MODE=false

# Detect CI environment
if [ -n "${CI:-}" ] || [ -n "${GITHUB_ACTIONS:-}" ] || [ -n "${TRAVIS:-}" ] || [ -n "${CIRCLECI:-}" ]; then
  # shellcheck disable=SC2034
  CI_MODE=true
fi

# Export variables needed in Docker environment
export WITH_WP_ENV
export DEBUG
export SHELL_MODE
export PHPUNIT_CONFIG
export PHPUNIT_GROUPS
export ARGS

# --- COLOR FUNCTIONS ---
# Color codes
GREEN='\033[0;32m'
RED='\033[0;31m'
NC='\033[0m' # No Color

# Function to add colored prefix to output lines
prefix_output() {
  local prefix="$1"
  local color="$2"
  local exit_code="$3"
  
  # Choose color based on exit code if provided
  if [ -n "$exit_code" ]; then
    if [ "$exit_code" -eq 0 ]; then
      color="$GREEN"
    else
      color="$RED"
    fi
  fi
  
  while IFS= read -r line; do
    if [ -n "$line" ]; then
      printf "${color}[%s]${NC} %s\n" "$prefix" "$line"
    else
      echo ""
    fi
  done
}

# --- ARG PARSING ---
ARGS=""
for arg in "$@"; do
  case "$arg" in
    --multisite)
      PHPUNIT_CONFIG="-c tests/phpunit/multisite.xml"
      ;;
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
      shift
      if [ $# -gt 0 ]; then
        PHP_VERSION="$1"
      fi
      ;;
    --wp)
      shift
      if [ $# -gt 0 ]; then
        WP_VERSION="$1"
      fi
      ;;
    --filter|--testdox|--coverage-*)
      # Pass PHPUnit-specific args through
      ARGS="$ARGS $arg"
      ;;
    --lint)
      # Run PHP linting only
      echo "🔍 Running PHP lint check..."
      if command -v npx &> /dev/null; then
        npx phplint '**/*.php' '!vendor/**' '!node_modules/**' || exit 1
      else
        find . -name "*.php" -not -path "./vendor/*" -exec php -l {} \; || exit 1
      fi
      echo "✅ PHP lint check passed"
      exit 0
      ;;
    --debug)
      DEBUG=true
      ;;
    --shell)
      SHELL_MODE=true
      ;;
    --cleanup)
      echo "🧹 Cleaning up all test containers and volumes..."
      
      # Remove all test containers
      echo "🛑 Removing test containers..."
      docker ps -a --format "{{.Names}}" | grep "^wp-test-" | xargs -r docker rm -f >/dev/null 2>&1 || true
      
      # Wait for containers to be removed
      sleep 1
      
      # Remove all test volumes
      echo "🗑️  Removing test volumes..."
      docker volume ls --format "{{.Name}}" | grep "^wp-test-db-volume-" | xargs -r docker volume rm >/dev/null 2>&1 || true
      
      echo "✅ Cleanup complete!"
      exit 0
      ;;
    --help|-h)
      echo "WordPress Test Runner"
      echo ""
      echo "Usage: $0 [options] [phpunit-args]"
      echo ""
      echo "Test Options:"
      echo "  --multisite      Use multisite configuration (tests/phpunit/multisite.xml)"
      echo "  --ajax           Run tests in ajax group only"
      echo "  --ms-files       Run tests in ms-files group only"
      echo "  --external-http  Run tests in external-http group only"
      echo ""
      echo "Environment Options:"
      echo "  --php VERSION    PHP version to use (default: 8.2)"
      echo "  --wp VERSION     WordPress version to use (default: trunk)"
      echo ""
      echo "Utility Options:"
      echo "  --lint           Run PHP syntax check only"
      echo "  --cleanup        Remove all leftover test containers and volumes"
      echo "  --debug          Show verbose output from all tools"
      echo "  --shell          Drop into interactive shell in test environment"
      echo "  --help, -h       Show this help message"
      echo ""
      echo "PHPUnit Options (passed through):"
      echo "  --filter PATTERN Filter tests by pattern"
      echo "  --testdox        Generate testdox output"
      echo "  --coverage-*     Coverage options"
      echo ""
      echo "Examples:"
      echo "  $0                          # Run all tests"
      echo "  $0 --php 8.1 --wp 6.4      # Test with PHP 8.1 and WordPress 6.4"
      echo "  $0 --filter test_cache      # Run tests matching 'test_cache'"
      echo "  $0 --multisite --ajax       # Run multisite ajax tests"
      echo "  $0 --lint                   # Check PHP syntax only"
      echo "  $0 --debug --shell          # Interactive debugging"
      exit 0
      ;;
    *)
      # Pass unknown args to PHPUnit
      ARGS="$ARGS $arg"
      ;;
  esac
done

# Auto-detect WordPress plugin and enable WordPress test environment
# Check for WordPress plugin header in any PHP file
if grep -l "Plugin Name:" ./*.php 2>/dev/null >/dev/null; then
  if [ "$DEBUG" = true ]; then
    echo "🔍 WordPress plugin detected - setting up WordPress test environment"
  fi
  WITH_WP_ENV=true
# Check for WordPress test classes
elif grep -q "WP_UnitTestCase\|extends.*WP_" tests/*.php 2>/dev/null; then
  if [ "$DEBUG" = true ]; then
    echo "🔍 WordPress tests detected - setting up WordPress test environment"
  fi
  WITH_WP_ENV=true
else
  echo "⚠️  No WordPress plugin or tests detected - running tests without WordPress environment"
  echo "   This may cause failures if your tests use WordPress functions"
fi

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

# Build test image if it doesn't exist
build_test_image_if_needed() {
  if ! docker image inspect "$TEST_IMAGE_NAME" >/dev/null 2>&1; then
    if [ "$DEBUG" = true ]; then
      echo "🔨 Building cached test environment image (this only happens once)..."
    fi
    docker build -t "$TEST_IMAGE_NAME" -f Dockerfile.test . >/dev/null 2>&1
    if [ "$DEBUG" = true ]; then
      echo "✅ Test environment image built and cached"
    fi
  fi
}

# Create cache volumes if they don't exist
create_cache_volumes() {
  docker volume create "$COMPOSER_CACHE_VOLUME" >/dev/null 2>&1 || true
  docker volume create "$WP_TESTS_CACHE_VOLUME" >/dev/null 2>&1 || true
}

# Spinner with countdown timer
spinner_with_countdown() {
  local max_time="$1"
  local check_command="$2"
  local message="$3"
  
  local spinner_chars="⠋⠙⠹⠸⠼⠴⠦⠧⠇⠏"
  local spinner_length=${#spinner_chars}
  local elapsed=0
  local spinner_index=0
  
  echo -n "$message"
  
  while [ "$elapsed" -lt "$max_time" ]; do
    # Calculate remaining time
    local remaining=$((max_time - elapsed))
    local minutes=$((remaining / 60))
    local seconds=$((remaining % 60))
    
    # Get current spinner character
    local char="${spinner_chars:$spinner_index:1}"
    
    # Update display
    printf "\r%s %s [%d:%02d]" "$message" "$char" "$minutes" "$seconds"
    
    # Check if condition is met
    if eval "$check_command" 2>/dev/null; then
      if [ "$DEBUG" = "true" ]; then
        printf "\r%s ✅ [%d:%02d]\n" "$message" "$minutes" "$seconds"
      else
        printf "\r\033[K"  # Clear the line in non-debug mode
      fi
      return 0
    fi
    
    sleep 1
    elapsed=$((elapsed + 1))
    spinner_index=$(((spinner_index + 1) % spinner_length))
  done
  
  # Timeout reached
  printf "\r%s ❌ [0:00]\n" "$message"
  return 1
}


start_docker_if_needed() {
  if check_docker_running; then
    if [ "$DEBUG" = true ]; then
      echo "✅ Docker daemon is already running"
    fi
    return 0
  fi
  
  DOCKER_WAS_STOPPED=true
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
    elif command -v service &> /dev/null; then
      echo "   Starting Docker service with service command..."
      sudo service docker start || {
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
  
  # Wait for Docker to be ready with spinner
  if ! spinner_with_countdown 180 "check_docker_running" "⏳ Waiting for Docker daemon to be ready"; then
    echo "❌ Docker failed to start within 3 minutes. Please start Docker manually."
    exit 1
  fi
}

check_port_available() {
  if lsof -Pi :$TEST_DB_PORT -sTCP:LISTEN -t >/dev/null 2>&1; then
    if [ "$DEBUG" = true ]; then
        echo "⚠️  Port $TEST_DB_PORT is already in use."
    fi
    
    # Check if it's a leftover test container
    local existing_container
    existing_container=$(docker ps --filter "publish=$TEST_DB_PORT" --format "{{.Names}}" 2>/dev/null)
    if [[ "$existing_container" =~ ^wp-test-db- ]]; then
      if [ "$DEBUG" = true ]; then
        echo "🧹 Found leftover test container: $existing_container. Removing it..."
      fi
      docker rm -f "$existing_container" >/dev/null 2>&1 || true
      
      # Also clean up any leftover containers and volumes from previous runs
      if [ "$DEBUG" = true ]; then
        echo "🗑️  Cleaning up leftover test containers and volumes..."
      fi
      
      # Remove any leftover test containers first
      docker ps -a --format "{{.Names}}" | grep "^wp-test-" | head -10 | xargs -r docker rm -f >/dev/null 2>&1 || true
      
      # Wait for containers to be removed
      sleep 1
      
      # Then remove leftover volumes  
      docker volume ls --format "{{.Name}}" | grep "^wp-test-db-volume-" | head -5 | xargs -r docker volume rm >/dev/null 2>&1 || true
      
      sleep 2  # Give time for port to be freed
      
      # Check if port is now free
      if ! lsof -Pi :$TEST_DB_PORT -sTCP:LISTEN -t >/dev/null 2>&1; then
        if [ "$DEBUG" = true ]; then
          echo "✅ Port $TEST_DB_PORT is now available"
        fi
        return 0
      fi
    fi
    
    echo "❌ Port $TEST_DB_PORT is still in use. Please free the port or change TEST_DB_PORT."
    echo "   Run: lsof -Pi :$TEST_DB_PORT -sTCP:LISTEN"
    exit 1
  fi
}

if [ "$DEBUG" = true ]; then
  echo "🔍 Checking Docker environment..."
fi
check_docker_installed
start_docker_if_needed
build_test_image_if_needed
create_cache_volumes
check_port_available

stop_docker_if_started() {
  if [ "$DOCKER_WAS_STOPPED" = true ]; then
    echo "🐳 Leaving Docker running for subsequent test runs..."
    echo "   Use 'docker system prune' to clean up if needed"
    # Don't actually stop Docker Desktop on macOS - it's too slow to restart
    # and creates race conditions on subsequent runs
    if [[ "$OSTYPE" == "linux-gnu"* ]]; then
      # On Linux, we can safely stop the service
      if command -v systemctl &> /dev/null; then
        echo "🐳 Stopping Docker service..."
        sudo systemctl stop docker 2>/dev/null || true
        echo "✅ Docker service stopped"
      elif command -v service &> /dev/null; then
        echo "🐳 Stopping Docker service..."
        sudo service docker stop 2>/dev/null || true
        echo "✅ Docker service stopped"
      fi
    fi
  fi
}

cleanup() {
  if [ "$DEBUG" = true ]; then
    echo "🧹 Cleaning up..."
  fi
  
  # Force stop and remove containers first
  if docker ps -a --format "{{.Names}}" 2>/dev/null | grep -q "^$DB_CONTAINER_NAME$"; then
    if [ "$DEBUG" = true ]; then
      echo "🛑 Stopping database container..."
    fi
    docker stop "$DB_CONTAINER_NAME" >/dev/null 2>&1 || true
    docker rm -f "$DB_CONTAINER_NAME" >/dev/null 2>&1 || true
  fi
  if docker ps -a --format "{{.Names}}" 2>/dev/null | grep -q "^$PHP_CONTAINER_NAME$"; then
    if [ "$DEBUG" = true ]; then
      echo "🛑 Stopping PHP container..."
    fi
    docker stop "$PHP_CONTAINER_NAME" >/dev/null 2>&1 || true
    docker rm -f "$PHP_CONTAINER_NAME" >/dev/null 2>&1 || true
  fi
  
  # Wait a moment for containers to fully stop
  sleep 1
  
  # Now remove the volume
  if docker volume ls --format "{{.Name}}" 2>/dev/null | grep -q "^$DB_VOLUME_NAME$"; then
    if [ "$DEBUG" = true ]; then
      echo "🗑️  Removing database volume..."
    fi
    docker volume rm "$DB_VOLUME_NAME" >/dev/null 2>&1 || {
      echo "⚠️  Volume cleanup failed - container may still be using it"
      echo "   Manual cleanup: docker rm -f $DB_CONTAINER_NAME && docker volume rm $DB_VOLUME_NAME"
    }
  fi
  
  rm -rf "$TMP_DIR"
  stop_docker_if_started
}
trap cleanup EXIT

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

# --- START DATABASE ---
if [ "$DEBUG" = true ]; then
  echo "🚀 Starting ephemeral MariaDB container..."
fi

# Create named volume for database
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

if ! spinner_with_countdown 120 "docker exec \"$DB_CONTAINER_NAME\" mysqladmin ping -h\"127.0.0.1\" -P3306 --silent" "⏳ Waiting for DB to be ready"; then
  echo "❌ Database failed to be ready within 2 minutes."
  echo "🔍 Checking container status..."
  docker ps -a --filter "name=$DB_CONTAINER_NAME" --format "table {{.Names}}\t{{.Status}}\t{{.Ports}}"
  echo "📋 Container logs (last 10 lines):"
  docker logs "$DB_CONTAINER_NAME" --tail 10 2>/dev/null || echo "No logs available"
  exit 1
fi

# DB ready line is already cleared by the spinner function

# --- HANDLE SHELL MODE ---
if [ "$SHELL_MODE" = true ]; then
  echo "🐚 Starting interactive debugging shell..."
  echo "📦 Setting up test environment in Docker (PHP $PHP_VERSION)..."
  
  # Start the container in interactive mode with a bash shell
  docker run -it --rm --name "$PHP_CONTAINER_NAME" \
    -v "$PWD":/app \
    -w /app \
    --network host \
    -e WORDPRESS_DB_HOST=127.0.0.1:"$TEST_DB_PORT" \
    -e WORDPRESS_DB_NAME="$DB_NAME" \
    -e WORDPRESS_DB_USER="$DB_USER" \
    -e WORDPRESS_DB_PASSWORD="$DB_PASSWORD" \
    -e WITH_WP_ENV="$WITH_WP_ENV" \
    -e DEBUG="true" \
    -e PHPUNIT_CONFIG="$PHPUNIT_CONFIG" \
    -e PHPUNIT_GROUPS="$PHPUNIT_GROUPS" \
    -e ARGS="$ARGS" \
    php:"$PHP_VERSION"-cli bash -c "
    set -euo pipefail
    
    echo '🔧 Installing dependencies...'
    apt-get update -qq >/dev/null 2>&1 && apt-get install -y -qq unzip git curl libzip-dev mariadb-client subversion libpng-dev libjpeg-dev libfreetype6-dev vim less
    
    echo '🔌 Installing PHP extensions...'
    docker-php-ext-configure gd --with-freetype --with-jpeg >/dev/null 2>&1 && docker-php-ext-install mysqli zip gd >/dev/null 2>&1
    
    echo '📥 Installing Composer...'
    curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer >/dev/null 2>&1
    
    if [ -f composer.json ]; then
      echo '📦 Running composer install...'
      composer install --no-interaction --no-progress --quiet >/dev/null 2>&1
      
      # Install PHPUnit Polyfills if not already installed
      if [ ! -d vendor/yoast/phpunit-polyfills ]; then
        echo '📦 Installing PHPUnit Polyfills for WordPress tests...'
        composer require --dev yoast/phpunit-polyfills --no-interaction --quiet >/dev/null 2>&1
      fi
      
      PHPUNIT_CMD='./vendor/bin/phpunit'
    else
      echo '📥 Installing PHPUnit...'
      curl -L https://phar.phpunit.de/phpunit-10.phar -o /usr/local/bin/phpunit >/dev/null 2>&1 && chmod +x /usr/local/bin/phpunit
      PHPUNIT_CMD='phpunit'
      
      # For projects without composer, install PHPUnit Polyfills manually
      echo '📦 Installing PHPUnit Polyfills for WordPress tests...'
      mkdir -p /tmp/phpunit-polyfills
      curl -L https://github.com/Yoast/PHPUnit-Polyfills/archive/refs/heads/main.zip -o /tmp/polyfills.zip >/dev/null 2>&1
      cd /tmp && unzip -q polyfills.zip && mv PHPUnit-Polyfills-main/* /tmp/phpunit-polyfills/ && cd /app
    fi
    
    # Set up PHPUnit Polyfills path for WordPress tests
    if [ -d vendor/yoast/phpunit-polyfills ]; then
      export WP_TESTS_PHPUNIT_POLYFILLS_PATH=/app/vendor/yoast/phpunit-polyfills
    else
      export WP_TESTS_PHPUNIT_POLYFILLS_PATH=/tmp/phpunit-polyfills
    fi
    
    # Export the variables and create a setup file for the interactive shell
    export PHPUNIT_CMD
    echo \"export PHPUNIT_CMD='\$PHPUNIT_CMD'\" > /tmp/focus-debug-env.sh
    echo \"export WP_TESTS_PHPUNIT_POLYFILLS_PATH='\$WP_TESTS_PHPUNIT_POLYFILLS_PATH'\" >> /tmp/focus-debug-env.sh

    if [ \"\$WITH_WP_ENV\" = \"true\" ]; then
      echo '🧪 Setting up WordPress test environment...'
      WP_TESTS_DIR=/tmp/wordpress-tests-lib
      rm -rf \$WP_TESTS_DIR && mkdir -p \$WP_TESTS_DIR
      # Determine correct SVN path based on WP version
      if [ \"$WP_VERSION\" = \"trunk\" ]; then
        WP_TESTS_TAG=\"trunk\"
      else
        WP_TESTS_TAG=\"tags/$WP_VERSION\"
      fi
      svn export --quiet https://develop.svn.wordpress.org/\$WP_TESTS_TAG/tests/phpunit/includes/ \$WP_TESTS_DIR/includes >/dev/null 2>&1
      svn export --quiet https://develop.svn.wordpress.org/\$WP_TESTS_TAG/tests/phpunit/data/ \$WP_TESTS_DIR/data >/dev/null 2>&1
      svn export --quiet https://develop.svn.wordpress.org/\$WP_TESTS_TAG/src/ \$WP_TESTS_DIR/src >/dev/null 2>&1
      svn export --quiet https://develop.svn.wordpress.org/\$WP_TESTS_TAG/wp-tests-config-sample.php wp-tests-config.php >/dev/null 2>&1
      sed -i \"s/youremptytestdbnamehere/$DB_NAME/\" wp-tests-config.php
      sed -i \"s/yourusernamehere/$DB_USER/\" wp-tests-config.php
      sed -i \"s/yourpasswordhere/$DB_PASSWORD/\" wp-tests-config.php
      sed -i \"s|localhost|127.0.0.1:$TEST_DB_PORT|\" wp-tests-config.php
      mv wp-tests-config.php \$WP_TESTS_DIR/wp-tests-config.php
      export WP_TESTS_DIR=\$WP_TESTS_DIR
      echo '✅ WordPress test environment ready.'
    fi
    
    echo
    echo '🎯 DEBUG ENVIRONMENT READY!'
    echo '================================'
    echo 'PHPUnit command: '\$PHPUNIT_CMD
    echo
    echo 'Available commands:'
    echo '  '\$PHPUNIT_CMD'                           # Run PHPUnit'
    echo '  '\$PHPUNIT_CMD' tests/test-simple.php     # Run specific test file'
    echo '  '\$PHPUNIT_CMD' --list-tests              # List all available tests'
    echo '  php -l filename.php                     # Check PHP syntax'
    echo '  cat /tmp/wordpress-tests-lib/wp-tests-config.php  # View WP test config'
    echo '  mysql -h127.0.0.1 -P$TEST_DB_PORT -u$DB_USER -p$DB_PASSWORD $DB_NAME  # Connect to test DB'
    echo '  exit                                    # Exit shell'
    echo
    echo 'Environment variables:'
    echo '  WP_TESTS_DIR='\$WP_TESTS_DIR
    echo '  WP_TESTS_PHPUNIT_POLYFILLS_PATH='\$WP_TESTS_PHPUNIT_POLYFILLS_PATH
    echo '  WORDPRESS_DB_HOST=127.0.0.1:$TEST_DB_PORT'
    echo '  WORDPRESS_DB_NAME=$DB_NAME'
    echo '  PHPUNIT_CMD='\$PHPUNIT_CMD
    echo
    echo 'Files in current directory:'
    ls -la
    echo
    echo '🐚 Dropping into interactive shell...'
    echo 'Type \"exit\" to return to host system.'
    echo
    
    # Create a bashrc that sources our environment
    echo 'source /tmp/focus-debug-env.sh' > /tmp/.focus-bashrc
    echo 'echo \"Environment loaded. PHPUNIT_CMD=\$PHPUNIT_CMD\"' >> /tmp/.focus-bashrc
    
    # Start interactive bash shell with our custom bashrc
    exec bash --rcfile /tmp/.focus-bashrc
  "
  exit 0
fi

# --- RUN PHP CONTAINER WITH TEST LOGIC ---
if [ "$DEBUG" = true ]; then
  echo "📦 Running PHPUnit tests in Docker (PHP $PHP_VERSION)..."
  # Run with verbose output
  docker run --rm --name "$PHP_CONTAINER_NAME" \
    -v "$PWD":/app \
    -w /app \
    --network host \
    -e WORDPRESS_DB_HOST=127.0.0.1:"$TEST_DB_PORT" \
    -e WORDPRESS_DB_NAME="$DB_NAME" \
    -e WORDPRESS_DB_USER="$DB_USER" \
    -e WORDPRESS_DB_PASSWORD="$DB_PASSWORD" \
    -e WITH_WP_ENV="$WITH_WP_ENV" \
    -e DEBUG="$DEBUG" \
    -e PHPUNIT_CONFIG="$PHPUNIT_CONFIG" \
    -e PHPUNIT_GROUPS="$PHPUNIT_GROUPS" \
    -e ARGS="$ARGS" \
    php:"$PHP_VERSION"-cli bash -c "
    set -euo pipefail

    if [ \"\$DEBUG\" = \"true\" ]; then
      echo '🔧 Installing dependencies...'
      (apt-get update -qq && apt-get install -y -qq unzip git curl libzip-dev mariadb-client subversion libpng-dev libjpeg-dev libfreetype6-dev) 2>&1 | while IFS= read -r line; do
        printf \"\033[0;34m[apt]\033[0m %s\n\" \"\$line\"
      done
    else
      apt-get update -qq >/dev/null 2>&1 && apt-get install -y -qq unzip git curl libzip-dev mariadb-client subversion libpng-dev libjpeg-dev libfreetype6-dev >/dev/null 2>&1
    fi
    
    if [ \"\$DEBUG\" = \"true\" ]; then
      echo '🔌 Installing PHP extensions...'
      docker-php-ext-install mysqli zip gd 2>&1 | while IFS= read -r line; do
        printf \"\033[0;34m[docker-php-ext]\033[0m %s\n\" \"\$line\"
      done
    else
      docker-php-ext-install mysqli zip gd >/dev/null 2>&1
    fi

    if [ \"\$DEBUG\" = \"true\" ]; then
      echo '📥 Installing Composer...'
      curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer 2>&1 | while IFS= read -r line; do
        printf \"\033[0;32m[composer]\033[0m %s\n\" \"\$line\"
      done
    else
      curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer >/dev/null 2>&1
    fi

    if [ -f composer.json ]; then
      if [ \"\$DEBUG\" = \"true\" ]; then
        echo '📦 Running composer install...'
        composer install --no-interaction --no-progress 2>&1 | while IFS= read -r line; do
          printf \"\033[0;32m[composer]\033[0m %s\n\" \"\$line\"
        done
      else
        composer install --no-interaction --no-progress --quiet >/dev/null 2>&1
      fi
      
      if [ ! -d vendor ]; then
        echo '❌ Missing vendor/. Composer install failed.'
        exit 1
      fi
    else
      if [ \"\$DEBUG\" = \"true\" ]; then
        echo '📝 No composer.json found - assuming WordPress plugin without composer dependencies'
      fi
    fi

    # Use composer-installed PHPUnit if available, otherwise install PHPUnit 10
    if [ -f vendor/bin/phpunit ]; then
      if [ \"\$DEBUG\" = \"true\" ]; then
        echo '✅ Using composer-installed PHPUnit'
      fi
      PHPUNIT_CMD='./vendor/bin/phpunit'
    elif ! command -v phpunit &> /dev/null; then
      if [ \"\$DEBUG\" = \"true\" ]; then
        echo '📥 Installing PHPUnit...'
        (curl -L https://phar.phpunit.de/phpunit-10.phar -o /usr/local/bin/phpunit && chmod +x /usr/local/bin/phpunit) 2>&1 | while IFS= read -r line; do
          printf \"\033[0;33m[phpunit-install]\033[0m %s\n\" \"\$line\"
        done
      else
        curl -L https://phar.phpunit.de/phpunit-10.phar -o /usr/local/bin/phpunit >/dev/null 2>&1 && chmod +x /usr/local/bin/phpunit
      fi
      PHPUNIT_CMD='phpunit'
    else
      PHPUNIT_CMD='phpunit'
    fi

    if [ \"\$WITH_WP_ENV\" = \"true\" ]; then
      if [ \"\$DEBUG\" = \"true\" ]; then
        echo '🧪 Setting up WordPress test environment...'
      fi
      WP_TESTS_DIR=/tmp/wordpress-tests-lib
      rm -rf \\\$WP_TESTS_DIR
      mkdir -p \\\$WP_TESTS_DIR
      # Determine correct SVN path based on WP version
      if [ \"$WP_VERSION\" = \"trunk\" ]; then
        WP_TESTS_TAG=\"trunk\"
      else
        WP_TESTS_TAG=\"tags/$WP_VERSION\"
      fi
      
      if [ \"\$DEBUG\" = \"true\" ]; then
        (svn export --quiet https://develop.svn.wordpress.org/\$WP_TESTS_TAG/tests/phpunit/includes/ \\\$WP_TESTS_DIR/includes &&
         svn export --quiet https://develop.svn.wordpress.org/\$WP_TESTS_TAG/tests/phpunit/data/     \\\$WP_TESTS_DIR/data &&
         svn export --quiet https://develop.svn.wordpress.org/\$WP_TESTS_TAG/src/                    \\\$WP_TESTS_DIR/src &&
         svn export --quiet https://develop.svn.wordpress.org/\$WP_TESTS_TAG/wp-tests-config-sample.php wp-tests-config.php) 2>&1 | while IFS= read -r line; do
          if [ -n \"\$line\" ]; then
            printf \"\033[0;35m[svn]\033[0m %s\n\" \"\$line\"
          fi
        done
      else
        svn export --quiet https://develop.svn.wordpress.org/\$WP_TESTS_TAG/tests/phpunit/includes/ \\\$WP_TESTS_DIR/includes >/dev/null 2>&1 &&
        svn export --quiet https://develop.svn.wordpress.org/\$WP_TESTS_TAG/tests/phpunit/data/     \\\$WP_TESTS_DIR/data >/dev/null 2>&1 &&
        svn export --quiet https://develop.svn.wordpress.org/\$WP_TESTS_TAG/src/                    \\\$WP_TESTS_DIR/src >/dev/null 2>&1 &&
        svn export --quiet https://develop.svn.wordpress.org/\$WP_TESTS_TAG/wp-tests-config-sample.php wp-tests-config.php >/dev/null 2>&1
      fi

      sed -i \"s/youremptytestdbnamehere/$DB_NAME/\" wp-tests-config.php
      sed -i \"s/yourusernamehere/$DB_USER/\" wp-tests-config.php
      sed -i \"s/yourpasswordhere/$DB_PASSWORD/\" wp-tests-config.php
      sed -i \"s|localhost|127.0.0.1:$TEST_DB_PORT|\" wp-tests-config.php

      mv wp-tests-config.php \\\$WP_TESTS_DIR/wp-tests-config.php
      export WP_TESTS_DIR=\\\$WP_TESTS_DIR
      if [ \"\$DEBUG\" = \"true\" ]; then
        echo '✅ WordPress test environment ready.'
      fi
    fi

    if [ \"\$DEBUG\" = \"true\" ]; then
      echo '🚦 Running tests...'
    fi
    
    # Create visual separator for PHPUnit output
    COLS=\$(tput cols 2>/dev/null || echo 80)
    SEPARATOR_LINE=\$(printf '━%.0s' \$(seq 1 \$COLS))
    
    echo \"📋 \$SEPARATOR_LINE\"
    echo \"📋 ✅ PHPUnit Test Results\"
    echo \"📋 \$SEPARATOR_LINE\"
    
    # Run PHPUnit with conditional colored prefix
    if [ \"\$DEBUG\" = \"true\" ]; then
      \$PHPUNIT_CMD \$PHPUNIT_CONFIG \$PHPUNIT_GROUPS \$ARGS 2>&1 | while IFS= read -r line; do
        if [ -n \"\$line\" ]; then
          printf \"\033[0;36m[phpunit]\033[0m %s\n\" \"\$line\"
        else
          echo \"\"
        fi
      done
      PHPUNIT_EXIT=\${PIPESTATUS[0]}
    else
      \$PHPUNIT_CMD \$PHPUNIT_CONFIG \$PHPUNIT_GROUPS \$ARGS
      PHPUNIT_EXIT=\$?
    fi
    
    echo \"\"
    echo \"📋 \$SEPARATOR_LINE\"
    if [ \$PHPUNIT_EXIT -eq 0 ]; then
      echo \"📋 ✅ PHPUnit Tests Complete\"
    else
      echo \"📋 ❌ PHPUnit Tests Failed (exit code: \$PHPUNIT_EXIT)\"
    fi
    echo \"📋 \$SEPARATOR_LINE\"
    
    exit \$PHPUNIT_EXIT
  "
else
  # Run non-debug mode with spinner for setup
  {
    docker run --rm --name "$PHP_CONTAINER_NAME" \
      -v "$PWD":/app \
      -w /app \
      --network host \
      -e WORDPRESS_DB_HOST=127.0.0.1:"$TEST_DB_PORT" \
      -e WORDPRESS_DB_NAME="$DB_NAME" \
      -e WORDPRESS_DB_USER="$DB_USER" \
      -e WORDPRESS_DB_PASSWORD="$DB_PASSWORD" \
      -e WITH_WP_ENV="$WITH_WP_ENV" \
      -e DEBUG="$DEBUG" \
      -e PHPUNIT_CONFIG="$PHPUNIT_CONFIG" \
      -e PHPUNIT_GROUPS="$PHPUNIT_GROUPS" \
      -e ARGS="$ARGS" \
      php:"$PHP_VERSION"-cli bash -c "
        set -euo pipefail
        apt-get update -qq >/dev/null 2>&1 && apt-get install -y -qq unzip git curl libzip-dev mariadb-client subversion libpng-dev libjpeg-dev libfreetype6-dev >/dev/null 2>&1
        docker-php-ext-configure gd --with-freetype --with-jpeg >/dev/null 2>&1 && docker-php-ext-install mysqli zip gd >/dev/null 2>&1
        curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer >/dev/null 2>&1
        
        if [ -f vendor/bin/phpunit ]; then
          PHPUNIT_CMD='./vendor/bin/phpunit'
        elif [ -f composer.json ]; then
          composer install --no-interaction --no-progress --quiet >/dev/null 2>&1
          # Install PHPUnit Polyfills if needed for WordPress tests
          if [ ! -d vendor/yoast/phpunit-polyfills ]; then
            composer require --dev yoast/phpunit-polyfills --no-interaction --quiet >/dev/null 2>&1
          fi
          PHPUNIT_CMD='./vendor/bin/phpunit'
          export WP_TESTS_PHPUNIT_POLYFILLS_PATH=/app/vendor/yoast/phpunit-polyfills
        else
          curl -L https://phar.phpunit.de/phpunit-10.phar -o /usr/local/bin/phpunit >/dev/null 2>&1 && chmod +x /usr/local/bin/phpunit
          PHPUNIT_CMD='phpunit'
          # Install PHPUnit Polyfills manually for WordPress tests
          mkdir -p /tmp/phpunit-polyfills >/dev/null 2>&1
          curl -L https://github.com/Yoast/PHPUnit-Polyfills/archive/refs/heads/main.zip -o /tmp/polyfills.zip >/dev/null 2>&1
          cd /tmp && unzip -q polyfills.zip >/dev/null 2>&1 && mv PHPUnit-Polyfills-main/* /tmp/phpunit-polyfills/ >/dev/null 2>&1 && cd /app
          export WP_TESTS_PHPUNIT_POLYFILLS_PATH=/tmp/phpunit-polyfills
        fi

        if [ \"\$WITH_WP_ENV\" = \"true\" ]; then
          WP_TESTS_DIR=/tmp/wordpress-tests-lib
          rm -rf \$WP_TESTS_DIR && mkdir -p \$WP_TESTS_DIR
          # Determine correct SVN path based on WP version
          if [ \"$WP_VERSION\" = \"trunk\" ]; then
            WP_TESTS_TAG=\"trunk\"
          else
            WP_TESTS_TAG=\"tags/$WP_VERSION\"
          fi
          svn export --quiet https://develop.svn.wordpress.org/\$WP_TESTS_TAG/tests/phpunit/includes/ \$WP_TESTS_DIR/includes >/dev/null 2>&1
          svn export --quiet https://develop.svn.wordpress.org/\$WP_TESTS_TAG/tests/phpunit/data/ \$WP_TESTS_DIR/data >/dev/null 2>&1
          svn export --quiet https://develop.svn.wordpress.org/\$WP_TESTS_TAG/src/ \$WP_TESTS_DIR/src >/dev/null 2>&1
          svn export --quiet https://develop.svn.wordpress.org/\$WP_TESTS_TAG/wp-tests-config-sample.php wp-tests-config.php >/dev/null 2>&1
          sed -i \"s/youremptytestdbnamehere/$DB_NAME/\" wp-tests-config.php
          sed -i \"s/yourusernamehere/$DB_USER/\" wp-tests-config.php
          sed -i \"s/yourpasswordhere/$DB_PASSWORD/\" wp-tests-config.php
          sed -i \"s|localhost|127.0.0.1:$TEST_DB_PORT|\" wp-tests-config.php
          mv wp-tests-config.php \$WP_TESTS_DIR/wp-tests-config.php
          export WP_TESTS_DIR=\$WP_TESTS_DIR
        fi

        COLS=\$(tput cols 2>/dev/null || echo 80)
        SEPARATOR_LINE=\$(printf '━%.0s' \$(seq 1 \$COLS))
        echo \"📋 \$SEPARATOR_LINE\"
        echo \"📋 ✅ PHPUnit Test Results\"
        echo \"📋 \$SEPARATOR_LINE\"
        \$PHPUNIT_CMD \$PHPUNIT_CONFIG \$PHPUNIT_GROUPS \$ARGS --verbose
        PHPUNIT_EXIT=\$?
        echo \"\"
        echo \"📋 \$SEPARATOR_LINE\"
        if [ \$PHPUNIT_EXIT -eq 0 ]; then
          echo \"📋 ✅ PHPUnit Tests Complete\"
        else
          echo \"📋 ❌ PHPUnit Tests Failed (exit code: \$PHPUNIT_EXIT)\"
        fi
        echo \"📋 \$SEPARATOR_LINE\"
        exit \$PHPUNIT_EXIT
      "
  } &
  
  docker_pid=$!
  wait $docker_pid
  exit $?
fi