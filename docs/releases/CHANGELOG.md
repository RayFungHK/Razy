# Changelog

All notable changes to Razy v0.5 will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.5.4] - 2026-02-10

### Added
- **PSR-16 Cache System** — Full implementation of PSR-16 SimpleCache for the Razy framework
  - `Razy\Cache` static facade with auto-initialization, enable/disable toggle, and adapter swapping
  - `Razy\Cache\CacheInterface` — PSR-16 compatible interface with get/set/delete/clear/has/batch operations
  - `Razy\Cache\FileAdapter` — File-based cache with directory sharding, atomic writes, TTL support, GC, and stats
  - `Razy\Cache\ApcuAdapter` — High-performance APCu shared memory adapter with namespace prefixing
  - `Razy\Cache\NullAdapter` — No-op adapter for disabling cache transparently
  - File validation caching via `Cache::getValidated()` / `Cache::setValidated()` for mtime-based auto-invalidation
  - `CACHE_FOLDER` constant defined in bootstrap (`data/cache/`)
  - Integrated into framework components for automatic cross-request caching:
    - `YAML::parseFile()` — Caches parsed YAML data, auto-invalidates when file changes
    - `Configuration` constructor — Caches parsed JSON/INI/YAML configs with mtime validation
    - `Distributor::scanModule()` — Caches module manifest with directory signature validation
  - New CLI command: `php Razy.phar cache [clear|gc|stats|status]`
  - 66 new unit tests (128 assertions) covering all adapters, facade, TTL, validation, and edge cases
- **Module Repository System** - Complete workflow for packaging, publishing, and distributing modules
  - `RepositoryManager` class for repository index management and module search
  - `pack` command - Package modules as .phar files for distribution
    - Creates versioned .phar archives with manifest.json
    - Generates SHA256 checksums for integrity verification
    - Options: `--no-compress`, `--no-assets`
  - `publish` command - Generate repository index from packaged modules
    - Scans packages/ directory and aggregates manifest.json files
    - Creates master index.json for repository
    - Options: `--verbose`, `--dry-run`
    - **GitHub API Push**: Upload packages directly to GitHub repository
      - `--push` flag with `--token` and `--repo` options
      - Supports custom branch (`--branch`) and commit message (`--message`)
      - Automatically creates/updates files via GitHub Contents API
  - `search` command - Search modules from configured repositories
    - Searches across all configured repositories
    - Displays module details (versions, description, author)
    - Options: `--verbose`, `--refresh`
  - `install --from-repo` flag - Install modules from configured repositories
    - Resolves module code to download URL via RepositoryManager
    - Supports version specification (`vendor/module@1.0.0`)
    - Displays `disclaimer.txt` if present in module package folder
    - Requires user acceptance of `terms.txt` if present (type `yes` or `agree`)
    - Shows module info (description, author, versions) before prompting
    - **Dependency Resolution**: Automatically detects and installs required modules
      - Reads `require` section from installed module's `module.php`
      - Checks if dependencies are already installed (shared or distributor)
      - Prompts user to install missing dependencies
      - Downloads and installs from configured repositories
  - Repository structure documentation in [REPOSITORY-SYSTEM.md](../guides/REPOSITORY-SYSTEM.md)

### Removed
- **commit command** - Superseded by the new `pack` command
  - `pack` provides complete distribution packaging with manifest.json, checksums, and repository support
  - For local versioning, use direct folder copy or version control

### Changed
- **RepositoryManager.buildRawUrl()** - Now public method for building raw content URLs
- **Prerequisite Version Validation** - Modules now validate installed package versions at load time
  - If installed version doesn't satisfy module's constraint, module fails to load with clear error
  - Error message includes package name, required constraint, and resolution command
  - `compose` command reports version conflicts before downloading packages
  - Tracks which module registered which prerequisite for better debugging
- **Composer Demo Modules** - New demo modules showcasing version conflict resolution
  - `demo_modules/system/markdown_service/` - Shared service pattern wrapping league/commonmark
  - `demo_modules/demo/markdown_consumer/` - Consumer using service API (version-conflict-free)
  - Documented Shared Service Pattern for resolving same-distributor version conflicts
