# Razy v0.5.4

Current stable release with GitHub Module Installer, Distributor Inspector, and advanced database features.

**Release Date**: February 10, 2026 | **Status**: Stable | **PHP**: 8.2+

---

### What's New in v0.5.4

#### Interactive App Shell (runapp)
- **New `runapp` CLI command** to run distributors interactively without sites.inc.php
- **Bash-like interactive shell** with prompt: `[distCode]>` or `[distCode@tag]>`
- **Built-in shell commands**: `help`, `info`, `routes`, `modules`, `api`, `run <path>`, `call <api> <cmd>`, `exit`
- **Pipe support for scripting**: `@("routes", "exit") | php Razy.phar runapp appdemo`
- **Cross-platform**: Windows PowerShell UTF-8 BOM handling, `stream_isatty()` TTY detection

### Quick runapp Examples
```bash
# Start interactive shell
php Razy.phar runapp appdemo

# With tag
php Razy.phar runapp mysite@dev

# Pipe commands for scripting
@("routes", "modules", "exit") | php Razy.phar runapp appdemo
```

#### GitHub Module Installer
- **New `install` CLI command** to download and install modules from GitHub
- **Support for public & private repositories** (with personal access token)
- **Multiple installation modes**:
  - Branch installation (default: main)
  - Release installation (latest tagged release)  
  - Distributor module installation
  - Custom path installation
- **Real-time progress tracking** for downloads and extraction
- **Repository validation** with detailed error messages

### Quick Install Examples
```bash
# Install from GitHub (main branch)
php Razy.phar install owner/repo

# Install specific branch
php Razy.phar install owner/repo@develop

# Install latest release
php Razy.phar install owner/repo --release

# Install to distributor modules
php Razy.phar install owner/module --dist=mysite --name=MyModule

# Install from private repo
php Razy.phar install owner/private --token=ghp_token
```

### Documentation 📚
- Complete GitHub installer guide: [docs/guides/GITHUB-INSTALLER.md](../guides/GITHUB-INSTALLER.md)
- Usage examples and troubleshooting
- Security best practices

### Bug Fixes 🐛
- **Fixed .htaccess rewrite generator** - Critical path formatting issues resolved
  - Correct webassets routing for all distributors
  - Fixed data directory mapping
  - Cross-platform path separator compatibility
  - Proper handling of root (/) and sub-path distributors

---

## 📊 Quick Stats

| Metric | Value | Status |
|--------|-------|--------|
| **Version** | 0.5.4 | ✅ Stable |
| **PHP** | 8.2+ | ✅ Modern |
| **PSR-4** | Autoloading | ✅ Compliant |
| **PSR-12** | Coding Style | ✅ Compliant |
| **Tests** | 366 / 641 assertions | ✅ Excellent |
| **Coverage** | ~85% | ✅ Strong |
| **License** | MIT | ✅ Open |

---

## 🚀 Core Features

- ✅ **Modular Architecture** - Dependency-based module loading
- ✅ **Modern PHP 8.2+** - Type declarations, union types, attributes
- ✅ **PSR Standards** - PSR-4, PSR-12 compliant
- ✅ **GitHub Installer** - Download modules from GitHub repositories
- ✅ **runapp Shell** - Interactive CLI for distributor testing
- ✅ **Performance** - FrankenPHP worker mode (7x boost)
- ✅ **OAuth/SSO** - Office 365 / Azure AD integration
- ✅ **YAML Support** - Native parser without dependencies
- ✅ **Composer** - Enhanced package management
- ✅ **Template Engine** - High-performance rendering
- ✅ **Database Layer** - Clean syntax for complex queries
- ✅ **Testing** - Comprehensive PHPUnit suite

---

## 📖 Documentation

| Document | Description |
|----------|-------------|
| [CHANGELOG.md](CHANGELOG.md) | Full version history |
| [GITHUB-INSTALLER.md](../guides/GITHUB-INSTALLER.md) | GitHub module installer guide |
| [PSR-STANDARDS.md](../documentation/PSR-STANDARDS.md) | Code quality guide |
| [docs/TESTING.md](../guides/TESTING.md) | Testing guide |
| [USAGE-SUMMARY.md](../documentation/USAGE-SUMMARY.md) | Framework overview |

---

## 🔗 Quick Links

- **Install**: `composer require rayfunghk/razy`
- **Changelog**: [CHANGELOG.md](CHANGELOG.md)
- **Install from GitHub**: `php Razy.phar install owner/repo`
- **Tests**: `composer test`
- **Style Check**: `composer cs-check`
- **Auto-Fix**: `composer cs-fix`

---

## 📋 Version History

| Version | Date | Highlights |
|---------|------|------------|
| **0.5.4** | 2026-02-10 | runapp CLI, GitHub Module Installer |
| 0.5.3 | 2026-02-08 | PSR-12 + Unit Tests |
| 0.5.2 | 2026-01-XX | YAML + OAuth + Worker Mode |
| 0.5.1 | 2025-XX-XX | Documentation + Production Analysis |
| 0.5.0 | 2025-XX-XX | Initial v0.5 Release |

---

*See [CHANGELOG.md](../CHANGELOG.md) for complete details*
