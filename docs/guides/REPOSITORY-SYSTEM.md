# Module Repository System

The Razy Module Repository System provides a complete workflow for packaging, publishing, and installing modules from custom repositories.

## Overview

The repository system consists of three commands:
- `pack` - Package modules as .phar files
- `publish` - Generate repository index and publish to GitHub
- `search` - Search modules from configured repositories
- `install --from-repo` - Install modules from configured repositories

## Repository Structure

A module repository follows this structure (using GitHub Releases for .phar files):

```
repository/
├── index.json              # Master index of all modules
└── vendor/
    └── module/
        └── manifest.json   # Module metadata & versions

GitHub Releases:
├── vendor-module-v1.0.0    # Release tag
│   └── 1.0.0.phar          # .phar attached as release asset
├── vendor-module-v1.1.0
│   └── 1.1.0.phar
└── vendor-module-v2.0.0
    └── 2.0.0.phar
```

### Why GitHub Releases?

.phar files are distributed via GitHub Releases instead of storing directly in the repository for several reasons:
- **Cleaner repository**: Only metadata (index.json, manifest.json) in the repo
- **Better versioning**: Each version is a proper GitHub Release
- **Download tracking**: GitHub provides download statistics
- **CDN delivery**: GitHub's release assets use faster CDN distribution

### index.json

Master index containing all available modules:

```json
{
  "vendor/module-a": {
    "description": "Module A description",
    "author": "Author Name",
    "latest": "2.0.0",
    "versions": ["2.0.0", "1.1.0", "1.0.0"]
  },
  "vendor/module-b": {
    "description": "Module B description",
    "author": "Author Name",
    "latest": "1.0.0",
    "versions": ["1.0.0"]
  }
}
```

### manifest.json

Per-module metadata (stored at `vendor/module/manifest.json`):

```json
{
  "module_code": "vendor/module",
  "description": "Module description",
  "author": "Author Name",
  "latest": "2.0.0",
  "versions": ["2.0.0", "1.1.0", "1.0.0"]
}
```

### Tag Naming Convention

GitHub Release tags follow this format:
- Tag: `{vendor}-{module}-v{version}` (e.g., `demo-my_module-v1.0.0`)
- Asset: `{version}.phar` (e.g., `1.0.0.phar`)

Download URL format:
```
https://github.com/{owner}/{repo}/releases/download/{vendor}-{module}-v{version}/{version}.phar
```

## Commands

### Pack Command

Package a module as a .phar file for distribution.

```bash
php Razy.phar pack <module_code> <version> [output_path] [options]
```

**Arguments:**
- `module_code` - Module code (vendor/module or dist@vendor/module)
- `version` - Version to package (e.g., 1.0.0)
- `output_path` - Output directory (default: ./packages/)

**Options:**
- `--no-compress` - Skip GZIP compression
- `--no-assets` - Exclude webassets folder

**Examples:**

```bash
# Pack shared module
php Razy.phar pack vendor/module 1.0.0

# Pack distributor module
php Razy.phar pack mysite@vendor/module 1.0.0

# Pack to specific directory
php Razy.phar pack vendor/module 1.0.0 ./releases/
```

### Publish Command

Generate repository index from packaged modules and optionally push to GitHub.

```bash
php Razy.phar publish [packages_path] [options]
```

**Arguments:**
- `packages_path` - Path to packages directory (default: ./packages/)

**Options:**
- `-v, --verbose` - Show detailed information
- `--dry-run` - Preview changes without writing files
- `--push` - Push to GitHub repository via API (requires publish.inc.php config)
- `--branch=NAME` - Branch to push to (default: main)
- `--dist=CODE` - Distributor code to scan for modules
- `--include-shared` - Include shared modules when scanning
- `--scan` - Scan source modules and auto-pack new versions
- `--force` - Force push even if version exists

**Configuration (packages/publish.inc.php):**

Create a config file with your GitHub credentials:

```php
<?php
return [
    'token' => 'ghp_your_personal_access_token',
    'repo' => 'owner/repo',
];
```