- **runapp CLI Command** - Interactive shell for running distributors without sites.inc.php
  - `php Razy.phar runapp <dist_code[@tag]>` starts interactive shell
  - Bash-like prompt with distributor code prefix: `[distCode]>`
  - Shell commands: `help`, `info`, `routes`, `modules`, `api`, `run <path>`, `call <api> <cmd>`, `exit`
  - Supports piped input for scripting: `@("routes", "exit") | php Razy.phar runapp appdemo`
  - Cross-platform: Windows PowerShell UTF-8 BOM handling, `stream_isatty()` for TTY detection
- **Playground Demo Modules** - Demo modules for runapp testing
  - `playground/sites/appdemo/demo/hello/` - Basic routes (index, hello, greet, info, time, json)
  - `playground/sites/appdemo/demo/api/` - API module with commands (getData, calculate, echo)
- **GitHub Module Installer** - New CLI command to download and install modules from GitHub
  - `install` command for terminal/CLI operations
  - `RepoInstaller` class for programmatic installation
  - Support for public and private repositories (with personal access token)
  - Multiple installation modes:
    - Branch installation (default: main)
    - Release installation (latest tagged release)
    - Distributor module installation
    - Custom path installation
  - Repository format support:
    - Short format: `owner/repo` or `owner/repo@branch`
    - Full URL: `https://github.com/owner/repo`
  - Features:
    - Real-time download progress tracking
    - Repository validation before download
    - Overwrite protection with user confirmation
    - Auto-path detection and creation
    - Post-install tips (README, composer.json)
  - Options:
    - `--release` / `-r` - Download latest release
    - `--branch=NAME` / `-b=NAME` - Specify branch
    - `--name=NAME` / `-n=NAME` - Module name
    - `--dist=CODE` / `-d=CODE` - Install to distributor modules
    - `--token=TOKEN` - GitHub personal access token
- **Distributor Inspector** - New CLI command to inspect distributor configuration
  - `inspect` command to check distributor details
  - Display comprehensive information:
    - Domain bindings and URL paths
    - Module status and versions
    - Module requirements and dependencies
    - Configuration settings (global modules, autoload, data mapping)
    - Module metadata (author, description, API name)
  - Options:
    - `--details` / `-d` - Show detailed module information
    - `--modules-only` / `-m` - Show only module information
    - `--domains-only` - Show only domain binding information
  - Features:
    - Color-coded output for better readability
    - Module status indicators (LOADED, PENDING, FAILED, etc.)
    - Alias detection for domains
    - Shared module identification
    - Relative path display for easy navigation
- **Comprehensive Documentation**
  - [docs/guides/GITHUB-INSTALLER.md](../guides/GITHUB-INSTALLER.md) - Complete GitHub installer guide
    - Installation examples and troubleshooting
    - Security best practices for token handling
    - API reference for RepoInstaller class
  - [docs/guides/INSPECT-COMMAND.md](../guides/INSPECT-COMMAND.md) - Distributor inspector reference
    - Usage examples for all options
    - Output format documentation
    - Integration with other commands
  - [docs/guides/PLUGIN-SYSTEM.md](../guides/PLUGIN-SYSTEM.md) - Complete plugin system documentation
    - Overview of plugin architecture and PluginTrait
    - Detailed guide for all four plugin types (Template, Collection, FlowManager, Statement)
    - Creating plugins with base classes and naming conventions
    - Loading plugins automatically and manually
    - Extensive examples for each plugin type
    - Built-in plugin reference
    - Best practices and troubleshooting
  - [docs/quick-reference/PLUGIN-QUICK-REFERENCE.md](../quick-reference/PLUGIN-QUICK-REFERENCE.md) - Quick reference for plugin development
    - Fast lookup table for plugin types and patterns
    - Quick start templates for each plugin type
    - Common patterns and usage examples
    - File naming rules and return type reference
    - Debugging tips and testing checklist
  - [docs/guides/DATABASE-QUERY-SYNTAX.md](../guides/DATABASE-QUERY-SYNTAX.md) - Complete database query syntax guide
    - TableJoinSyntax with all join types and conditions
    - WhereSyntax with comparison, string, JSON, and array operators
    - Logical operators (AND, OR, NOT) and grouping
    - Parameter binding and NULL handling
    - 7 complete real-world examples
    - Best practices and performance tips
  - [docs/quick-reference/DATABASE-QUERY-QUICK-REFERENCE.md](../quick-reference/DATABASE-QUERY-QUICK-REFERENCE.md) - Database query quick reference
