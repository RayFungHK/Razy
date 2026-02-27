# Repository Module Installer - Quick Reference

Install modules directly from GitHub or custom repository URLs via command line.

## Quick Start

```bash
# Install from GitHub repository (main branch)
php Razy.phar install owner/repo

# Install specific version/tag
php Razy.phar install owner/repo@v1.0.0
php Razy.phar install owner/repo --version=v1.0.0

# Install latest stable release
php Razy.phar install owner/repo --stable

# Install latest release (including pre-release)
php Razy.phar install owner/repo --latest

# Install from specific branch
php Razy.phar install owner/repo@develop
php Razy.phar install owner/repo --branch=develop

# Install from custom URL
php Razy.phar install https://example.com/module.zip ./modules/mymodule

# Install as module for distributor
php Razy.phar install owner/repo-module --dist=mysite --name=MyModule

# Install from private repository
php Razy.phar install owner/private-repo --token=ghp_yourtoken
```

---

## Command Syntax

```bash
php Razy.phar install <repository> [target_path] [options]
```

### Arguments

| Argument | Description | Required |
|----------|-------------|----------|
| `repository` | Repository in format `owner/repo`, `owner/repo@version`, or full URL | Yes |
| `target_path` | Installation path (auto-detected if not specified) | No |

### Repository Formats

| Format | Example | Description |
|--------|---------|-------------|
| GitHub short | `owner/repo` | GitHub repository (uses main branch) |
| GitHub with version | `owner/repo@v1.0.0` | GitHub repo with specific version/branch/tag |
| GitHub URL | `https://github.com/owner/repo` | Full GitHub URL |
| Custom URL | `https://example.com/repo.zip` | Any ZIP download URL |

### Options

| Option | Alias | Description |
|--------|-------|-------------|
| `--latest` | `-l` | Download latest release (any, including pre-release) |
| `--stable` | `-s` | Download latest stable release (non-prerelease) |
| `--version=VER` | `-v=VER` | Specify version/tag (e.g., v1.0.0) |
| `--branch=NAME` | `-b=NAME` | Specify branch name (default: main) |
| `--name=NAME` | `-n=NAME` | Module name for modules directory |
| `--dist=CODE` | `-d=CODE` | Install to distributor's modules directory |
| `--token=TOKEN` | - | Authentication token for private repos |

---

## Installation Modes

### 1. Branch Installation (Default)

Downloads the latest commit from a specific branch:

```bash
# Main branch (default)
php Razy.phar install owner/repo

# Specific branch
php Razy.phar install owner/repo --branch=develop
php Razy.phar install owner/repo@develop
```

**Use when:**
- You want the latest development code
- Repository doesn't use releases
- You need a specific branch

### 2. Version/Tag Installation

Downloads a specific tagged version:

```bash
php Razy.phar install owner/repo@v1.0.0
php Razy.phar install owner/repo --version=v1.0.0
```

**Use when:**
- You need a specific version
- Reproducible builds are important
- Following semver versioning

### 3. Latest Release Installation

Downloads the latest release (including pre-releases):

```bash
php Razy.phar install owner/repo --latest
```

**Use when:**
- You want the newest release features
- Testing pre-release versions is acceptable

### 4. Stable Release Installation

Downloads the latest stable (non-prerelease) release:

```bash
php Razy.phar install owner/repo --stable
```

**Use when:**
- You want stable, tested code
- Production environment deployment
- Avoiding pre-release versions

### 5. Custom URL Installation

Downloads from any ZIP file URL:

```bash
php Razy.phar install https://example.com/module.zip ./modules/mymodule
```

**Use when:**
- Module is hosted outside GitHub
- Private GitLab/Bitbucket repositories
- Internal company repositories

### 6. Distributor Module Installation

Installs directly into a distributor's modules directory:

```bash
# Auto-detect module name from repo
php Razy.phar install owner/user-auth --dist=mysite

# Custom module name
php Razy.phar install owner/user-auth --dist=mysite --name=UserAuth
```

**Installs to:** `sites/{distributor}/modules/{module_name}/`

---

