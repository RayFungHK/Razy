# Razy\ModuleInfo

## Overview
`ModuleInfo` is a core metadata container for module packages. It loads and validates module configuration from `module.php` and `package.php`, providing secure access to module information and inter-module communication capabilities.

## Summary
- Metadata for a module package, loaded from `module.php` and `package.php`.
- Validates module code, author, assets, and versioning.
- Provides module metadata for secure inter-module communication.

## Source Files
- **Class**: `src/library/Razy/ModuleInfo.php`
- **Creation**: Instantiated by `Module` from module folder and config

---

## Properties & Getters

### Basic Information
- `getCode(): string` - Returns module package code (e.g., `vendor/package`)
- `getClassName(): string` - Returns the package name (last segment of code)
- `getAlias(): string` - Returns human-readable module alias
- `getAuthor(): string` - Returns module author name
- `getDescription(): string` - Returns module description
- `getVersion(): string` - Returns current module version

### Paths
- `getPath(): string` - Returns the full module directory path
- `getContainerPath(bool $isRelative = false): string` - Returns container path (with optional relative path)

### API & Dependencies
- `getAPIName(): string` - Returns API code name if exposed as API
- `getRequire(): array` - Returns array of required modules and versions
- `getPrerequisite(): array` - Returns array of PHP package prerequisites

### Assets & Configuration
- `getAssets(): array` - Returns asset definitions and paths
- `isShadowAsset(): bool` - Returns if shadow asset mode is enabled
- `isPharArchive(): bool` - Returns if module is packaged as Phar
- `isShared(): bool` - Returns if module is a shared module

---

## Module Metadata (v0.5.4+)

### Overview
Module metadata enables secure inter-module communication. A module defines metadata in its `package.php` file, and other modules can read it by passing their own `ModuleInfo` object for verification.

### Security
- ✅ **Verification**: Only code with a valid `ModuleInfo` object can read metadata
- ✅ **Type Safety**: Requires `ModuleInfo` parameter to access metadata
- ✅ **Encapsulation**: Metadata is private and accessed only through getter method

### Method

#### `getMetadata(ModuleInfo $requesterInfo, ?string $key = null): mixed`

**Parameters:**
- `$requesterInfo` (`ModuleInfo`) - The requesting module's ModuleInfo object (used for verification)
- `$key` (string|null) - Optional specific metadata key. If null, returns all metadata

**Returns:**
- All metadata array if `$key` is null
- Specific value if `$key` provided
- `null` if key doesn't exist

**Example:**
```php
// In Module A, read metadata from Module B
$moduleB = $domain->module('vendor/moduleb');
$moduleInfoB = $moduleB->getModuleInfo();
$moduleInfoA = $this->getModuleInfo();

// Get all metadata
$allMetadata = $moduleInfoB->getMetadata($moduleInfoA);

// Get specific metadata key
$version = $moduleInfoB->getMetadata($moduleInfoA, 'version');
$capabilities = $moduleInfoB->getMetadata($moduleInfoA, 'capabilities');
```

### Defining Metadata in package.php

In your module's `package.php`, define metadata organized by module package name:

```php
<?php
return [
    'alias' => 'mymodule',
    'api_name' => 'MyAPI',
    'require' => [
        'vendor/dependency' => '>=1.0.0',
    ],
    'metadata' => [
        'vendor/mymodule' => [
            'version' => '2.0.0',
            'capabilities' => ['export', 'import', 'batch-process'],
            'config' => [
                'timeout' => 30,
                'retry_count' => 3,
                'batch_size' => 100,
            ],
            'api_endpoints' => [
                'export' => '/api/mymodule/export',
                'import' => '/api/mymodule/import',
            ],
        ],
        'vendor/other_module' => [
            'deprecated' => true,
            'migration_path' => 'Use vendor/newmodule instead',
        ],
    ],
];
```

### Use Cases
- **Module Discovery**: Modules query capabilities of other modules
- **Feature Detection**: Check if required features are available
- **Configuration Sharing**: Pass runtime configuration between modules
- **Compatibility Checking**: Verify module versions and compatibility
- **API Documentation**: Document exposed endpoints and methods

---

## Code Format Requirements

### Module Code Pattern
Module codes must follow `vendor/package` format with additional levels (e.g., `vendor/scope/package`):

```
Pattern: ^[a-z0-9]([_.-]?[a-z0-9]+)*(\/[a-z0-9](([_.]?|-{0,2})[a-z0-9]+)*)+$

Valid examples:
- vendor/mypackage
- vendor/scope/mypackage
- vendor/my-package
- vendor/my_package
```

### Invalid Formats
```
❌ Vendor/Package    (uppercase)
❌ vendor_package    (no slash)
❌ vendor/           (missing package)
❌ /vendor           (leading slash)
```

---

## Usage Notes

- **Validation**: `module_code` must follow `vendor/package` pattern - creates error if invalid
- **Parent Dependencies**: `require` array automatically includes implicit parent namespace dependencies
- **Version Format**: Supports semantic versioning and wildcard versions (e.g., `~1.0`, `^2.0`, `*`)
- **Assets**: Asset paths are resolved relative to module directory
- **Shared Modules**: Global modules accessible from shared folder have `isShared() = true`

---

## Example: Complete ModuleInfo Usage

```php
<?php
namespace MyVendor\MyModule;

use Razy\Module;
use Razy\ModuleInfo;

class MyModuleClass {
    private Module $module;
    private ModuleInfo $info;
    
    public function __construct(Module $module) {
        $this->module = $module;
        $this->info = $module->getModuleInfo();
    }
    
    public function inspectModule() {
        echo "Module: " . $this->info->getCode() . "\n";
        echo "Alias: " . $this->info->getAlias() . "\n";
        echo "Version: " . $this->info->getVersion() . "\n";
        echo "Author: " . $this->info->getAuthor() . "\n";
        echo "API Name: " . $this->info->getAPIName() . "\n";
    }
    
    public function readDependentModuleMetadata() {
        // Get another module and read its metadata
        $otherModule = $this->module->getDomain()->module('vendor/other');
        if ($otherModule) {
            $otherInfo = $otherModule->getModuleInfo();
            
            // Read metadata with verification
            $metadata = $otherInfo->getMetadata($this->info);
            $version = $otherInfo->getMetadata($this->info, 'version');
            
            echo "Other Module Capabilities: " . json_encode($metadata['capabilities'] ?? []) . "\n";
        }
    }
    
    public function listAssets() {
        $assets = $this->info->getAssets();
        foreach ($assets as $destination => $assetInfo) {
            echo "Asset: {$destination}\n";
            echo "  Path: {$assetInfo['path']}\n";
            echo "  System Path: {$assetInfo['system_path']}\n";
        }
    }
}
```

---

## Related Classes
- `Module` - Container for ModuleInfo and module runtime
- `Domain` - Manages module loading within a domain
- `Distributor` - Manages module collections

## See Also
- [Razy.Module.md](Razy.Module.md) - Module runtime container
- [Razy.Domain.md](Razy.Domain.md) - Domain and module management
