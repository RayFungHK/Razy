# Module: {$module_code}

**Module Code**: {$module_code}  
**Version**: {$version}  
**Distribution**: {$dist_code}  
**Author**: {$author}

---

## Overview

{$description}

---

## Reference Guides

**Framework Foundation**: Read [Razy skills.md](../../skills.md)  
**Distribution Context**: Read [Distribution: {$dist_code}](../{$dist_code}.md)  
**Usage Docs**: See `/docs/Razy.*.md` for class details

---
<!-- START BLOCK: api_commands_section -->
## API Commands

This module exposes the following commands:
<!-- START BLOCK: api_command -->
- {$command}
    - Description: {$description}
    - File: {$path}
<!-- END BLOCK: api_command -->
<!-- END BLOCK: api_commands_section -->

<!-- START BLOCK: events_section -->
## Lifecycle Events

This module implements the following event listeners:
<!-- START BLOCK: event -->
- {$event_name}
<!-- END BLOCK: event -->
<!-- END BLOCK: events_section -->

<!-- START BLOCK: files_section -->
## File Structure

This module uses the following directory structure:
<!-- START BLOCK: directory -->
- `{$name}/` — {$description}
<!-- END BLOCK: directory -->
<!-- END BLOCK: files_section -->

<!-- START BLOCK: prompts_section -->
## Implementation Notes

Code prompts for LLM understanding:
<!-- START BLOCK: prompt_file -->
### {$file}
<!-- START BLOCK: prompt -->
- Line {$line}: {$prompt}
<!-- END BLOCK: prompt -->
<!-- END BLOCK: prompt_file -->
<!-- END BLOCK: prompts_section -->

<!-- START BLOCK: dependencies_section -->
## Communication Graph

This module requires the following modules:
<!-- START BLOCK: dependency -->
- **{$module}** (v{$version})
<!-- END BLOCK: dependency -->
<!-- END BLOCK: dependencies_section -->

---

## Usage Examples

Check `/workbook/examples/` for sample implementations similar to this module.

---

## Development Guidelines

### Adding a New API Command

1. Create `controller/command_name.php` with closure
2. Register in Controller::__onInit():
   ```php
   $agent->addAPICommand('command_name', 'controller/command_name.php');
   ```
3. Add `// @llm prompt: description` above the return statement
4. Run: `php Razy.phar generate-skills`

### Adding LLM Prompts

**In PHP files**:
```php
/**
 * Some function
 * @llm prompt: This is what LLM needs to know
 */
```

**In TPL templates**:
```tpl
{#llm prompt}This renders the product list{/}
```

---

## File Locations

- **Module Path**: `sites/{$dist_code}/modules/{$module_code}/`
- **Data Path**: `sites/{$dist_code}/data/{optional_subfolder}/`
- **Config**: `sites/{$dist_code}/modules/{$module_code}/package.json`

---

**Generated**: {$generated_at}  
**For**: LLM Code Assistants  
**Last Updated**: {$updated_at}
