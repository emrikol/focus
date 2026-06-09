# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

FOCUS Object Cache is a WordPress plugin that implements a persistent object-cache drop-in for WordPress. It supports file-backed storage by default and an optional custom database-table backend when dedicated object-cache services like Redis or Memcached are not available.

## Development Commands

### JavaScript/CSS Linting

```bash
npm run lint:js          # Lint JavaScript files
npm run lint:js:fix      # Auto-fix JavaScript linting issues
npm run lint:css         # Lint CSS/style files
npm run lint:md:docs     # Lint Markdown documentation
npm run lint:pkg-json    # Lint package.json
```

### PHP Code Standards

```bash
composer lint      # Run PHP CodeSniffer
composer lint:fix  # Auto-fix PHP coding standards issues
```

### Testing

```bash
./run-tests.sh                     # Run single-site tests only
./run-tests.sh --all               # Run ALL tests (single-site + multisite) - RECOMMENDED
./run-tests.sh --multisite         # Run multisite tests only
./run-tests.sh --debug             # Run tests with verbose output
./run-tests.sh --php 8.1           # Test with specific PHP version (8.1, 8.2, etc.)
./run-tests.sh --wp 7.0            # Test with specific WordPress version
./run-tests.sh --shell             # Interactive debugging shell
./run-tests.sh --lint              # Run PHP syntax check only
./run-tests.sh --cleanup           # Clean up Docker test containers and images
./run-tests.sh --filter test_cache # Run specific test methods/classes
phpunit                            # Run tests directly (requires local setup)
```

**⚠️ IMPORTANT:** For complete test coverage, always use `./run-tests.sh --all` which runs both single-site and multisite tests sequentially.

#### Test Detail and Debugging Options

The test runner accepts any PHPUnit flag for detailed output:

```bash
# Get detailed information about all test results (including skipped tests)
./run-tests.sh --all --verbose --testdox           # Complete test suite with details
./run-tests.sh --verbose --testdox                 # Single-site tests with details
./run-tests.sh --multisite --verbose --testdox     # Multisite tests with details

# Stop on failures for immediate debugging
./run-tests.sh --all --stop-on-failure --verbose   # Complete suite, stop on first failure
./run-tests.sh --stop-on-failure --verbose         # Single-site only
./run-tests.sh --multisite --stop-on-failure --verbose # Multisite only

# List all available tests without running them
./run-tests.sh --list-tests                        # Single-site tests
./run-tests.sh --multisite --list-tests            # Multisite tests

# Run specific test groups
./run-tests.sh --all --group focus --verbose       # All tests in 'focus' group
./run-tests.sh --group cache --testdox             # Single-site cache tests
./run-tests.sh --multisite --group cache --testdox # Multisite cache tests

# Maximum detail for troubleshooting (recommended)
./run-tests.sh --all --verbose --testdox --stop-on-failure      # Complete suite
./run-tests.sh --verbose --testdox --stop-on-failure            # Single-site
./run-tests.sh --multisite --verbose --testdox --stop-on-failure # Multisite
```

**Key flags for detailed test information:**

- `--verbose`: Shows detailed test output and reasons for skipped tests
- `--testdox`: Human-readable test names with ✔/↩/❌ icons
- `--stop-on-failure`: Stops immediately when a test fails
- `--stop-on-skipped`: Stops immediately when a test is skipped
- `--coverage-text`: Shows test coverage (requires Xdebug)

**IMPORTANT**: Always run `./run-tests.sh` with a timeout to prevent infinite loops:

```bash
timeout 300 ./run-tests.sh  # 5 minute timeout
```

**NOTE**: The test runner now uses cached Docker images and volumes for faster execution (~8.5s vs ~32s). The first time using a new PHP version (e.g., `--php 8.1`) will take longer as it builds the cached image.

#### Multisite Testing

The `--multisite` flag runs tests in a WordPress multisite environment:

- **Single-site mode** (default): Tests run against a standard WordPress installation
- **Multisite mode** (`--multisite`): Tests run against a WordPress network with multisite enabled

**Key differences when running multisite tests:**

- WordPress network is installed during setup ("Installing network...")
- Tests run with `WP_TESTS_MULTISITE=1` constant
- All object cache functionality is tested in a multisite context
- Configuration file: `tests/phpunit/multisite.xml`

**Example multisite test combinations:**

```bash
./run-tests.sh --multisite --filter test_cache              # Run specific cache tests in multisite
./run-tests.sh --multisite --filter Test_FOCUS_Multisite    # Run all FOCUS multisite-specific tests
./run-tests.sh --multisite --debug                          # Debug multisite issues
./run-tests.sh --multisite --php 8.1                        # Test multisite with PHP 8.1
```

**Test Coverage Overview:**

**Core Cache Functionality (`tests/test-focus-cache.php`):**

- File and database storage implementations
- Cache expiration via file modification time
- Configuration constants (WP_FOCUS_MAXTTL, WP_CACHE_KEY_SALT)
- Non-persistent groups and security measures
- FOCUS-specific features and error handling

**WordPress Core Compatibility (`tests/test-focus-core-compat.php`):**

- Key validation tests (replicating core WordPress tests skipped for external caches)
- Flush functionality across memory and persistent storage
- Mixed data type handling (objects, arrays, primitives, null, boolean)
- Edge cases (whitespace keys, long keys, special characters)
- Integration with wp_cache_* functions