**GitHub Token Permissions (Fine-grained PAT):**
- `Contents: Read and write` - For pushing index.json, manifest.json
- `Metadata: Read-only` - For reading repo info

**Changelog Support:**

Create `packages/vendor/module/changelog/<version>.txt` to provide custom commit messages for each version release.

**Options:**
- `--cleanup` - Remove old .phar files from repo (migrates to GitHub Releases)

**Examples:**

```bash
# Publish from default packages directory
php Razy.phar publish

# Publish from specific directory
php Razy.phar publish ./my-repo/

# Preview changes
php Razy.phar publish --dry-run

# Push to GitHub (reads token/repo from publish.inc.php)
php Razy.phar publish --push

# Push to prerelease branch
php Razy.phar publish --push --branch=prerelease

# Scan and auto-pack distributor modules
php Razy.phar publish --dist=mysite --scan --push

# Full workflow with shared modules
php Razy.phar publish --dist=mysite --include-shared --scan --push
```

### Search Command

Search modules from configured repositories.

```bash
php Razy.phar search <query> [options]
```

**Arguments:**
- `query` - Search query (module code, name, or keyword)

**Options:**
- `-v, --verbose` - Show detailed information
- `--refresh` - Force refresh repository index cache

**Examples:**

```bash
# Search for modules
php Razy.phar search database
php Razy.phar search vendor/module

# Show detailed information
php Razy.phar search auth --verbose
```

### Install from Repository

Install modules from configured repositories.

```bash
php Razy.phar install <module_code> --from-repo [options]
```

**Arguments:**
- `module_code` - Module code (vendor/module[@version])

**Options:**
- `-r, --from-repo` - Install from configured repositories
- `-v, --version=VER` - Specify version
- `-n, --name=NAME` - Module name
- `-d, --dist=CODE` - Install to distributor
- `-y, --yes` - Auto-confirm installation

**Examples:**

```bash
# Install latest version (prompts for shared/distributor selection)
php Razy.phar install vendor/module --from-repo

# Install specific version
php Razy.phar install vendor/module@1.0.0 --from-repo

# Install with version option
php Razy.phar install vendor/module --from-repo --version=1.0.0

# Install to distributor
php Razy.phar install vendor/module --from-repo --dist=mysite

# Auto-confirm (defaults to shared modules)
php Razy.phar install vendor/module --from-repo --yes
```

### Sync Command

Sync modules from distributor repository configuration. This command reads `repository.inc.php` from a distributor and installs all defined modules.

```bash
php Razy.phar sync [distributor] [options]
```

**Arguments:**
- `distributor` - Distributor code (optional, prompts if not provided)

**Options:**
- `-v, --verbose` - Show detailed information
- `--dry-run` - Preview changes without installing
- `-y, --yes` - Auto-confirm all installations

**Distributor repository.inc.php format:**

```php
<?php
return [
    // Repository sources
    'repositories' => [
        'https://github.com/owner/repo/' => 'main',
    ],
    
    // Modules to sync
    'modules' => [
        // Simple format - install to distributor, specified version
        'vendor/module-a' => 'latest',
        'vendor/module-b' => '1.0.0',
        
        // Detailed format with options
        'vendor/module-c' => [
            'version' => '2.0.0',
            'is_shared' => true,  // Install to shared/module/
        ],
    ],
];
```

**Examples:**

```bash
# Prompt to select distributor
php Razy.phar sync

# Sync specific distributor
php Razy.phar sync mysite

# Auto-confirm all installations
php Razy.phar sync mysite --yes

# Preview what would be installed
php Razy.phar sync mysite --dry-run
```

### Dependency Resolution

When installing a module, the installer automatically checks for required modules defined in the module's `module.php`:

```php
// module.php
return [
    'module_code' => 'vendor/module',
    'require' => [
        'vendor/dependency-a' => '^1.0',
        'vendor/dependency-b' => '^2.0',
    ],
];
```

**Installation flow:**
1. Module is downloaded and installed
2. Installer reads `module.php` from the installed module
3. Checks if each required module is already installed
4. Prompts user to install missing dependencies
5. Downloads and installs dependencies from configured repositories

