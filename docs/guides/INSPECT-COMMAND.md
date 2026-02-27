# Distributor Inspector - Quick Reference

Inspect distributor configuration, domain bindings, and module status.

## Quick Start

```bash
# Inspect a distributor
php Razy.phar inspect mysite

# Show detailed module information
php Razy.phar inspect mysite --details

# Show only modules
php Razy.phar inspect mysite --modules-only

# Show only domain bindings
php Razy.phar inspect mysite --domains-only
```

---

## Command Syntax

```bash
php Razy.phar inspect <distributor_code> [options]
```

### Arguments

| Argument | Description | Required |
|----------|-------------|----------|
| `distributor_code` | Code of the distributor to inspect | Yes |

### Options

| Option | Alias | Description |
|--------|-------|-------------|
| `--details` | `-d` | Show detailed module information (author, description, API, requires) |
| `--modules-only` | `-m` | Show only module information |
| `--domains-only` | - | Show only domain binding information |

---

## Output Information

### Domain Bindings Section

Shows all domains and paths bound to the distributor:

- **Domain**: The hostname (e.g., `localhost`, `example.com`)
- **Path**: URL path prefix (e.g., `/`, `/api`, `/admin`)
- **Identifier**: Full distributor identifier with tag (e.g., `mysite`, `mysite@dev`)
- **Tag**: Version tag (`*` for default, or custom like `dev`, `staging`)
- **Aliases**: Domain aliases pointing to this domain

### Modules Section

Lists all modules loaded by the distributor:

- **Module Code**: Full module identifier (e.g., `vendor/package`)
- **Status**: Current module state
  - 🟢 **LOADED** - Successfully loaded and initialized
  - 🔵 **IN QUEUE** - Ready for loading
  - 🟡 **PENDING** - Waiting for dependencies
  - 🔵 **PROCESSING** - Currently initializing
  - 🔴 **FAILED** - Failed to load
- **Version**: Module version (`default`, `dev`, or semantic version)

#### Detailed Module Information (with `--details`)

Additional information shown with the `--details` flag:

- **Author**: Module author/maintainer
- **Alias**: Module alias name
- **Description**: Module description
- **API**: API name if registered
- **Requires**: List of required modules with versions
- **Path**: Relative path to module directory
- **Shared**: Indicator if module is shared across distributors

### Configuration Section

Shows distributor configuration:

- **Config File**: Path to `dist.php`
- **Global Modules**: Whether shared modules are enabled
- **Autoload**: Whether autoloading is enabled
- **Data Mapping**: Custom data path mappings

---

## Examples

### Example 1: Basic Inspection

```bash
php Razy.phar inspect mysite
```

**Output**:
```
═══════════════════════════════════════════════════════════
  DISTRIBUTOR: MYSITE
═══════════════════════════════════════════════════════════

[DOMAIN BINDINGS]

  1. localhost
     Path:       /
     Identifier: mysite
     Tag:        default

[MODULES]

  Total Modules: 3

  1. vendor/auth (LOADED)
     Version:    1.2.3

  2. vendor/blog (LOADED)
     Version:    2.0.1

  3. vendor/utils (LOADED)
     Version:    default

[CONFIGURATION]

  Config File:     sites/mysite/dist.php
  Global Modules:  Yes
  Autoload:        No

═══════════════════════════════════════════════════════════

[SUCCESS] Inspection complete!
```

### Example 2: Detailed Module Information

```bash
php Razy.phar inspect mysite --details
```

**Additional Output**:
```
  1. vendor/auth (LOADED)
     Version:    1.2.3
     Author:     John Doe
     Alias:      Auth
     Description: User authentication and authorization module
     API:        auth
     Requires:
       - vendor/database (^2.0)
       - vendor/session (^1.5)
     Path:       sites/mysite/vendor/auth/1.2.3
```

### Example 3: Multiple Domains

```bash
php Razy.phar inspect api-site
```

**Output**:
```
[DOMAIN BINDINGS]

  1. api.example.com
     Path:       /
     Identifier: api-site
     Tag:        default
     Aliases:    api-v1.example.com

  2. example.com
     Path:       /api
     Identifier: api-site
     Tag:        default
```

### Example 4: Development Tag

```bash
php Razy.phar inspect mysite@dev
```

**Output**:
```
[DOMAIN BINDINGS]

  1. localhost
     Path:       /dev
     Identifier: mysite@dev
     Tag:        dev
```

### Example 5: Modules Only

```bash
php Razy.phar inspect mysite --modules-only
```

Skips domain binding information, shows only modules and configuration.

### Example 6: Domains Only

```bash
php Razy.phar inspect mysite --domains-only
```

Shows only domain bindings, skips module listing and configuration.

---

## Module Status Reference

| Status | Color | Meaning |
|--------|-------|---------|
| **LOADED** | 🟢 Green | Module successfully loaded and initialized |
| **IN QUEUE** | 🔵 Cyan | Module ready and in loading queue |
| **PENDING** | 🟡 Yellow | Waiting for required modules to load |
| **PROCESSING** | 🔵 Blue | Currently initializing |
| **FAILED** | 🔴 Red | Failed to load (check dependencies or code) |
| **UNKNOWN** | ⚪ White | Unrecognized status |