- **Thread System (Initial)**
  - `Thread` and `ThreadManager` with in-process execution
  - Process backend for non-blocking command execution with concurrency limit
  - `Agent::thread()` accessor for module usage
- **SSE Streaming Helper**
  - `SSE` helper for Server-Sent Events output
  - Proxy mode for forwarding upstream SSE streams (LLM endpoints)
- **Cross-Distributor Internal Bridge (Initial)**
  - Internal HTTP bridge endpoint with allowlist and optional HMAC signature
  - Module API execution via bridge for cross-distributor calls
- **Internal API Execution via CLI Bridge**
  - `Distributor::executeInternalAPI()` method for safe cross-distributor API calls
  - CLI Process Isolation (separate PHP process via proc_open)
  - Returns `null` if `proc_open` is unavailable
  - Function availability detection (checks for disabled functions)
  - Comprehensive error handling and warnings for incompatible environments
  - Solves class namespace conflicts when distributors use different Composer versions
  - New documentation: [docs/guides/INTERNAL-API-FALLBACK-AND-ISOLATION.md](../guides/INTERNAL-API-FALLBACK-AND-ISOLATION.md)
    - Composer prefix explanation and class isolation strategies
    - Configuration guide for enabling all fallback methods
    - Usage examples and error handling patterns
    - Performance considerations and troubleshooting
