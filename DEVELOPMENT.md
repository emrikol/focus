# FOCUS Object Cache - Development Guide

This guide covers everything you need to know for developing, testing, and debugging the FOCUS Object Cache WordPress plugin.

## Table of Contents

- [Quick Start](#quick-start)
- [Development Environment](#development-environment)
- [Testing](#testing)
- [Debugging](#debugging)
- [Code Standards](#code-standards)
- [Architecture](#architecture)
- [Troubleshooting](#troubleshooting)

## Quick Start

1. **Clone and setup:**
   ```bash
   git clone <repository-url>
   cd focus
   ```

2. **Run tests:**
   ```bash
   ./run-tests.sh
   ```

3. **Debug issues:**
   ```bash
   ./run-tests.sh --shell
   ```

## Development Environment

### Requirements

- **Docker** (for testing environment)
- **PHP 8.0+** (for local development)
- **Git**

### Docker-based Testing

The project uses Docker for isolated, consistent testing across environments. The test runner automatically:

- Sets up PHP 8.2 environment
- Installs WordPress 7.0 test suite by default
- Configures MariaDB 10.6 database
- Installs PHPUnit and dependencies

## Testing

### Test Runner Commands

```bash
# Run all tests
./run-tests.sh

# Run with verbose debug output
./run-tests.sh --debug

# Run multisite tests
./run-tests.sh --multisite

# Run specific test groups
./run-tests.sh --ajax
./run-tests.sh --ms-files
./run-tests.sh --external-http

# Combine options
./run-tests.sh --multisite --ajax --debug

# Clean up Docker resources
./run-tests.sh --cleanup

# Get help
./run-tests.sh --help
```

### Interactive Debugging Shell

For debugging test failures and exploring the test environment:

```bash
./run-tests.sh --shell
```

This drops you into an interactive bash shell inside the Docker container with:

- Full WordPress test environment setup
- PHPUnit installed and configured
- Database connection ready
- All your local files mounted and editable

### Available Commands in Debug Shell

The environment automatically loads with `$PHPUNIT_CMD` set to the correct PHPUnit command (either `./vendor/bin/phpunit` or `phpunit`).

```bash
# Run PHPUnit tests (the variable is automatically set for you)
$PHPUNIT_CMD                           # Run all tests
$PHPUNIT_CMD tests/test-simple.php     # Run specific test file
$PHPUNIT_CMD tests/test-cache.php      # Run cache tests
$PHPUNIT_CMD --list-tests              # List all available tests
$PHPUNIT_CMD --debug                   # Run with debug output

# If $PHPUNIT_CMD is empty, manually source the environment:
source /tmp/focus-debug-env.sh

# PHP debugging
php -l filename.php                    # Check PHP syntax
php -r "your_test_code_here"           # Execute PHP code

# Environment inspection
cat /tmp/wordpress-tests-lib/wp-tests-config.php  # View WP test config
ls -la /tmp/wordpress-tests-lib/       # Browse WordPress test files
env | grep -E "WP_|WORDPRESS_"         # View WordPress environment variables

# Database access
mysql -h127.0.0.1 -P3307 -uwordpress -ppassword wordpress

# File editing
vim filename.php                       # Edit files (or use your local editor)
cat filename.php                       # View file contents
```

### Test File Structure

```
tests/
├── bootstrap.php          # Test environment setup
├── test-cache.php         # Object cache functionality tests
├── test-simple.php        # Basic functionality tests
└── phpunit/
    └── multisite.xml      # Multisite test configuration
```

### Live File Editing

Your local project directory is mounted into Docker at `/app`, so:

✅ **Edit files locally** with your preferred editor (VS Code, PhpStorm, etc.)  
✅ **Changes are immediately available** in the Docker container  
✅ **No need to restart** the container after file changes  
✅ **Test changes instantly** with `$PHPUNIT_CMD`

**Example workflow:**
1. Start debug shell: `./run-tests.sh --shell`
2. Edit `tests/bootstrap.php` locally in your IDE
3. Run test in Docker: `$PHPUNIT_CMD tests/test-simple.php`
4. See changes immediately!

## Debugging

### Common Debugging Scenarios

#### Test Failures
```bash
# Start debug shell
./run-tests.sh --shell

# Run specific failing test with verbose output
$PHPUNIT_CMD tests/test-cache.php --verbose --debug

# Check PHP syntax
php -l tests/test-cache.php
php -l includes/object-cache.php
```

#### WordPress Setup Issues
```bash
# Check WordPress test environment
cat /tmp/wordpress-tests-lib/wp-tests-config.php

# Test WordPress bootstrap manually
php -r "require '/tmp/wordpress-tests-lib/includes/bootstrap.php'; echo 'WordPress loaded successfully';"

# Check available WordPress functions
php -r "require '/tmp/wordpress-tests-lib/includes/bootstrap.php'; var_dump(function_exists('wp_cache_set'));"
```

#### Database Connection Issues
```bash
# Test database connection
mysql -h127.0.0.1 -P3307 -uwordpress -ppassword wordpress -e "SHOW TABLES;"

# Check connection from PHP
php -r "
$pdo = new PDO('mysql:host=127.0.0.1:3307;dbname=wordpress', 'wordpress', 'password');
echo 'Database connection successful';
"
```

#### Object Cache Issues
```bash
# Test object cache initialization
php -r "
require 'includes/object-cache.php';
\$cache = new WP_Object_Cache();
echo 'Object cache created successfully';
"

# Check cache directory permissions
ls -la /tmp/
ls -la /app/
```

### Debug Logging

Add debug statements to your PHP files:

```php
// In bootstrap.php or test files
error_log("FOCUS DEBUG: " . __FILE__ . ":" . __LINE__);
echo "FOCUS DEBUG: Variable value = " . var_export($variable, true) . "\n";

// In object-cache.php
file_put_contents('/tmp/focus-debug.log', "Cache operation: $key\n", FILE_APPEND);
```

## Code Standards

### PHP Code Standards

The project follows WordPress coding standards. Always run these commands after making changes:

```bash
# Auto-fix formatting issues
phpcbf --extensions=php .

# Check for remaining violations  
phpcs --extensions=php .

# For specific files
phpcbf --extensions=php focus.php includes/object-cache.php
phpcs --extensions=php focus.php includes/object-cache.php
```

### JavaScript Code Standards

```bash
# Auto-fix JavaScript issues
npm run lint:js -- --fix

# Check for remaining violations
npm run lint:js
```

### Important Rules

- **Never ignore coding standards** unless explicitly requested
- **Run PHPCS/PHPCBF** before committing changes
- **Ask for guidance** if unsure how to fix a violation
- **Use project root** for running coding standards tools

## Architecture

### Core Components

```
focus/
├── focus.php                    # Main plugin file
├── includes/
│   ├── class-focus-cache.php    # Admin interface class
│   └── object-cache.php         # Drop-in object cache implementation
├── tests/                       # Test suite
└── run-tests.sh                # Docker test runner
```

### Object Cache Implementation

The FOCUS Object Cache (`includes/object-cache.php`) implements WordPress's object cache API using file or database-backed storage:

- **File backend storage:** `/wp-content/focus-object-cache/[group]/[key].php`
- **Database backend storage:** Custom transient cache tables using deterministic bucket and key hashes
- **Data encoding:** Serialized PHP data
- **Expiration:** File modification time for the file backend, `expires_at` rows for the database backend
- **Multisite support:** Separate cache per blog

### Key Constants

```php
WP_CACHE_KEY_SALT    // Cache key uniqueness (default: '')
WP_FOCUS_MAXTTL      // Maximum cache TTL (default: 1 year)
WP_FOCUS_BACKEND     // Persistent backend: 'file' or 'database'
WP_FOCUS_CACHE_PREFETCH // Optional request prefetching
CACHE_PATH           // Custom cache directory (optional)
```

### WordPress Integration

The plugin operates as a WordPress "drop-in":

1. Copies `object-cache.php` to `/wp-content/` during activation
2. WordPress automatically loads the drop-in
3. Replaces default non-persistent cache with persistent file or database-backed cache

## Troubleshooting

### Common Issues

#### `$PHPUNIT_CMD` Variable Empty
If the `$PHPUNIT_CMD` variable is empty in the debug shell:

```bash
# Manually source the environment file
source /tmp/focus-debug-env.sh

# Verify it's now set
echo "$PHPUNIT_CMD"

# Alternative: use the command directly
./vendor/bin/phpunit tests/test-simple.php  # If composer.json exists
# OR
phpunit tests/test-simple.php               # If using system PHPUnit
```

#### PHPUnit Polyfills Error
If you see "The PHPUnit Polyfills library is a requirement for running the WP test suite":

```bash
# The test runner automatically installs PHPUnit Polyfills
# Check if the environment variable is set:
echo "$WP_TESTS_PHPUNIT_POLYFILLS_PATH"

# Should show either:
# /app/vendor/yoast/phpunit-polyfills (if using Composer)
# /tmp/phpunit-polyfills (if installed manually)

# If missing, manually source the environment:
source /tmp/focus-debug-env.sh
```

#### `assert(!empty($name))` Error
This WordPress core assertion error typically indicates missing globals during initialization:

```bash
# Debug in shell
./run-tests.sh --shell

# Check global variables
php -r "var_dump(\$GLOBALS['blog_id']); var_dump(defined('WP_CONTENT_DIR'));"

# Test object cache initialization
php -r "require 'includes/object-cache.php'; echo 'Success';"
```

#### Docker Issues
```bash
# Clean up all test containers and volumes
./run-tests.sh --cleanup

# Check Docker status
docker ps
docker images

# Free up space
docker system prune
```

#### Port Conflicts
If port 3307 is in use:
```bash
# Check what's using the port
lsof -Pi :3307 -sTCP:LISTEN

# The test runner will attempt to clean up automatically
```

#### Permission Issues
```bash
# Check file permissions
ls -la includes/object-cache.php
ls -la tests/

# Fix if needed (rarely required)
chmod 644 includes/object-cache.php
chmod 755 tests/
```

### Getting Help

1. **Start with the debug shell:** `./run-tests.sh --shell`
2. **Check PHP syntax:** `php -l filename.php`
3. **Run PHPCS:** `phpcs --extensions=php .`
4. **Check WordPress setup:** `cat /tmp/wordpress-tests-lib/wp-tests-config.php`
5. **Test database:** `mysql -h127.0.0.1 -P3307 -uwordpress -ppassword wordpress`

### Performance Testing

```bash
# Time cache operations
php -r "
require 'includes/object-cache.php';
\$cache = new WP_Object_Cache();
\$start = microtime(true);
for (\$i = 0; \$i < 1000; \$i++) {
    \$cache->set('test' . \$i, 'data' . \$i);
}
echo 'Set 1000 items: ' . (microtime(true) - \$start) . ' seconds';
"
```

### Release Process

1. **Update version numbers** in `focus.php` and `includes/object-cache.php`
2. **Run full test suite:** `./run-tests.sh`
3. **Check coding standards:** `phpcs --extensions=php .`
4. **Test with WordPress:** Install in local WordPress instance
5. **Create release package:** `composer build:release`

---

## Need Help?

- **Test failures:** Use `./run-tests.sh --shell` for interactive debugging
- **Code standards:** Run `phpcs --extensions=php .` and `phpcbf --extensions=php .`
- **WordPress integration:** Check `/tmp/wordpress-tests-lib/` in debug shell
- **Docker issues:** Try `./run-tests.sh --cleanup` first
