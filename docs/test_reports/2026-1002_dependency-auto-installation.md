# Test Report: Module Dependency Auto-Installation

**Test Date**: 2026-02-10  
**Feature**: Automatic installation of module dependencies during `--from-repo` install  
**Status**: ✅ ALL TESTS PASSED

---

## Test Overview

Tests the automatic detection and installation of module dependencies when using the `install` command with `--from-repo` flag. When a module declares dependencies in its `module.php` `require` array, the installer should automatically download and install missing dependencies.

---

## Bug Fixed

**Issue**: The `--from-repo` installation path in `install.inc.php` exited at line 370 before reaching the dependency checking code (lines 541-670).

**Fix**: Added dependency checking logic to the `--from-repo` code path, executed after successful module extraction but before `exit(0)`.

**File Changed**: `src/system/terminal/install.inc.php` (lines ~350-480)

---

## Test Environment

- **PHP Version**: 8.3.1 (MAMP)
- **OS**: Windows
- **Repository**: `RayFungHK/razy-demo-index` (GitHub)
- **Working Directory**: `test-razy-cli/`

---

## Test Modules

### demo/demo_module (v1.0.1)
```php
return [
    'module_code' => 'demo/demo_module',
    'version' => '1.0.1',
    'require' => [
        'demo/helper_module' => '>=1.0.0',
    ],
];
```

### demo/helper_module (v1.0.0)
```php
return [
    'module_code' => 'demo/helper_module',
    'version' => '1.0.0',
];
```

Both modules published to GitHub Releases:
- `demo-demo_module-v1.0.1` with `1.0.1.phar`
- `demo-helper_module-v1.0.0` with `1.0.0.phar`

---

## Test Cases

### Test 1: Fresh Install with Dependency Resolution

**Command**:
```powershell
Remove-Item -Recurse -Force "shared\module\demo" -ErrorAction SilentlyContinue
php Razy.phar install demo/demo_module --from-repo --yes
```

**Expected**: Install `demo_module`, detect `helper_module` dependency, auto-install it

**Actual Output**:
```
Repository Module Installer
Download and install modules from GitHub or custom repositories

[SEARCH] Looking for module: demo/demo_module
[✓] Found module: demo/demo_module
    Description: A demo module that can be loaded by any distributor
    Author: Test Developer
    Available versions: 1.0.1, 1.0.0
[✓] Selected version: 1.0.1

[AUTO] Installing to shared modules (default)
[✓] Download URL: https://github.com/RayFungHK/razy-demo-index/releases/download/demo-demo_module-v1.0.1/1.0.1.phar
Installing to shared modules
Target: C:\Users\RayFung\VSCode-Projects\Razy\test-razy-cli\shared\module\demo\demo_module
[DOWNLOAD] Starting download...
[✓] Downloaded (9.08 KB)
[EXTRACT] Extracting module...
[✓] Extracted to: C:\Users\RayFung\VSCode-Projects\Razy\test-razy-cli\shared\module\demo\demo_module

[SUCCESS] Module installed!

Module: demo/demo_module@1.0.1
Location: C:\Users\RayFung\VSCode-Projects\Razy\test-razy-cli\shared\module\demo\demo_module

[DEPENDENCIES] This module requires 1 other module(s)
  - demo/helper_module (>=1.0.0)

[INSTALL] Installing dependency: demo/helper_module
  [DOWNLOAD] https://github.com/RayFungHK/razy-demo-index/releases/download/demo-helper_module-v1.0.0/1.0.0.phar
  [✓] Installed demo/helper_module@1.0.0
```

**Result**: ✅ PASSED

---

### Test 2: Already-Installed Dependency Detection

**Command**:
```powershell
php Razy.phar install demo/demo_module --from-repo --yes
```

**Expected**: Detect `helper_module` already installed, skip download

**Actual Output**:
```
[DEPENDENCIES] This module requires 1 other module(s)
  - demo/helper_module (>=1.0.0)
    [INSTALLED] Already installed in shared modules
```

**Result**: ✅ PASSED

---

### Test 3: Verify Both Modules Installed

**Command**:
```powershell
Get-ChildItem "shared\module\demo" -Directory
```

**Expected**: Both `demo_module` and `helper_module` directories exist

**Actual**:
```
demo_module
helper_module
```

**Result**: ✅ PASSED

---

## Results Summary

| Test | Description | Status |
|------|-------------|--------|
| 1 | Fresh install with dependency auto-download | ✅ Pass |
| 2 | Already-installed dependency detection | ✅ Pass |
| 3 | Verify module directories created | ✅ Pass |

---

## Implementation Details

The dependency checking code added to `--from-repo` path:

1. After successful extraction, reads `module.php` from extracted module
2. Checks for `require` array in module config
3. Lists all dependencies with version constraints
4. For each dependency:
   - Checks if already installed (shared or distributor modules)
   - If missing and user confirms (or `--yes` flag), downloads from repository
5. Uses same `RepositoryManager` to resolve download URLs

**Key Code Location**: `src/system/terminal/install.inc.php` lines ~350-480
