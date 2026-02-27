# Razy Composer Integration

Razy includes a built-in Composer package manager that fetches and installs PHP libraries from [Packagist.org](https://packagist.org) without requiring the Composer binary.

## Two Types of Dependencies

**Module Dependencies** (`required`): Other Razy modules that must be loaded before this module
- Declared in `package.php` `required` array
- Ensures module execution order
- Example: A payment module might require a user authentication module

**Package Prerequisites** (`prerequisite`): External PHP packages from Packagist
- Declared in `package.php` `prerequisite` array
- Installed via `php main.php compose` command
- Example: QR code libraries, spreadsheet packages, email libraries

---

## Package Prerequisite Installation Flow

1. **Module Declaration**: Modules declare PHP library prerequisites in their `package.php` file
2. **Automatic Collection**: When a distributor is composed, Razy collects all prerequisite packages
3. **Package Installation**: Razy downloads packages from Packagist, extracts them, and configures PSR-0/PSR-4 autoloading
4. **Version Locking**: Installed versions are locked in `lock.json` to ensure consistency

## Declaring Prerequisites

Only external Composer packages go in `prerequisite`. Module dependencies use `required` instead.

In your module's `package.php`, add a `prerequisite` array for external PHP libraries:

```php
return [
    'label' => 'My Module',
    'version' => '1.0.0',
    'required' => [
        'vendor/other-module' => '1.0',  // Other Razy modules (optional)
    ],
    'prerequisite' => [
        'vendor/package-name' => 'version-constraint',  // External packages only
        'another/package' => '^2.0',
    ],
];
```

## Required Modules vs Prerequisites

| Type | Array Name | Purpose | Examples |
|------|-----------|---------|----------|
| **Required Modules** | `required` | Razy modules that must load first | `['vendor/auth-module' => '1.0']` |
| **Packages** | `prerequisite` | External PHP packages from Packagist | `['phpoffice/phpspreadsheet' => '~2.0']` |

### Version Constraint Syntax

Razy supports all standard Composer version constraints plus stability flags:

| Constraint | Example | Description |
|------------|---------|-------------|
| **Exact** | `1.2.3` | Exact version match |
| **Wildcard** | `1.0.*` | Any patch version of 1.0 |
| **Range** | `1.0 - 2.0` | Between 1.0 (inclusive) and 2.0 (exclusive) |
| **Tilde** | `~1.2.3` | >=1.2.3 <1.3.0 (patch releases) |
| | `~1.2` | >=1.2.0 <2.0.0 (minor releases) |
| **Caret** | `^1.2.3` | >=1.2.3 <2.0.0 (compatible releases) |
| | `^0.2.3` | >=0.2.3 <0.3.0 (0.x is special) |
| **Comparison** | `>=1.2.3` | Greater than or equal to |
| | `>1.2.3` | Greater than |
| | `<=2.0.0` | Less than or equal to |
| | `<2.0.0` | Less than |
| | `!=1.2.3` | Not equal to |
| **Any** | `*` | Any version |
| **Logical AND** | `>=1.0,<2.0` | Must satisfy both constraints |
| **Logical OR** | `^1.0\|\|^2.0` | Must satisfy at least one constraint |
| **Dev Branches** | `dev-master` | Specific development branch |
| | `dev-develop` | Development branch |
| **Stability Flags** | `1.0@dev` | Version 1.0 with dev stability |
| | `*@alpha` | Any alpha version |
| | `>=2.0@beta` | Version >=2.0 with beta stability |

### Real-World Examples

From production modules:

```php
// QR Code Generator
'prerequisite' => [
    'chillerlan/php-qrcode' => '*',
]

// QR Code Scanner with fallback
'prerequisite' => [
    'chillerlan/php-qrcode' => '*',
    'khanamiryan/qrcode-detector-decoder' => '*',
]

// Spreadsheet Processing
'prerequisite' => [
    'phpoffice/phpspreadsheet' => '~2.0.0', // >=2.0.0 <2.1.0
]

// Development Branch
'prerequisite' => [
    'vendor/package' => 'dev-master', // Latest master branch
]

// Beta Testing
'prerequisite' => [
    'vendor/package' => '2.0@beta', // Version 2.0 beta releases
]

// Allow Unstable Versions
'prerequisite' => [
    'vendor/package' => '*@dev', // Any version including dev
]
```

## Installing Packages

Use the terminal command to install all prerequisites for a distributor:

```bash
php main.php compose <distributor-code>
```

### Output Example

```
Update distributor module and package
Validating package: chillerlan/php-qrcode (4.3.4)
 - Downloading: chillerlan/php-qrcode @4.3.4 (45%)
 - Done.
 - chillerlan/php-qrcode: Extracting `chillerlan\QRCode` from `src/`
```

## Architecture

### Files Involved

| File | Purpose |
|------|---------|
| `ModuleInfo.php` | Reads `prerequisite` array from `package.php` |
| `Module.php` | Registers prerequisites with Distributor during module load |
| `Distributor.php` | Collects all prerequisites from loaded modules |
| `Application.php` | Provides `compose()` method for terminal command |
| `PackageManager.php` | Downloads, validates, and installs packages |
| `terminal/compose.inc.php` | CLI command handler |

### Process Flow

```
1. Module Constructor
   └─> ModuleInfo reads prerequisite array
   └─> Module::__construct() calls Distributor::prerequisite()

2. Terminal Command: php main.php compose <dist>
   └─> Application::compose($distCode, $closure)
   └─> Distributor::initialize() loads all modules
   └─> Distributor::compose() loops prerequisites

3. For each prerequisite:
   └─> PackageManager::fetch() from packagist.org
   └─> PackageManager::validate() checks version
   └─> Downloads ZIP via CURL
   └─> Extracts PSR-0/PSR-4 namespaces
   └─> Updates lock.json

4. Autoloading
   └─> Extracted to: SYSTEM_ROOT/autoload/<distributor-code>/<namespace>/
   └─> SPL autoloader resolves classes
```

## PackageManager API

### Constructor

```php
public function __construct(
    Distributor $distributor,
    string $name,              // Package name: vendor/package
    string $versionRequired,   // Version constraint: ~2.0.0
    ?Closure $notifyClosure    // Progress callback
)
```

### Methods

```php
// Fetch package metadata from packagist.org
public function fetch(): bool

// Validate and install package
public function validate(): bool

// Get package name
public function getName(): string

// Get installed version
public function getVersion(): string

// Update version lock file
static public function UpdateLock(): void
```

### Status Constants

```php
const STATUS_IDLE = 0;
const STATUS_FETCHING = 1;
const STATUS_READY = 2;
const STATUS_UPDATED = 3;

const TYPE_READY = 'ready';
const TYPE_DOWNLOAD_PROGRESS = 'download-progress';
const TYPE_DOWNLOAD_FINISHED = 'download-finished';
const TYPE_UPDATED = 'updated';
const TYPE_EXTRACT = 'extract';
const TYPE_FAILED = 'failed';
const TYPE_ERROR = 'error';
```

## Version Constraint Logic

The `vc()` function in `bootstrap.inc.php` implements constraint parsing:

### Tilde (~) Examples

```php
vc('~1.2.3', '1.2.4')  // true  (>=1.2.3 <1.3.0)
vc('~1.2.3', '1.3.0')  // false
vc('~1.2', '1.9.0')    // true  (>=1.2.0 <2.0.0)
vc('~1.2', '2.0.0')    // false
```

### Caret (^) Examples

```php
vc('^1.2.3', '1.9.0')  // true  (>=1.2.3 <2.0.0)
vc('^1.2.3', '2.0.0')  // false
vc('^0.2.3', '0.2.9')  // true  (>=0.2.3 <0.3.0, special 0.x handling)
vc('^0.2.3', '0.3.0')  // false
```

### Logical Operators

```php
vc('>=1.0,<2.0', '1.5.0')    // true  (AND: both must be true)
vc('>=1.0,<2.0', '2.5.0')    // false
vc('^1.0||^2.0', '1.5.0')    // true  (OR: at least one must be true)
vc('^1.0||^2.0', '2.5.0')    // true
vc('^1.0||^2.0', '3.0.0')    // false
```

## Dev Branches and Stability Flags

Razy now supports Composer-style development branches and stability flags.

### Development Branches

Install from VCS branches instead of tagged releases:

```php
'prerequisite' => [
    'vendor/package' => 'dev-master',     // Latest master branch
    'vendor/package' => 'dev-develop',    // Development branch
    'vendor/package' => 'dev-feature-x',  // Feature branch
]
```

**How it works:**
- Packagist tracks VCS branches and exposes them with `dev-` prefix
- Razy matches these exactly (case-insensitive)
- No version comparison - exact branch name match

### Stability Flags

Control which release types are acceptable:

```php
'prerequisite' => [
    'vendor/package' => '1.0@dev',      // Version 1.0 dev releases
    'vendor/package' => '*@alpha',      // Any alpha version
    'vendor/package' => '>=2.0@beta',   // Version >=2.0 beta or better
    'vendor/package' => '^3.0@RC',      // Version ^3.0 release candidates
]
```

**Stability levels (from most to least stable):**
1. `@stable` - Stable releases (default)
2. `@RC` - Release candidates
3. `@beta` - Beta versions
4. `@alpha` - Alpha versions
5. `@dev` - Development versions

**Stability filtering:**
- By default, only `@stable` versions are considered
- Specifying a stability flag (e.g., `@beta`) includes that level and all more stable levels
- Example: `*@beta` includes stable, RC, and beta versions

### Version Sorting

Razy sorts available versions by:
1. **Stability** (stable first, dev last)
2. **Version number** (descending)

Example order for same package:
```
1. 2.0.0 (stable)
2. 1.9.0 (stable)
3. 2.0.0-RC1 (rc)
4. 2.0.0-beta2 (beta)
5. 2.0.0-alpha1 (alpha)
6. dev-master (dev)
```

This ensures stable versions are preferred unless you explicitly request lower stability.

## Autoloading

Packages are extracted to: `SYSTEM_ROOT/autoload/<distributor-code>/<namespace>/`

### Example Structure

```
autoload/
└─ mysite/
   ├─ chillerlan/
   │  └─ QRCode/
   │     ├─ QRCode.php
   │     └─ ...
   └─ PhpOffice/
      └─ PhpSpreadsheet/
         ├─ Spreadsheet.php
         └─ ...
```

### PSR-4 Mapping

If `package.json` defines:

```json
{
  "autoload": {
    "psr-4": {
      "chillerlan\\QRCode\\": "src/"
    }
  }
}
```

Then Razy extracts `src/` to `autoload/mysite/chillerlan/QRCode/`, so:
- `chillerlan\QRCode\QRCode` → `autoload/mysite/chillerlan/QRCode/QRCode.php`

## Lock File

`data/packages/lock.json` stores installed versions:

```json
{
  "mysite": {
    "chillerlan/php-qrcode": {
      "version": "4.3.4.0",
      "timestamp": 1234567890
    },
    "phpoffice/phpspreadsheet": {
      "version": "2.0.0.0",
      "timestamp": 1234567890
    }
  }
}
```

## Advantages Over Standard Composer

1. **No Composer Binary Required**: Works on systems without Composer installed
2. **Distributor-Scoped**: Each distributor has isolated package versions
3. **Integrated Workflow**: Part of Razy's module system
4. **Progress Feedback**: Real-time download progress in terminal
5. **Dev Branch Support**: Can install from VCS branches like `dev-master`
6. **Stability Control**: Fine-grained control over package stability

## Limitations

1. **No Dev Dependencies**: Only production packages are supported
2. **No Scripts**: Composer scripts (post-install, etc.) are not executed
3. **No Platform Checks**: PHP version/extension requirements from packages are not validated
4. **Manual Compose**: Must run `compose` command manually (not automatic like `composer install`)

## Version Conflict Resolution

### The Problem

When multiple modules in the **same distributor** require different versions of the same package, you have a version conflict:

```php
// Module A: package.php
'prerequisite' => ['league/commonmark' => '^1.0']  // Wants v1.x

// Module B: package.php
'prerequisite' => ['league/commonmark' => '^2.0']  // Wants v2.x

// Result: Only ONE version can be loaded - conflict!
```

### ✅ Different Distributors = No Conflict

Different distributors are **isolated**:
- Each distributor has its own `autoload/<distributor>/` folder
- Each distributor has its own `lock.json` entries
- Cross-distributor communication uses internal HTTP bridge (isolated processes)

```
autoload/
├── site-a/                    # Uses commonmark@1.6
│   └── League/CommonMark/
└── site-b/                    # Uses commonmark@2.4
    └── League/CommonMark/
```

### ❌ Same Distributor = Shared Autoloader

Modules in the **same distributor** share the PHP autoloader, so only one version can exist.

### Solution: Shared Service Pattern

Create a **service module** that wraps the library and exposes a version-agnostic API:

**Step 1: Create Service Module**

```php
// system/markdown_service/default/package.php
return [
    'label' => 'Markdown Service',
    'api_name' => 'markdown',
    
    // The ONLY place that declares the library
    'prerequisite' => [
        'league/commonmark' => '^2.0',
    ],
];
```

```php
// system/markdown_service/default/api/parse.php
return function (string $markdown, array $options = []): array {
    // Wrap the library with a stable API
    $converter = new \League\CommonMark\GithubFlavoredMarkdownConverter($config);
    return [
        'success' => true,
        'html' => $converter->convert($markdown)->getContent(),
    ];
};
```

**Step 2: Consumer Modules Depend on Service (Not Library)**

```php
// blog/default/package.php
return [
    'label' => 'Blog Module',
    
    // Depend on the SERVICE, not the library!
    'required' => [
        'system/markdown_service' => '*',
    ],
    
    // NO prerequisite for commonmark
    'prerequisite' => [],
];
```

```php
// blog/default/controller/post.php
return function ($postId): void {
    $post = $this->getPost($postId);
    
    // Use the service API - version-agnostic!
    $result = $this->api('markdown')->parse($post['content']);
    echo $result['html'];
};
```

### Benefits of Shared Service Pattern

| Benefit | Description |
|---------|-------------|
| **No Conflicts** | Library version managed in ONE place |
| **Stable API** | Consumer modules don't break when library updates |
| **Easy Testing** | Can mock the service API |
| **Centralized Updates** | Update service module to upgrade library |
| **Decoupling** | Consumers isolated from library internals |

### Example: Demo Modules

See the demo modules for a working example:

- `demo_modules/system/markdown_service/` - Service that wraps league/commonmark
- `demo_modules/demo/markdown_consumer/` - Consumer that uses the service

Routes:
- `/system/markdown_service/demo` - Service demo
- `/demo/markdown_consumer/render` - Interactive editor
- `/demo/markdown_consumer/blog` - Blog demo
- `/demo/markdown_consumer/info` - Service info

## Best Practices

### 1. Use Specific Constraints

```php
// ✅ Good: Allows compatible updates
'phpoffice/phpspreadsheet' => '~2.0.0',

// ❌ Avoid: Too permissive, may break on major updates
'phpoffice/phpspreadsheet' => '*',
```

### 2. Test After Compose

Always test your modules after running `compose` to ensure compatibility with installed versions.

### 3. Document Prerequisites

In your module's README, list prerequisites and their purpose:

```markdown
## Prerequisites

- `chillerlan/php-qrcode` ~4.0: QR code generation
- `khanamiryan/qrcode-detector-decoder` *: QR code scanning (fallback)
```

### 4. Version Lock in Production

Commit `lock.json` to version control to ensure consistent package versions across environments.

## Troubleshooting

### Package Not Found

```
[ERROR] Cannot update package vendor/package-name (1.0.0).
```

**Solution**: Check package name spelling on [Packagist.org](https://packagist.org)

### Version Constraint Not Satisfied

```
[ERROR] No version in repos is available for update.
```

**Solutions**:
- Relax version constraint or check available versions on Packagist.org
- Check stability flag - you may need `@dev` or `@beta` for pre-release versions
- Verify the package has the version you're requesting

### Dev Branch Not Found

```
[ERROR] No version in repos is available for update.
```

When using `dev-branch-name`:

**Solutions**:
- Verify branch exists in the package's VCS repository
- Check branch name spelling (branches are case-sensitive)
- Ensure Packagist has synced the latest branches (may take a few minutes)

### Stability Issues

**Problem**: Getting dev versions when you want stable

**Solution**: Don't use stability flags, or explicitly use `@stable`:
```php
'vendor/package' => '1.0@stable'  // Only stable releases
```

**Problem**: Not getting beta/alpha versions

**Solution**: Add appropriate stability flag:
```php
'vendor/package' => '*@beta'   // Include beta and more stable
'vendor/package' => '*@alpha'  // Include alpha and more stable
'vendor/package' => '*@dev'    // Include all versions
```

### Download Failed

```
[ERROR] Download failed for vendor/package-name
```

**Solution**: Check network connection, ensure `SYSTEM_ROOT/data/packages/` is writable

### Autoload Not Working

**Solution**: Ensure package uses PSR-0 or PSR-4 autoloading. Check `SYSTEM_ROOT/autoload/<dist>/` exists.

### Module Failed to Load - Prerequisite Version Conflict

```
Module 'vendor/module' failed to load: prerequisite version conflict.
Package 'league/commonmark' requires '^1.0', but installed version is incompatible.
Run 'php Razy.phar compose mysite' to resolve dependencies.
```

**Cause**: The installed package version doesn't satisfy this module's version constraint.

**Solutions**:

1. **Run compose to update packages**:
   ```bash
   php Razy.phar compose <distributor-code>
   ```

2. **If conflict is between modules**, use the Shared Service Pattern:
   - Create a service module that declares the dependency once
   - Consumer modules depend on the service, not the library

3. **Check compose output for version conflicts**:
   ```
   [WARNING] Version conflict detected:
     Package: league/commonmark
     Module: vendor/module_a
     Required: ^1.0
     Installed: 2.4.0
   ```

4. **Update the version constraint** in the module's `package.php` to match installed version

## Prerequisite Version Validation

Razy validates prerequisite versions at two stages:

### 1. Module Load Time

When a module loads, Razy checks if each prerequisite package's installed version satisfies the module's version constraint:

```php
// If installed league/commonmark is 2.4.0, but module requires ^1.0
// Module will fail to load with error
```

**Behavior**:
- If package is **not installed**: No error (compose will install it)
- If package is installed but **version mismatch**: Module fails to load with error
- If package is installed and **version matches**: Module loads normally

### 2. Compose Time

When running `compose`, Razy reports version conflicts before downloading:

```bash
php Razy.phar compose mysite
```

Output shows:
```
[WARNING] Version conflict detected:
  Package: league/commonmark
  Module: vendor/blog
  Required: ^1.0
  Installed: 2.4.0

Validating package: league/commonmark (2.4.0)
...
```

The compose command will attempt to find a version that satisfies **ALL** modules' constraints. If no such version exists, compose fails.

## Migration from Composer

If your project already uses Composer, you can migrate to Razy's prerequisite system:

### 1. Identify Dependencies

From `composer.json`:

```json
{
  "require": {
    "monolog/monolog": "^2.0",
    "guzzlehttp/guzzle": "^7.0"
  }
}
```

### 2. Add to Module

In `package.php`:

```php
'prerequisite' => [
    'monolog/monolog' => '^2.0',
    'guzzlehttp/guzzle' => '^7.0',
]
```

### 3. Run Compose

```bash
php main.php compose <distributor-code>
```

### 4. Remove Composer (Optional)

If you no longer need Composer:

```bash
rm composer.json composer.lock
rm -rf vendor/
```

## See Also

- [PackageManager.php](../src/library/Razy/PackageManager.php) - Package manager implementation
- [Distributor.php](../src/library/Razy/Distributor.php) - Prerequisite collection
- [ModuleInfo.php](../src/library/Razy/ModuleInfo.php) - Module configuration
- [Production Usage Analysis](usage/PRODUCTION-USAGE-ANALYSIS.md) - Real-world examples