## Examples

### Example 1: Basic Installation

```bash
php Razy.phar install rayfung/auth-module
```

**Result:**
- Downloads from `https://github.com/rayfung/auth-module`
- Uses `main` branch
- Installs to `modules/auth-module/`

### Example 2: Install Specific Version

```bash
php Razy.phar install rayfung/auth-module@v2.1.0
# or
php Razy.phar install rayfung/auth-module --version=v2.1.0
```

**Result:**
- Downloads tag `v2.1.0`
- Installs to `modules/auth-module/`

### Example 3: Install Stable Release

```bash
php Razy.phar install rayfung/blog-engine --stable --dist=mysite --name=BlogModule
```

**Result:**
- Finds latest non-prerelease release
- Installs to `sites/mysite/modules/BlogModule/`

### Example 4: Install from Custom Repository

```bash
php Razy.phar install https://gitlab.company.com/team/module/-/archive/main/module.zip ./modules/company-module
```

**Result:**
- Downloads from custom URL
- Installs to `modules/company-module/`

### Example 5: Install Shared Library

```bash
php Razy.phar install rayfung/shared-lib ./library/vendor/SharedLib
```

**Result:**
- Installs to `SYSTEM_ROOT/library/vendor/SharedLib/`
- Can be used by multiple distributors

### Example 6: Private Repository

```bash
php Razy.phar install mycompany/private-module --token=ghp_yourpersonaltoken12345
```

**Token Requirements:**
- GitHub: Create at Settings → Developer settings → Personal access tokens
- Minimum scope: `repo` (for private repos)
- Token format: `ghp_` prefix + alphanumeric

---

## Default Install Locations

| Mode | Target Path |
|------|-------------|
| Default | `modules/{repo_name}/` |
| With `--dist` | `sites/{dist_code}/modules/{module_name}/` |
| Custom path | Specified path (relative to SYSTEM_ROOT or absolute) |

---

## Features

### ✅ Supported

- [x] GitHub repositories (public & private)
- [x] Custom repository URLs
- [x] Branch downloads
- [x] Tag/version downloads
- [x] Latest release (--latest)
- [x] Stable release (--stable)
- [x] Custom installation paths
- [x] Distributor module installation
- [x] Progress tracking
- [x] Overwrite protection
- [x] Auto-path detection
- [x] Repository validation

### 🔄 Coming Soon

- [ ] GitLab native support
- [ ] Bitbucket native support
- [ ] Install multiple modules at once
- [ ] Version constraints (e.g., `>=1.0.0`)
- [ ] Update existing modules
- [ ] Rollback to previous version
- [ ] Module dependency resolution

---

## Progress Output

During installation, you'll see:

```
Repository Module Installer
Download and install modules from GitHub or custom repositories

Repository: owner/module-name
Target path: /path/to/modules/module-name
Mode: Stable Release

[INFO] Starting installation: owner/module-name
[INFO] Using stable release: v2.1.0
[VALIDATE] Checking repository...
[✓] Repository validated

[DOWNLOAD] Starting download...
 - Progress: 45% (2.3 MB / 5.1 MB)
[✓] Download complete (5.1 MB)

[EXTRACT] Extracting archive...
[✓] Extraction complete
[✓] Files installed to: /path/to/modules/module-name

[SUCCESS] Module installed successfully!
Repository: owner/module-name
Location: /path/to/modules/module-name

[TIP] Check README.md for installation instructions
```

---

## Error Handling

### Common Errors

**Repository not found:**
```
[ERROR] Repository not found or not accessible.

Make sure:
  1. Repository exists: owner/repo
  2. Repository is public (or provide --token for private repos)
  3. You have internet connection
```

**Invalid format:**
```
[ERROR] Invalid repository format. Use "owner/repo", "owner/repo@version", or a full URL
```

**No releases found:**
```
[ERROR] No releases found
        Falling back to main branch
```

**No stable releases:**
```
[ERROR] No stable releases found
        Falling back to main branch
```

---

## Security Considerations

### Token Storage

