# Skills: {$app_name}

**Application**: {$app_name}  
**Framework**: Razy v{$version}  
**Purpose**: Context anchor for LLM agents working with this project  
**Generated**: {$date}

---

## Start Here

This document helps LLM agents understand this Razy-based project structure and how to develop with it.

### Reading Order

1. **This file** - Project overview and development guidance
2. **Razy Framework** - Extract and review `Razy.phar` -> `skills.md` for complete framework reference
3. **Distribution context** - `skills/{dist_code}.md` for specific distributions
4. **Module context** - `skills/{dist_code}/{module}.md` for specific modules

---

## Understanding This Project Structure

This project is built with **Razy Framework**. The key concepts:

### Distributions
Each distribution is a separate application instance:
- Located in `sites/{dist_code}/`
- Has its own `dist.php` configuration
- Can enable/disable modules selectively
- Can use different module versions
- Bound to specific domains

### Modules
Reusable functionality packages organized as:
- **Location**: `sites/{dist_code}/modules/{vendor}/{module}/`
- **Metadata**: `module.php` and `package.php` files
- **Code**: `src/` directory with classes
- **Handlers**: 
  - Root level: `controller/{module_code}.{func}.php` (e.g., `hello.main.php`, `hello.list.php`) - prefix prevents naming conflicts
  - Subfolders: `controller/{subfolder}/{func}.php` (e.g., `controller/api/set.php`, `controller/api/auth.php`) - no prefix needed
- **Templates**: `view/` directory with `.tpl` files
- **Versioning**: Multiple versions can exist, distribution chooses which

---

## Development Workflows

Common workflows in this project:
- **Adding routes** - Declare in controller's `$agent->addLazyRoute()`, create matching handler at `controller/{module_code}.{name}.php` or `controller/{subfolder}/{name}.php`
- **Adding API commands** - Declare in controller's `$agent->addAPICommand()`, create handler file matching the path specified
- **Adding event listeners** - Declare in controller's `$agent->listen()`, create handler at specified path
- **Creating templates** - Place `.tpl` files in module's `view/` directory
- **Module dependencies** - Declare in module's `package.php` `required` array (modules that must load first)
- **Composer packages** - Declare in module's `package.php` `prerequisite` array, install via `php main.php compose {dist_code}`

**Handler naming rule**:
- **Root level**: Use prefix `{module_code}.{func}` to prevent conflicts (e.g., route `'main'` → file `hello.main.php`)
- **Subfolders**: No prefix needed (e.g., route `'api/set'` → file `controller/api/set.php`)

Example:
```php
$agent->addLazyRoute(['/' => 'main', 'api' => ['list' => 'list', 'create' => 'process']]);
$agent->addAPICommand(['#set' => 'api/set', 'auth' => 'api/auth']);
// Creates: controller/hello.main.php, controller/hello.list.php, controller/hello.process.php, 
//          controller/api/set.php, controller/api/auth.php
```

**For implementation details**, refer to `Razy.phar/skills.md` for framework classes and patterns.

---

## File Organization

For this project:

```
{dist_code}/
├── skills.md                  # Distribution context
├── dist.php                    # Distribution config & module versions
├── modules/
│   ├── {vendor}/{module}/      # Installed modules
│   │   ├── module.php
│   │   ├── default/
│   │   │   ├── package.php
│   │   │   └── src/
│   │   └── {version}/
│   └── ...
├── data/                       # Distribution-specific persistent data
└── {module}.md                 # Module documentation (in skills/)
```

---

## Common Tasks

### Read Module Documentation
- Find in `skills/{dist_code}/{module}.md`
- Shows API commands, events, file structure
- See example implementations

### Understand Module Dependencies
- Check `{module}/default/package.php` file
- Look for `required` array (modules that must load before this one)
- Understand execution order

### Install Composer Packages
- Check `{module}/default/package.php` `prerequisite` array for required packages
- Run `php main.php compose {dist_code}` to install (downloads from Packagist)
- Packages extract to `autoload/{dist_code}/` for use in your code

### Trace API Command Execution
1. Look up command in module's `package.php`
2. Find handler in `controller/` directory
3. Trace method implementation in `src/`
4. Check for template rendering in `view/`

### Check Module Events
- Review module's `__onInit()` / `__onReady()` methods
- Look for `$agent->listen()` calls
- Check what `$this->trigger()` events are emitted

---

## Template Engine Quick Reference

**Variables**: `{$variable}` or `{$object.property}`

**Conditions**: `{@if $condition}...{/if}` with operators: =, !=, <, >, |, ~

**Loops**: `{@each $array}...{$kvp.key}...{/each}`

**Define**: `{@def "varName" "value"}` or copy from other variable

**Blocks**: `<!-- START BLOCK: name -->...<!-- END BLOCK: name -->`

**Modifiers**: `{$var->modifier:"arg1":"arg2"}`

See `docs/guides/TEMPLATE-ENGINE-GUIDE.md` for complete reference.

---

## Framework Documentation

**Razy Framework Reference**: Extract `Razy.phar` and review `skills.md` at the root.

It contains:
- Startup constants and bootstrap initialization
- Core framework classes and their roles
- Helper functions available globally
- Design patterns and conventions
- Development guidance

---

## Key Reminders

- **Module isolation** - Changes to one distribution don't affect others
- **Version compatibility** - Respect module version numbering (semantic versioning)
- **Template safety** - Be aware of UTF-8 BOM in template files
- **Event listening** - Modules communicate via events, not direct function calls
- **API commands** - Prefer modules' registered commands over direct execution
- **Documentation** - Always check `skills/{dist}/{module}.md` for module specifics

---

**For**: LLM agents developing with this Razy-based application  
**Framework Docs**: Run `php Razy.phar` or check `docs/` folder  
**Module Context**: See `skills/` for distribution and module details