**Multisite Functionality (`tests/test-focus-multisite.php`):**

- Blog isolation (cache data separation between sites)
- Global cache groups (shared data across the network)
- Cache key prefixing with blog IDs
- File system organization by blog
- Multisite-specific operations (increment/decrement, deletion)
- WordPress default global groups compatibility

### Build and Release

```bash
composer readme          # Convert readme.txt to readme.md
composer build:release   # Create release package in /dist
```

## Architecture

### Core Components

**Main Plugin File (`focus.php`)**

- Loads the `FOCUS_Cache` class which handles admin interface and plugin lifecycle
- Manages enabling/disabling the object cache drop-in
- Provides admin UI for cache management in WordPress admin

**Object Cache Drop-in (`includes/object-cache.php`)**

- Implements the object cache drop-in that replaces WordPress's default object cache
- Supports file and database storage backends
- Supports cache groups, expiration, batch operations, prefetch, and WordPress multisite

**Admin Interface (`includes/admin-page.php`)**

- Provides HTML template for the settings page
- Shows cache status, configuration options, and management buttons

### Cache Storage Strategy

- File backend cache files are stored in `/wp-content/focus-object-cache/[group]/[key].php`
- Database backend cache rows are stored in custom transient cache tables
- Supports cache key salting via `WP_CACHE_KEY_SALT` constant
- Supports optional request prefetching via `WP_FOCUS_CACHE_PREFETCH`
- Prefetch saves grouped runtime cache keys at shutdown, hydrates matching requests early, excludes non-persistent/internal groups, and uses `WP_FOCUS_PREFETCH_TTL` for manifest expiry
- Database prefetch uses `focus_cache_prefetch_keys` plus joined batch reads from `focus_cache_items`, and carries requested-but-missing keys into the next manifest
- Query Monitor includes Object Cache panels and a FOCUS Prefetch subpanel for requested, loaded, missing, used, unused, and estimated savings metrics

### Configuration Constants

- `WP_FOCUS_MAXTTL`: Maximum cache expiration time (default: 1 year)
- `WP_CACHE_KEY_SALT`: Cache key prefix for uniqueness
- `WP_FOCUS_BACKEND`: Persistent backend, either `file` or `database`
- `WP_FOCUS_CACHE_PREFETCH`: Enables request prefetching
- `WP_FOCUS_PREFETCH_TTL`: Prefetch manifest TTL in seconds (default: 300)

### WordPress Integration

The plugin operates as a WordPress "drop-in" - it copies `object-cache.php` to `/wp-content/` to override WordPress's default caching behavior. This allows persistent caching without requiring additional server software.

## Code Standards

- Follows WordPress coding standards (enforced by PHPCS)
- Uses the `Emrikol` custom PHPCS standard (type safety, namespace validation, docblock enforcement)
- PHP 8.0+ compatibility required
- WordPress 6.5+ minimum version, tested through WordPress 7.0
- Supports multisite installations
- Uses `focus` text domain for internationalization
- All global functions/classes prefixed with `focus` or `FOCUS`

## Testing Environment

Tests use Docker containers with:

- PHP 8.2 with MariaDB 10.6
- WordPress 7.0 test environment by default
- Automatic WordPress test suite setup via SVN
- Custom test runner script handles container lifecycle

## Code Quality Process

**IMPORTANT**: After making any code changes, always run these commands in order:

**For PHP files:**

1. **Auto-fix violations**: `composer lint:fix` (fixes what it can automatically)
2. **Check remaining issues**: `composer lint` (reports remaining violations)
3. **Fix manually**: Address any remaining PHPCS violations
4. **Never ignore**: Do not add `phpcs:ignore` comments unless the user explicitly requests it

**For JavaScript files:**

1. **Auto-fix violations**: `npm run lint:js -- --fix` (fixes what it can automatically)
2. **Check remaining issues**: `npm run lint:js` (reports remaining violations)
3. **Fix manually**: Address any remaining ESLint violations
4. **Never ignore**: Do not add `eslint-disable` comments unless the user explicitly requests it

**Example workflow:**

```bash
# Make code changes to PHP files
# Then run:

# For PHP:
composer lint:fix             # Auto-fix formatting, spacing, etc.
composer lint                 # Check for remaining violations

# For JavaScript:
npm run lint:js -- --fix  # Auto-fix ESLint violations
npm run lint:js           # Check for remaining violations

# Fix any reported issues manually
# Ask user for guidance if unsure how to fix something
```

**Important Notes:**

**For PHP:**

- **Standards are automatic**: `.phpcs.xml.dist` configures everything including the `Emrikol` custom standard (type safety, namespace validation, docblock enforcement)
- `composer lint:fix` runs `phpcbf` — automatically fixes many formatting issues
- `composer lint` runs `phpcs` — reports remaining violations that need manual fixing
- Only add `phpcs:ignore` comments when the user specifically instructs you to do so
- Always ask the user for guidance if you're unsure how to fix a PHPCS violation

**For JavaScript:**

- Uses `@wordpress/scripts` ESLint configuration which follows WordPress JavaScript standards
- `npm run lint:js -- --fix` automatically fixes many formatting and style issues
- `npm run lint:js` reports remaining violations that need manual fixing
- Only add `eslint-disable` comments when the user specifically instructs you to do so
- Always ask the user for guidance if you're unsure how to fix an ESLint violation
