# Razy Framework Developer Wiki

**Purpose**: Reference documentation for developers and LLM agents building modules with Razy Framework.

---

## Quick Links

| Topic | Description |
|-------|-------------|
| [Class Reference](CLASS-REFERENCE.md) | **Complete class library overview with task checklists** |
| [Optimization Suggestions](OPTIMIZATION-SUGGESTIONS.md) | **Issues found & recommended fixes for Razy devs** |
| [Agent Pipeline](AGENT-PIPELINE.md) | Automated workflow for feature development |
| [Module Development](MODULE-DEVELOPMENT.md) | Step-by-step module creation guide |
| [Event System](EVENT-SYSTEM.md) | Event firing and listening patterns |
| [Route System](ROUTE-SYSTEM.md) | URL parameter capture with addRoute() |
| [Testing Workflow](TESTING-WORKFLOW.md) | Test server setup and validation |

---

## Development Pipeline Overview

```
┌─────────────────────────────────────────────────────────────────┐
│                    DEVELOPMENT PIPELINE                          │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│  1. IMPLEMENT                                                    │
│     ├── New Feature / Class / Fix / Update / Deletion / Modify  │
│     └── Create module files in correct structure                 │
│                                                                  │
│  2. WORKBOOK TEST                                                │
│     ├── Start PHP test server                                    │
│     ├── Test all endpoints                                       │
│     └── Validate JSON/HTML responses                             │
│                                                                  │
│  3. SUMMARIZE                                                    │
│     ├── Document what was implemented                            │
│     ├── Note any issues/workarounds discovered                   │
│     └── List files created/modified                              │
│                                                                  │
│  4. UPDATE DOCUMENTATION                                         │
│     ├── Update wiki (docs/wiki/)                                 │
│     ├── Update LLM-CAS.md                                        │
│     └── Add reference to workbook if needed                      │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

---

## Reference Modules

| Module | Location | Demonstrates |
|--------|----------|--------------|
| `event_demo` | `test-razy-cli/sites/mysite/demo/event_demo/` | Firing events via `$this->trigger()` |
| `event_receiver` | `test-razy-cli/sites/mysite/demo/event_receiver/` | Listening to events via `$agent->listen()` |
| `route_demo` | `test-razy-cli/sites/mysite/demo/route_demo/` | URL parameter capture with `addRoute()` |

---

## Module Structure Template

```
sites/{dist}/{vendor}/{module_code}/
├── module.php                    # Module metadata (REQUIRED)
└── {version}/                    # e.g., "default"
    ├── package.php               # Version config (REQUIRED)
    └── controller/
        ├── {module_code}.php     # Main controller (REQUIRED)
        └── {module_code}.{route}.php  # Route handlers
```

**Critical Path Rules**:
- ✅ `sites/mysite/demo/my_module/` 
- ❌ `sites/mysite/modules/demo/my_module/` (NO "modules/" folder)

---

## Agent Automation Triggers

When an agent completes a task:

1. **Feature Complete** → Update wiki + LLM-CAS.md
2. **Bug Fix** → Add to Troubleshooting section
3. **New Pattern** → Create wiki page + add reference module
4. **API Change** → Update `docs/usage/Razy.ClassName.md`

See [Agent Pipeline](AGENT-PIPELINE.md) for detailed automation workflow.