**❌ Don't:**
```bash
# Don't commit tokens to git
php Razy.phar install owner/repo --token=ghp_mysecrettoken
```

**✅ Do:**
```bash
# Use environment variable
export GITHUB_TOKEN=ghp_mysecrettoken
php Razy.phar install owner/repo --token=$GITHUB_TOKEN

# Or store in config file (gitignored)
php Razy.phar install owner/repo --token=$(cat ~/.github_token)
```

### HTTPS Only

All downloads use HTTPS connections. HTTP is not supported.

---

## API Reference

### RepoInstaller Class

Located at: `src/library/Razy/RepoInstaller.php`

**Source Constants:**
```php
RepoInstaller::SOURCE_GITHUB  // GitHub repository
RepoInstaller::SOURCE_CUSTOM  // Custom URL
```

**Version Constants:**
```php
RepoInstaller::VERSION_LATEST  // 'latest' - Any latest release
RepoInstaller::VERSION_STABLE  // 'stable' - Non-prerelease only
```

**Constructor:**
```php
public function __construct(
    string $repository,        // owner/repo, URL, or owner/repo@version
    string $targetPath,        // Installation path
    ?callable $notify = null,  // Progress callback
    ?string $version = null,   // Version: 'latest', 'stable', or tag/branch
    ?string $authToken = null  // Auth token
)
```

**Main Methods:**
```php
public function install(): bool
public function validate(): bool
public function getRepositoryInfo(): ?array
public function getLatestRelease(): ?array
public function getStableRelease(): ?array
public function getAvailableTags(): array
public function setVersion(string $version): void
```

**Getters:**
```php
public function getSource(): string      // SOURCE_GITHUB or SOURCE_CUSTOM
public function getOwner(): string       // Repository owner (GitHub only)
public function getRepo(): string        // Repository name
public function getBranch(): string      // Current branch
public function getVersion(): string     // Current version setting
public function getCustomUrl(): string   // Custom URL (custom source only)
public function getTargetPath(): string  // Installation target
```

**Notification Types:**
- `RepoInstaller::TYPE_INFO` - General information
- `RepoInstaller::TYPE_DOWNLOAD_START` - Download started
- `RepoInstaller::TYPE_PROGRESS` - Download progress
- `RepoInstaller::TYPE_DOWNLOAD_COMPLETE` - Download finished
- `RepoInstaller::TYPE_EXTRACT_START` - Extraction started
- `RepoInstaller::TYPE_EXTRACT_COMPLETE` - Extraction finished
- `RepoInstaller::TYPE_INSTALL_COMPLETE` - Installation complete
- `RepoInstaller::TYPE_ERROR` - Error occurred

**Example Usage:**
```php
use Razy\RepoInstaller;

// Install latest stable release
$installer = new RepoInstaller(
    'owner/repo',
    '/path/to/target',
    function($type, ...$data) {
        echo "$type: " . implode(', ', $data) . "\n";
    },
    RepoInstaller::VERSION_STABLE
);

if ($installer->validate()) {
    $installer->install();
}
```

---

## Backward Compatibility

For backward compatibility, `GitHubInstaller` is aliased to `RepoInstaller`:

```php
// Old code still works
$installer = new \Razy\GitHubInstaller($repo, $path);

// Equivalent to
$installer = new \Razy\RepoInstaller($repo, $path);
```

---

## Related Commands

| Command | Description |
|---------|-------------|
| `compose` | Install Composer packages for distributor |
| `link` | Create site alias |
| `remove` | Remove site |
| `commit` | Commit module version |

---

## Version History

- **v0.5.5** (2026-02-10)
  - Renamed to RepoInstaller
  - Added custom URL support
  - Added version/tag support (`--version`, `@v1.0.0`)
  - Added `--stable` for non-prerelease releases
  - Added `--latest` for any latest release
  - Backward compatibility alias for GitHubInstaller

- **v0.5.4** (2026-02-08)
  - Initial GitHub installer implementation
  - Support for public/private repositories
  - Branch and release downloads
  - Distributor module installation

---

**Last Updated:** February 10, 2026  
**Version:** 0.5.5