**Example output:**
```
[DEPENDENCIES] This module requires 2 other module(s)
  - vendor/dependency-a (^1.0)
  - vendor/dependency-b (^2.0) [INSTALLED] Already installed

Install required modules? (y/N): y

[INSTALL] Installing dependency: vendor/dependency-a
  [✓] Installed
```

## Configuration

### repository.inc.php

Configure repositories in your project root:

```php
<?php
return [
    // GitHub repository (branch)
    'https://github.com/username/repo/' => 'main',
    
    // GitLab repository
    'https://gitlab.com/username/repo/' => 'main',
    
    // Custom repository
    'https://example.com/razy-modules/' => null,
];
```

## Workflow

### Creating a Module Repository

1. **Package your modules:**
   ```bash
   php Razy.phar pack vendor/module-a 1.0.0
   php Razy.phar pack vendor/module-b 1.0.0
   ```

2. **Generate index:**
   ```bash
   php Razy.phar publish
   ```

3. **Push to GitHub:**
   ```bash
   cd packages
   git init
   git add .
   git commit -m "Initial repository"
   git remote add origin https://github.com/username/razy-modules.git
   git push -u origin main
   ```

### Using a Module Repository

1. **Configure repository:**
   Create `repository.inc.php`:
   ```php
   <?php
   return [
       'https://github.com/username/razy-modules/' => 'main',
   ];
   ```

2. **Search for modules:**
   ```bash
   php Razy.phar search database
   ```

3. **Install module:**
   ```bash
   php Razy.phar install vendor/module --from-repo
   ```

## API Reference

### RepositoryManager Class

```php
use Razy\RepositoryManager;

// Initialize with repositories
$manager = new RepositoryManager([
    'https://github.com/username/repo/' => 'main',
]);

// Search modules
$results = $manager->search('database');

// Get module info
$info = $manager->getModuleInfo('vendor/module');

// Get download URL
$url = $manager->getDownloadUrl('vendor/module', '1.0.0');
```

### RepoInstaller Integration

The `install --from-repo` command internally uses `RepositoryManager` to resolve module codes to download URLs, then passes the URL to `RepoInstaller` for actual downloading and installation.

## Disclaimer and Terms Files

Module packages can include optional files that are displayed during installation:

### disclaimer.txt

If a module's package folder contains `disclaimer.txt`, its content is displayed to the user before installation:

```
repository/
└── vendor/module/
    ├── disclaimer.txt    # Shown before install prompt
    └── 1.0.0.phar
```

Example `disclaimer.txt`:
```
This module is provided "as-is" without warranty.
Please review the documentation before using in production.
```

### terms.txt

If `terms.txt` exists, its content is shown and the user **must** type `yes` or `agree` to proceed:

```
repository/
└── vendor/module/
    ├── terms.txt         # Requires user agreement
    ├── disclaimer.txt    # Optional notice
    └── 1.0.0.phar
```

Example `terms.txt`:
```
By installing this module, you agree to:
1. Use this software only for lawful purposes
2. Not redistribute without permission
3. Accept liability for any damages
```

The installation flow:
1. Module info displayed (description, author, versions)
2. `disclaimer.txt` shown (if exists)
3. `terms.txt` shown with acceptance prompt (if exists)
4. Final confirmation to install

## Best Practices

1. **Version Numbering:** Use semantic versioning (MAJOR.MINOR.PATCH)
2. **Module Naming:** Use vendor/module format consistently
3. **Checksums:** Always verify SHA256 checksums in production
4. **Documentation:** Include README.md in packaged modules
5. **Updates:** Run `publish` after adding/updating any module version
6. **Legal:** Include terms.txt for modules with license requirements
7. **Notices:** Use disclaimer.txt for important usage information

## Troubleshooting

### Module not found
- Verify repository URL in `repository.inc.php`
- Run `search` to confirm module exists
- Use `--refresh` to clear cached index

### Download fails
- Check internet connection
- Verify repository is accessible
- For private repos, ensure authentication is configured