- **LLM Assistant Documentation System** - AI-friendly codebase documentation
  - Auto-generation of LLM context at multiple levels:
    - Root: `LLM-CAS.md` - Framework overview and architecture
    - Distribution: `llm-cas/{dist_code}.md` - Per-distribution context
    - Module: `llm-cas/{dist_code}/{module}.md` - Per-module context
  - New CLI command: `generate-llm-docs` - Auto-generate all LLM documentation
  - `LLMCASGenerator` class for programmatic LLM-CAS documentation generation
  - `ModuleMetadataExtractor` class for static analysis of modules (no initialization required)
  - Features:
    - Static code analysis (regex-based parsing of Controller.php)
    - Extracts API commands, lifecycle events, and dependencies
    - Scans for @llm prompt comments in PHP code
    - Extracts {#llm prompt} tags from TPL templates
    - Generates structured markdown for LLM assistants
  - Template engine enhancement:
    - `{#llm prompt}...{/}` comment tags for marking LLM-relevant sections
    - Automatic removal of tags from rendered HTML output
    - Preserves tags for doc generation while keeping output clean
  - Benefits:
    - LLM assistants understand framework, distributions, and modules
    - Quick navigation between class docs and examples
    - Module communication graph extracted automatically
    - Dependency analysis for cross-module calls
  - Documentation:
    - [LLM-CAS.md](../../LLM-CAS.md) - Root guide for LLM assistants
    - Framework architecture explanation
    - Quick reference for "how do I...?" questions
    - Integration with code examples and docs

### Changed
- Updated help command with `install` command reference
- Enhanced terminal command structure
- Improved cross-distributor communication with smart fallback chain
- `Mailer` supports non-blocking SMTP via `sendAsync()`

### Fixed
- **Crypt Decrypt Regex** - Added `s` (DOTALL) flag to `Crypt::Decrypt()` regex so `.` matches `\n` in binary ciphertext data; without this ~6–99% of decrypt operations silently failed depending on data length
- **YAML Block Scalar Parsing** - Fixed indent comparison (`>` → `>=`) in multiline literal/folded block parsing; content lines at the same indent level as `peekNextIndent()` were silently dropped
- **Template `loadTemplate()` Argument Swap** - Fixed swapped `$tplName`/`$filepath` arguments in recursive `loadTemplate()` call for array-based template sources
- **Template `ParseValue()` Regex Anchoring** - Fixed broken regex alternation anchoring where `^` only applied to the first alternative, allowing `(-?\d+)` to match trailing digits in path syntax like `$items.0`
- **Error Constructor** - `parent::__construct()` was only called in the non-CLI branch, causing empty exception messages when `CLI_MODE` was true
- **Configuration Extension Key** - Added null coalescing for undefined `extension` key in `pathinfo()` result, preventing PHP deprecation
- **Column Charset/Collation** - Added null coalescing for undefined `charset`/`collation` keys in `getCharsetSyntax()`, preventing PHP warnings
- **`.htaccess` Rewrite Generator** - Fixed critical bugs in rewrite rule generation
  - Fixed route_path calculation for root ("/") and sub-paths ("/demo")
  - Fixed data_path formatting to use forward slashes for proper .htaccess compatibility
  - Fixed webassets dist_path to correctly reference module paths with version placeholders
  - Removed unnecessary domain preg_quote that caused malformed RewriteRule patterns
  - Improved path normalization for consistent trailing slash handling
  - Now correctly generates rewrite rules for:
    - Multiple distributors with different URL paths
    - Multiple domains with path-based routing
    - Module webassets routing by version
    - Data directory mapping per distributor
  - Fixed Mailer origin initialization typo

### Improved
- Module installation workflow significantly simplified
- No manual Git cloning or file copying required
- Integrated progress tracking and error handling
- Support for private repository access
- `.htaccess` generation now produces cleaner, more reliable rewrite rules

### Technical Details
- **New Files:**
  - `src/library/Razy/RepoInstaller.php` - Repository installer class
  - `src/system/terminal/install.inc.php` - CLI install command handler
  - `src/system/terminal/inspect.inc.php` - CLI inspect command handler
  - `docs/guides/GITHUB-INSTALLER.md` - GitHub installer documentation
  - `docs/guides/INSPECT-COMMAND.md` - Distributor inspector documentation
  - `docs/guides/PLUGIN-SYSTEM.md` - Complete plugin system guide (60+ pages)
  - `docs/quick-reference/PLUGIN-QUICK-REFERENCE.md` - Plugin development quick reference
  - `docs/guides/DATABASE-QUERY-SYNTAX.md` - Complete database query syntax guide (50+ pages)
  - `docs/quick-reference/DATABASE-QUERY-QUICK-REFERENCE.md` - Database query quick reference
  - `src/library/Razy/Thread.php` - Thread entity
  - `src/library/Razy/ThreadManager.php` - Thread manager and process backend
  - `src/library/Razy/SSE.php` - Server-Sent Events helper
  - `docs/guides/THREAD-SYSTEM.md` - Thread system design and usage
  - `docs/guides/CROSS-DISTRIBUTOR-COMMUNICATION.md` - Bridge design and endpoint format
- **Modified Files:**
  - `src/system/terminal/help.inc.php` - Added install and inspect commands
  - `src/library/Razy/Application.php` - Fixed updateRewriteRules() method
  - `src/library/Razy/Mailer.php` - Added async SMTP send
  - `src/library/Razy/Distributor.php` - Internal bridge endpoint handler
  - `src/library/Razy/Module.php` - Internal bridge API execution helper
  - `docs/usage/Razy.Mailer.md` - Added async SMTP sample
- **Dependencies:** Uses cURL and ZipArchive (PHP extensions)

## [0.5.3] - 2026-02-08

### Added
- **PSR-12 Extended Coding Style** compliance via PHP CS Fixer
  - 150+ automated code quality rules
  - `.php-cs-fixer.php` configuration with modern PHP 8.2+ standards
  - Composer scripts: `cs-check`, `cs-fix`, `quality`
- **Comprehensive Unit Test Suite** (366 tests, 641 assertions)
  - `CacheTest.php` - 66 tests for PSR-16 cache system (95%+ coverage)
  - `YAMLTest.php` - 40+ tests for YAML parser/dumper (90%+ coverage)
  - `CollectionTest.php` - 12 tests for ArrayObject collection (85%+ coverage)
  - `ConfigurationTest.php` - 22 tests for multi-format config (85%+ coverage)
  - `TemplateTest.php` - 32 tests for template engine (70%+ coverage)
  - `StatementTest.php` - 25+ tests for SQL statement building (60%+ coverage)
  - `RouteTest.php` - 28 tests for routing (95%+ coverage)
  - `CryptTest.php` - 30+ tests for AES-256-CBC encryption (95%+ coverage)
  - `HashMapTest.php` - 49 tests for HashMap utility (95%+ coverage)
  - `TableHelperTest.php` - 60+ tests for ALTER TABLE operations (85%+ coverage)
  - Overall coverage: **~85%** for tested components (10 core components)
  - All tests pass in strict mode (`failOnRisky`, `failOnWarning`, `requireCoverageMetadata`)
- **Testing Infrastructure**
  - PHPUnit 10.5 configuration with coverage support
  - Test bootstrap with PSR-4 autoloading
  - Composer test scripts: `test`, `test-coverage`
- **Comprehensive Documentation**
  - [PSR-STANDARDS.md](../documentation/PSR-STANDARDS.md) - Complete PSR implementation guide (500+ lines)
  - [docs/PSR-QUICK-REFERENCE.md](../quick-reference/PSR-QUICK-REFERENCE.md) - Quick style reference
  - [docs/TESTING.md](../guides/TESTING.md) - Unit testing guide (200+ lines)
  - [docs/TEST-COVERAGE-SUMMARY.md](../status/TEST-COVERAGE-SUMMARY.md) - Coverage report
  - [docs/UNIT-TEST-STATUS.md](../status/UNIT-TEST-STATUS.md) - Final test status report

### Changed
- Updated README.md with PSR compliance badges and quality metrics
- Enhanced `.gitignore` with PHP CS Fixer cache exclusions
- Updated `composer.json` with code quality scripts
- Improved documentation index in [docs/documentation/DOCS-README.md](../documentation/DOCS-README.md)

### Improved
- Code quality standards now enterprise-grade
- Automated code style enforcement
- Comprehensive test coverage for critical components
- Professional documentation structure

## [0.5.2] - 2026-01-XX

### Added
- **Native YAML Parser and Dumper** (`Razy\YAML`)
  - Full YAML 1.2 subset support without external dependencies
  - Features: mappings, sequences, multi-line strings, anchors/aliases
  - Methods: `parse()`, `parseFile()`, `dump()`, `dumpFile()`
- **YAML Configuration Support** in `Configuration` class
  - Load `.yaml` and `.yml` files natively
  - Save configuration as YAML format
  - Multi-format support: PHP, JSON, INI, YAML
- **OAuth 2.0 / Office 365 SSO** authentication system
  - `Razy\OAuth2` - Generic OAuth 2.0 client
  - `Razy\Office365SSO` - Microsoft Entra ID / Azure AD integration
  - JWT token parsing and validation
  - Microsoft Graph API support
- **FrankenPHP/Caddy Worker Mode** support
  - 7.1x performance improvement for high-traffic sites
  - State management between requests
  - `UnlockForWorker()` and `resetForWorker()` methods
  - Worker lifecycle documentation
- **Enhanced Composer Integration**
  - Version constraint support: `~2.0.0`, `^1.0`, `dev-master`
  - Stability flags: `@dev`, `@alpha`, `@beta`, `@RC`, `@stable`
  - Dev branch support
  - Enhanced `PackageManager` with version matching

### Documentation Added
- [docs/guides/COMPOSER-INTEGRATION.md](../guides/COMPOSER-INTEGRATION.md) - Complete Composer guide
- [docs/quick-reference/COMPOSER-QUICK-REFERENCE.md](../quick-reference/COMPOSER-QUICK-REFERENCE.md) - Quick reference
- [docs/guides/CADDY-WORKER-MODE.md](../guides/CADDY-WORKER-MODE.md) - Worker mode guide
- [docs/quick-reference/CADDY-WORKER-QUICK-REFERENCE.md](../quick-reference/CADDY-WORKER-QUICK-REFERENCE.md) - Quick setup
- [docs/guides/OFFICE365-SSO.md](../guides/OFFICE365-SSO.md) - OAuth/SSO guide (650+ lines)
- [docs/quick-reference/OFFICE365-SSO-QUICK-REFERENCE.md](../quick-reference/OFFICE365-SSO-QUICK-REFERENCE.md) - Quick setup
- [docs/quick-reference/YAML-QUICK-REFERENCE.md](../quick-reference/YAML-QUICK-REFERENCE.md) - YAML syntax guide
- [docs/usage/Razy.YAML.md](../usage/Razy.YAML.md) - YAML class reference
- [docs/usage/Razy.OAuth2.md](../usage/Razy.OAuth2.md) - OAuth2 class reference
- [docs/usage/Razy.Office365SSO.md](../usage/Razy.Office365SSO.md) - SSO class reference

## [0.5.1] - 2025-XX-XX

### Added
- **Production Usage Analysis**
  - [docs/usage/PRODUCTION-USAGE-ANALYSIS.md](../usage/PRODUCTION-USAGE-ANALYSIS.md) - 50+ pages of real-world patterns
  - Module architecture best practices from production site analysis
  - Common patterns and anti-patterns documentation

### Documentation
- Comprehensive class documentation (50+ classes)
  - [USAGE-SUMMARY.md](../documentation/USAGE-SUMMARY.md) - High-level framework overview
  - Individual class documentation in `docs/usage/` directory
- **LLM Integration**
  - [docs/usage/LLM-PROMPT.md](../usage/LLM-PROMPT.md) - AI assistant integration guide
  - Structured prompt for accurate framework assistance

## [0.5.0] - Initial Release

### Core Features
- **Simplified Module Flow**
  - New lifecycle hooks: `__onInit`, `__onLoad`, `__onRequire`, `__onReady`, `__onScriptReady`, `__onRouted`, `__onEntry`
  - Reduced handshake complexity
  - 30% code reduction for module load status handling
- **Guaranteed Module Loading**
  - Dependency-based loading order
  - Automatic parent namespace module requirement
  - Module code supports deeper namespace levels (e.g., `vendor/package/module`)
- **Revamped Application Scope**
  - Application runs as instances
  - Application locking during routing
  - Flexible configuration outside main workflow
- **Standardized Distributor Code**
  - Support for `-` in distributor codes
  - Folder name must match distributor code
- **Enhanced Template Engine**
  - Parameter tags without closing tags
  - Array-path values support
  - Function blocks and tags
  - Four template block types: WRAPPER, INCLUDE, TEMPLATE, USE
- **Improved Database Layer**
  - WhereSyntax with shortened query syntax
  - TableJoinSyntax for complex joins
  - MySQL JSON function support via operators (`~=`, `:=`)
  - Accurate syntax parsing and validation
- **PSR-4 Autoloading**
  - Standard autoloading for `Razy\` namespace
  - Isolated package management (composer, custom classes, controllers)

### Architecture
- PHP 8.2+ requirement
- Modern type declarations (union types, nullable types, mixed)
- ArrayObject-based Collection class
- Event-driven module system
- Multi-site support via Domain class
- Module dependency resolution

### Developer Experience
- Clear error messages
- Flexible routing (lazy routes, regex routes, shadow routes)
- API command system
- Event listener system
- Plugin architecture (Template, Collection, FlowManager, Statement)

---

## Standards & Quality

### PSR Compliance
- **PSR-1**: Basic Coding Standard ✅
- **PSR-4**: Autoloading ✅
- **PSR-12**: Extended Coding Style ✅ (as of v0.5.3)

### Quality Metrics (v0.5.4)
- **366 tests / 641 assertions** with PHPUnit 10.5 (strict mode)
- **10 core components** covered (Cache, Collection, Configuration, Crypt, HashMap, Route, Statement, TableHelper, Template, YAML)
- **~85% test coverage** for tested components
- **150+ code quality rules** via PHP CS Fixer
- **Enterprise-grade** code standards
- **0 errors, 0 failures, 0 warnings, 0 risky** — fully clean test suite

### PHP Requirements
- **Minimum**: PHP 8.2
- **Extensions**: ext-zip, ext-curl, ext-json
- **Optional**: Xdebug/PCOV (for coverage reports)

---

## Upgrade Guide

### From 0.5.3 to 0.5.4
No breaking changes. Bug fixes and new features:
1. Run `composer install` to update dependencies
2. Run `composer test` to verify all 366 tests pass
3. Review changelog for 7 source bug fixes (Crypt, YAML, Template, Error, Configuration, Column)

### From 0.5.2 to 0.5.3
No breaking changes. New features:
1. Run `composer install` to get PHP CS Fixer
2. Run `composer cs-fix` to apply PSR-12 standards to your code
3. Run `composer test` to execute new test suite
4. Review [PSR-STANDARDS.md](../documentation/PSR-STANDARDS.md) for coding guidelines

### From 0.5.1 to 0.5.2
No breaking changes. New features:
1. Update `Configuration` class usage if you want YAML support
2. Optional: Implement OAuth2/Office365SSO for authentication
3. Optional: Configure Caddy worker mode for performance boost
4. Optional: Use enhanced Composer package management

### From 0.5.0 to 0.5.1
No breaking changes. Documentation-only release.

---

## Links

- **Repository**: https://github.com/rayfungHK/Razy
- **Documentation**: [docs/documentation/DOCS-README.md](../documentation/DOCS-README.md)
- **Issues**: https://github.com/rayfungHK/Razy/issues
- **License**: MIT

---

## Contributors

- **Ray Fung** - Original Author - [hello@rayfung.hk](mailto:hello@rayfung.hk)

---

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.
