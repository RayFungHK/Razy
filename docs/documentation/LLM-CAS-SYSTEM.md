# LLM-CAS Documentation System

**Purpose**: Auto-generated, AI-friendly documentation for Razy projects
**Scope**: Root, distribution, and module contexts

---

## Overview

The LLM-CAS system produces structured documentation at three levels:

1. **Root level** - Project-wide context and navigation (`LLM-CAS.md`)
2. **Distribution level** - Per-distributor configuration (`llm-cas/{dist_code}.md`)
3. **Module level** - Per-module API, events, and prompts (`llm-cas/{dist_code}/{module}-{version}.md`)

---

## Generate Documentation

```bash
php Razy.phar generate-llm-docs
```

Generate only the root file:

```bash
php Razy.phar generate-llm-docs --root-only
```

---

## Files Produced

```
LLM-CAS.md
llm-cas/{dist_code}.md
llm-cas/{dist_code}/{module}-{version}.md
```

---

## How LLM Agents Should Read

1. Read `LLM-CAS.md` at the project root.
2. If the project uses Razy, read `LLM-CAS.md` inside `Razy.phar` for framework context.
3. Read distribution context in `llm-cas/{dist_code}.md`.
4. Read module context in `llm-cas/{dist_code}/{module}-{version}.md`.
5. Use module usage docs under `docs/usage/` for detailed behavior.

---

## What Gets Extracted

- API commands and internal bindings from `Controller.php`
- Lifecycle event handlers
- Module dependencies
- `@llm prompt` comments in PHP code
- `{#llm prompt}...{/}` tags in TPL templates

---

## Maintenance Notes

- Re-run `generate-llm-docs` after module API changes or new prompt tags.
- When behavior changes, update:
	- `docs/releases/CHANGELOG.md`
	- `docs/releases/RELEASE-NOTES.md`
	- `docs/usage/`
	- `docs/guides/`
	- `docs/quick-reference/`
	- `IMPLEMENTATION-SUMMARY.md`
- Beware UTF8 BOM will broken the PHP script and pollut template file.

