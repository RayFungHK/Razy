# Distribution: {$dist_code}

**Distribution Code**: {$dist_code}  
**Bound Domains**: {$domain_list}  
**Location**: `sites/{$dist_code}/`

---

## Overview

This is the configuration and module context for the `{$dist_code}` distribution.

**Before continuing**: Read the [Razy skills.md](../../skills.md) for framework-level concepts.

---

## Configuration

**Main Config File**: `sites/{$dist_code}/dist.php`

- **Global Module**: {$global_module}
- **Autoload**: {$autoload}
- **Data Mapping**: {$data_mapping}

---

{$modules_section}

---

## How to Read Module Docs

Each module has its own LLM context file with:

1. **Module Overview** - What the module does
2. **API Commands** - Public API methods
3. **Events Implemented** - Lifecycle event handlers
4. **File Structure** - Brief explanation of each file
5. **LLM Prompts** - Code comments for LLM understanding
6. **Dependencies** - Other modules it requires
7. **Communication Graph** - How it interacts with other modules

---

## Project Layout

```
sites/{$dist_code}/
├── dist.php                    <- Distribution config
├── modules/
│   └── {module_code}/
│       ├── package.json        <- Module metadata
│       ├── src/
│       │   └── Controller.php  <- Main controller
│       ├── controller/         <- API implementations
│       ├── view/               <- Templates (.tpl)
│       ├── plugin/             <- Module plugins
│       └── data/               <- Persistent data
└── data/                       <- Distribution data
```

---

## Integration with Razy Framework

### Module API Access

**From another module in same distribution**:
```php
$result = $this->api('module_code')->api_command($arg);
```

**Cross-distribution** (safe with isolation):
```php
$result = $this->module->getDistributor()
    ->executeInternalAPI('target_module', 'command', [$arg]);
```

See: [Razy skills.md](../../skills.md#cross-module-communication)

---

## Next Steps

1. **Select a module** from the list above
2. **Read its LLM context** markdown file
3. **Reference the module's code** structure
4. **Check examples** in `/workbook/examples/`
5. **Review Razy docs** in `/docs/Razy.*.md`

---

**Generated**: {$generated_at}  
**Generator**: Razy CLI generate-skills  
**Last Updated**: {$updated_at}
