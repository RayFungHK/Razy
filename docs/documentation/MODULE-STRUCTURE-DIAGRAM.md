# Razy Module Structure Diagram

## Directory Hierarchy Visualization

```
RAZY FRAMEWORK ROOT
└── sites/
    └── {distributor_code}/
        ├── dist.php
        ├── modules/
        │   ├── {vendor_1}/
        │   │   ├── {module_A}/
        │   │   │   ├── module.php                    (optional)
        │   │   │   ├── default/                      (version folder)
        │   │   │   │   ├── package.php               [REQUIRED]
        │   │   │   │   ├── controller/
        │   │   │   │   │   └── {module_A}.php        [REQUIRED]
        │   │   │   │   ├── view/
        │   │   │   │   ├── src/
        │   │   │   │   ├── plugin/
        │   │   │   │   └── data/
        │   │   │   ├── 1.0.0/                        (version folder)
        │   │   │   │   ├── package.php
        │   │   │   │   ├── controller/
        │   │   │   │   │   └── {module_A}.php
        │   │   │   │   └── ... (rest of structure)
        │   │   │   └── dev/                          (version folder)
        │   │   │       ├── package.php
        │   │   │       └── controller/
        │   │   │           └── {module_A}.php
        │   │   └── {module_B}/
        │   │       └── default/
        │   │           ├── package.php
        │   │           └── controller/
        │   │               └── {module_B}.php
        │   └── {vendor_2}/
        │       └── {module_C}/
        │           └── default/
        │               ├── package.php
        │               └── controller/
        │                   └── {module_C}.php
        ├── plugins/
        ├── data/
        └── controller/
```

## Real-World Example

```
EXAMPLE APPLICATION: Blog Engine
├── sites/
│   └── myblog/
│       ├── dist.php
│       ├── modules/
│       │   ├── blog/                    (vendor namespace)
│       │   │   ├── article/             (module code: article)
│       │   │   │   ├── module.php       (optional config)
│       │   │   │   ├── default/         (version: default)
│       │   │   │   │   ├── package.php
│       │   │   │   │   ├── controller/
│       │   │   │   │   │   └── article.php
│       │   │   │   │   ├── view/
│       │   │   │   │   │   ├── list.tpl
│       │   │   │   │   │   ├── detail.tpl
│       │   │   │   │   │   └── form.tpl
│       │   │   │   │   ├── src/
│       │   │   │   │   │   ├── ArticleService.php
│       │   │   │   │   │   └── ArticleRepository.php
│       │   │   │   │   ├── plugin/
│       │   │   │   │   └── data/
│       │   │   │   └── 2.0.0/           (version: 2.0.0 - newer)
│       │   │   │       ├── package.php
│       │   │   │       └── controller/
│       │   │   │           └── article.php
│       │   │   └── comment/             (module code: comment)
│       │   │       └── default/
│       │   │           ├── package.php
│       │   │           └── controller/
│       │   │               └── comment.php
│       │   └── system/                  (vendor namespace)
│       │       ├── auth/                (module code: auth)
│       │       │   └── 1.0.0/
│       │       │       ├── package.php
│       │       │       └── controller/
│       │       │           └── auth.php
│       │       └── email/               (module code: email)
│       │           └── 1.5.0/
│       │               ├── package.php
│       │               └── controller/
│       │                   └── email.php
│       ├── plugins/
│       ├── data/
│       │   ├── cache/
│       │   ├── uploads/
│       │   └── logs/
│       └── controller/
```

## Module Code Mapping

### Vendor/Module Code Identification

The module path structure uses this pattern:
```
modules/{vendor}/{module_code}/{version}/
```

**Example**: `modules/blog/article/default/`
- Vendor: `blog`
- Module Code: `article`
- Version: `default`

**In dist.php**, referenced as:
```php
'modules' => [
    '*' => [
        'blog/article' => 'default',  // {vendor}/{module_code} => {version}
    ],
],
```

**Module Path Array** (how Razy internally tracks it):
```php
// Distributor scans:
DIST/modules/{vendor}/{module_code}/{version}/

// Creates registry:
[
    'blog/article' => [
        'path' => DIST/modules/blog/article,
        'versions' => ['default', '2.0.0'],
        'requires' => 'default',  // When 'dist.php' omits version
    ]
]
```