### Pack fails
- Verify module exists at specified path
- Check file permissions
- Ensure module has proper package.php

## Test Verification

### Publish Command Test (February 10, 2026)

**Test Setup:**
- Repository: `RayFungHK/razy-demo-index`
- Module: `demo/demo_index` v1.0.0
- Token: Fine-grained PAT with `Contents: Read and write` permission

**Configuration (`packages/publish.inc.php`):**
```php
<?php
return [
    'token' => 'github_pat_xxx...',
    'repo' => 'RayFungHK/razy-demo-index',
];
```

**Commands Executed:**
```bash
# 1. Pack the module
php Razy.phar pack demo/demo_index 1.0.0
# Output: [SUCCESS] Module packaged successfully!

# 2. Publish to GitHub
php Razy.phar publish --push -v
```

**Test Result: ✅ PASSED**

```
Repository Publisher
Generate repository index from packaged modules

Config loaded from: publish.inc.php
Repository: RayFungHK/razy-demo-index (branch: main)

[CHECK] Fetching existing tags from GitHub...

[VENDOR] demo
    Changelog found for 1.0.0
[✓] demo/demo_index (1 versions)
    Latest: 1.0.0
    Versions: 1.0.0

[✓] Generated: index.json

[SUCCESS] Repository index published!

Summary:
  Modules: 1
  Versions: 1
  New versions to publish: 1

[PUSH] Uploading to GitHub: RayFungHK/razy-demo-index

[✓] index.json
[✓] demo/demo_module/manifest.json

[RELEASES] Creating GitHub Releases...
[✓] Tag: demo-demo_module-v1.0.0
[✓] Release: demo-demo_module-v1.0.0
[✓] Asset: 1.0.0.phar

[SUCCESS] All files uploaded to GitHub!
```

**Files Published:**
- `index.json` - Master repository index
- `demo/demo_module/manifest.json` - Module metadata in repo
- GitHub Release `demo-demo_module-v1.0.0` with `1.0.0.phar` attached

**Live Repository:** https://github.com/RayFungHK/razy-demo-index

### Install Command Test (Updated February 10, 2026)

**Test Command:**
```bash
php Razy.phar install demo/demo_module --from-repo --yes
```

**Test Result: ✅ PASSED**
```
[SEARCH] Looking for module: demo/demo_module
[✓] Found module: demo/demo_module
[✓] Selected version: 1.0.0
[AUTO] Installing to shared modules (default)
[✓] Download URL: https://github.com/RayFungHK/razy-demo-index/releases/download/demo-demo_module-v1.0.0/1.0.0.phar
[DOWNLOAD] Starting download...
[✓] Downloaded (9.06 KB)
[EXTRACT] Extracting module...
[✓] Extracted to: shared/module/demo/demo_module
[SUCCESS] Module installed!
```

### Sync Command Test (February 10, 2026)

**Distributor Config (`sites/testsite/repository.inc.php`):**
```php
<?php
return [
    'repositories' => [
        'https://github.com/RayFungHK/razy-demo-index/' => 'main',
    ],
    'modules' => [
        'demo/demo_module' => [
            'version' => 'latest',
            'is_shared' => true,
        ],
    ],
];
```

**Test Command:**
```bash
php Razy.phar sync testsite --yes
```

**Test Result: ✅ PASSED**
```
Module Sync
Distributor: testsite

[REPOS] Repositories configured:
    https://github.com/RayFungHK/razy-demo-index/ (main)
[MODULES] Modules to sync: 1
[CHECK] demo/demo_module
    [DOWNLOAD] 1.0.0.phar
    [✓] Installed v1.0.0 (9.06 KB) to shared

Summary
  Installed: 1
  Skipped: 0
```

## See Also

- [RepoInstaller Guide](REPO-INSTALLER.md) - Direct GitHub/URL installation
- [Module Structure](../documentation/MODULE-STRUCTURE.md) - Module organization
- [Distributor Guide](../documentation/DISTRIBUTOR-GUIDE.md) - Distributor management
