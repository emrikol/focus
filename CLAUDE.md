# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

FOCUS Object Cache is a WordPress plugin that implements file-based object caching as a drop-in replacement for WordPress's default non-persistent object cache. The plugin provides persistent caching using the local filesystem when database-based caching solutions like Redis or Memcached are not available.

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
phpcs --extensions=php . # Run PHP CodeSniffer using phpcs.ruleset.xml
phpcbf --extensions=php . # Auto-fix PHP coding standards issues
```

### Testing
```bash
./run-tests.sh           # Run PHPUnit tests in Docker environment
./run-tests.sh --debug   # Run tests with verbose output
./run-tests.sh --multisite # Run multisite-specific tests
./run-tests.sh --cleanup # Clean up Docker test containers
phpunit                  # Run tests directly (requires local setup)
```

### Build and Release
```bash
grunt readme             # Convert readme.txt to readme.md
grunt release            # Create release package in /release directory
grunt version:patch      # Bump version numbers across files
```

## Architecture

### Core Components

**Main Plugin File (`focus.php`)**
- Contains the `FOCUS_Cache` class which handles admin interface and plugin lifecycle
- Manages enabling/disabling the object cache drop-in
- Provides admin UI for cache management in WordPress admin

**Object Cache Drop-in (`includes/object-cache.php`)**
- Implements `WP_Object_Cache` class that replaces WordPress's default object cache
- Stores cache data as serialized, base64-encoded files in `/wp-content/focus-object-cache/`
- Supports cache groups, expiration, and WordPress multisite
- Uses file modification time for expiration tracking

**Admin Interface (`includes/admin-page.php`)**
- Provides HTML template for the settings page
- Shows cache status, configuration options, and management buttons

### Cache Storage Strategy

- Cache files stored in `/wp-content/focus-object-cache/[group]/[key].php`
- Files contain PHP comment headers/footers to prevent direct execution
- Uses base64 encoding and PHP serialization for data storage
- File modification time represents cache expiration timestamp
- Supports cache key salting via `WP_CACHE_KEY_SALT` constant

### Configuration Constants

- `WP_FOCUS_MAXTTL`: Maximum cache expiration time (default: 1 year)
- `WP_CACHE_KEY_SALT`: Cache key prefix for uniqueness
- `CACHE_PATH`: Custom cache directory path (optional)

### WordPress Integration

The plugin operates as a WordPress "drop-in" - it copies `object-cache.php` to `/wp-content/` to override WordPress's default caching behavior. This allows persistent caching without requiring additional server software.

## Code Standards

- Follows WordPress coding standards (enforced by PHPCS)
- PHP 8.0+ compatibility required
- WordPress 6.5+ minimum version
- Supports multisite installations
- Uses `focus` text domain for internationalization
- All global functions/classes prefixed with `focus` or `FOCUS`

## Testing Environment

Tests use Docker containers with:
- PHP 8.2 with MariaDB 10.6
- WordPress 6.5 test environment
- Automatic WordPress test suite setup via SVN
- Custom test runner script handles container lifecycle

## Code Quality Process

**IMPORTANT**: After making any code changes, always run these commands in order:

**For PHP files:**
1. **Auto-fix violations**: `phpcbf --extensions=php .` or `phpcbf --extensions=php path/to/file.php` (fixes what it can automatically)
2. **Check remaining issues**: `phpcs --extensions=php .` or `phpcs --extensions=php path/to/file.php` (reports remaining violations)
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

# For PHP (from project root):
phpcbf --extensions=php .               # Auto-fix formatting, spacing, etc.
phpcs --extensions=php .                # Check for remaining violations
# Or for specific files:
phpcbf --extensions=php focus.php includes/object-cache.php
phpcs --extensions=php focus.php includes/object-cache.php

# For JavaScript:
npm run lint:js -- --fix  # Auto-fix ESLint violations
npm run lint:js           # Check for remaining violations

# Fix any reported issues manually
# Ask user for guidance if unsure how to fix something
```

**Important Notes:**

**For PHP:**
- **Run from project root**: Use `phpcs --extensions=php .` or `phpcbf --extensions=php .` from the plugin directory
- **Avoid memory issues**: The `--extensions=php` flag prevents scanning large files that cause memory exhaustion
- **Uses project ruleset**: Commands automatically use the `phpcs.ruleset.xml` configuration
- `phpcbf` (PHP Code Beautifier and Fixer) automatically fixes many formatting issues
- `phpcs` (PHP_CodeSniffer) reports remaining violations that need manual fixing
- Only add `phpcs:ignore` comments when the user specifically instructs you to do so
- Always ask the user for guidance if you're unsure how to fix a PHPCS violation

**For JavaScript:**
- Uses `@wordpress/scripts` ESLint configuration which follows WordPress JavaScript standards
- `npm run lint:js -- --fix` automatically fixes many formatting and style issues
- `npm run lint:js` reports remaining violations that need manual fixing
- Only add `eslint-disable` comments when the user specifically instructs you to do so
- Always ask the user for guidance if you're unsure how to fix an ESLint violation