## Version Selection Flow Diagram

```
dist.php
    ↓
'modules' => [...
    'blog/article' => 'default',    ← Explicit version
    'blog/comment' => '1.5.0',      ← Specific version
    'system/auth',                  ← No version = defaults to 'default'
]
    ↓
Distributor::scanModule($code)  (Distributor.php:333)
    ↓
Version Resolution:
    If explicit version in dist.php
        └─→ Load: modules/{vendor}/{module}/{version}/
    Else (no version specified)
        └─→ Load: modules/{vendor}/{module}/default/
    ↓
Load package.php from version folder
    ↓
Load controller/{module_code}.php
    ↓
✓ Module Ready!
```

## File Type Organization

### By Directory Level

**Module Root** (e.g., `modules/blog/article/`)
- `module.php` - Module-level configuration (optional)
- Plain folder with version subdirectories

**Version Root** (e.g., `modules/blog/article/default/`)
- `package.php` - **[REQUIRED]** Module entry point
- Subdirectories: `controller/`, `view/`, `src/`, etc.

**Controller** (e.g., `modules/blog/article/default/controller/`)
- `{module_code}.php` - **[REQUIRED]** Main controller file
  - Anonymous class extending `\Razy\Controller`
  - Each public method = one action
  - Handles HTTP requests for this module

**Optional Subdirectories**:
- `view/` → Template files (*.tpl)
- `src/` → PHP classes and services
- `plugin/` → Module-specific plugins
- `data/` → Static data, config, defaults
- `model/` → Data models (if not in src/)
- `config/` → Configuration files (if not in data/)

## @llm Documentation Points

For LLMCASGenerator to extract documentation:

```
modules/blog/article/default/
├── package.php
│   /** @llm Module-level doc */            ← Module Overview
│
├── controller/article.php
│   /** @llm Action-level doc */            ← Action Descriptions
│   public function list() { }
│   public function detail() { }
│   public function create() { }
│
├── view/list.tpl
│   {# @llm Template-level doc #}           ← Template Purpose
│
├── src/ArticleService.php
│   /** @llm Class-level doc */             ← Service Documentation
│   class ArticleService { }
│
└── data/defaults.php
    // May contain configuration-related comments
```

## Quick Copying Template

When creating a new module, copy this structure:

```
mkdir -p modules/{vendor}/{module}/{version}/controller
mkdir -p modules/{vendor}/{module}/{version}/view
mkdir -p modules/{vendor}/{module}/{version}/src

# Create required files
cat > modules/{vendor}/{module}/{version}/package.php << 'PHP'
<?php
return [
    'name' => 'Module Name',
    'version' => '1.0.0',
];
PHP

cat > modules/{vendor}/{module}/{version}/controller/{module}.php << 'PHP'
<?php
use Razy\Controller;
return new class extends Controller {
    public function index() {
        return "Hello from {module}!";
    }
};
PHP

# Add to dist.php
# 'modules' => [
#     '*' => [
#         '{vendor}/{module}' => '{version}',
#     ],
# ],
```

## Size/Scale Reference

### Minimal Module
Files: 2 files, 1 folder level
Size: ~1 KB
Module: Essential functionality only

### Standard Module
Files: 5-10 files, 3 folder levels
Size: ~10-50 KB
Module: Complete with views, services

### Large Module
Files: 20+ files, 5+ folder levels
Size: ~100+ KB
Module: Full application subsystem

## Key Takeaways

1. **Structural**: Version folder MUST exist (default/dev/1.0.0/etc.)
2. **Mandatory Files**:
   - `{version}/package.php`
   - `{version}/controller/{module_code}.php`
3. **Controller MUST**: Extend `\Razy\Controller`, return anonymous class
4. **Naming**: module_code = folder name, controller file name
5. **Version Resolution**: Defaults to 'default' if not specified
6. **Optional**: Everything else (view/, src/, plugin/, data/) is optional
7. **Multi-Version**: Multiple version folders can coexist in same module
8. **Documentation**: Use `@llm` comments for LLMCASGenerator extraction