---

## Use Cases

### 1. Debug Module Loading Issues

```bash
# Check which modules failed to load
php Razy.phar inspect mysite --details
```

Look for modules with **FAILED** or **PENDING** status and check their requirements.

### 2. Verify Domain Configuration

```bash
# Ensure domains are bound correctly
php Razy.phar inspect mysite --domains-only
```

Verify paths, tags, and aliases are configured as expected.

### 3. Check Module Versions

```bash
# List all module versions
php Razy.phar inspect mysite
```

Useful before committing new module versions or during troubleshooting.

### 4. Audit Module Dependencies

```bash
# See complete dependency tree
php Razy.phar inspect mysite --details
```

Review the "Requires" section for each module to understand dependencies.

### 5. Verify Configuration Changes

```bash
# After modifying dist.php
php Razy.phar inspect mysite
```

Confirm global modules, autoload, and data mapping settings are applied.

---

## Common Issues and Solutions

### Issue: "Distributor not found"

**Cause**: Distributor code doesn't exist or isn't configured in `sites.inc.php`

**Solution**:
```bash
# Create the distributor first
php Razy.phar set localhost/path distcode -i
```

### Issue: "No domain bindings found"

**Cause**: Distributor exists but has no domains configured

**Solution**:
```bash
# Bind a domain to the distributor
php Razy.phar set example.com/path mysite
```

### Issue: " Failed to load distributor"

**Cause**: Missing or invalid `dist.php` configuration file

**Solution**:
1. Check `sites/mysite/dist.php` exists
2. Verify the file has valid PHP syntax
3. Ensure `dist` property matches the distributor code

### Issue: All modules show "PENDING" status

**Cause**: Missing module dependencies or circular dependencies

**Solution**:
```bash
# Check module requirements with --details
php Razy.phar inspect mysite --details

# Ensure all required modules are present
```

### Issue: Module shows "FAILED" status

**Cause**: Code error, missing files, or dependency issues

**Solution**:
1. Check module's `package.php` file exists
2. Verify all required modules are loaded
3. Check PHP error logs for details

---

## Integration with Other Commands

### After Installing a Module

```bash
# Install a module
php Razy.phar install owner/module --dist=mysite

# Verify it's loaded
php Razy.phar inspect mysite
```

### Before Committing a Version

```bash
# Check current module versions
php Razy.phar inspect mysite

# Commit new version
php Razy.phar commit mysite@vendor/module 1.2.4

# Verify the new version
php Razy.phar inspect mysite
```

### After Updating Configuration

```bash
# Modify dist.php
# ...

# Verify changes
php Razy.phar inspect mysite

# Update rewrite rules if needed
php Razy.phar rewrite
```

---

## Output Format

### Color Coding

- 🟢 **Green**: Success, loaded, headers
- 🔵 **Cyan**: Section titles, in-progress status
- 🟡 **Yellow**: Warning, pending, domain names
- 🔴 **Red**: Error, failed status
- ⚪ **White**: Normal text, values
- 🔷 **Blue**: Processing status

### Sections Order

1. **Header**: Distributor name
2. **Domain Bindings**: Domains, paths, tags, aliases
3. **Modules**: List with status and versions
4. **Configuration**: Settings from dist.php
5. **Footer**: Success message

---

## Scripting and Automation

### Check if Distributor Exists

```bash
#!/bin/bash
if php Razy.phar inspect mysite > /dev/null 2>&1; then
    echo "Distributor exists"
else
    echo "Distributor not found"
fi
```

### Extract Module Count

```bash
# Parse output to count modules
php Razy.phar inspect mysite | grep "Total Modules:" | awk '{print $3}'
```

### Find Failed Modules

```bash
# List modules with FAILED status
php Razy.phar inspect mysite | grep "FAILED" | awk '{print $2}'
```

---

## Related Commands

| Command | Description |
|---------|-------------|
| `set` | Create or update distributor domain bindings |
| `remove` | Remove a distributor |
| `compose` | Install Composer packages for distributor |
| `install` | Install modules from GitHub |
| `commit` | Commit a new module version |
| `rewrite` | Update .htaccess rewrite rules |

---

## Tips

1. **Use `--details` for debugging** - Shows complete module information including dependencies
2. **Check after config changes** - Verify modifications to `dist.php` are applied correctly
3. **Monitor module status** - Failed or pending modules indicate issues
4. **Verify domain bindings** - Ensure paths and tags match your routing requirements
5. **Compare environments** - Use different tags (dev, staging, production) and inspect each

---

## Version History

- **v0.5.4** (2026-02-08)
  - Initial implementation of inspect command
  - Support for detailed module information
  - Domain binding and alias detection
  - Module status and version display
  - Configuration overview

---

**Last Updated**: February 8, 2026  
**Version**: 0.5.4
