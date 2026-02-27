# Agent Pipeline - Automated Development Workflow

**Purpose**: Defines the automated workflow for LLM agents to follow when implementing features, fixes, or updates.

---

## Pipeline Stages

### Stage 1: Implement

**Trigger**: User requests new feature/class/fix/update/deletion/modification

**Actions**:
```
1. Analyze request and identify affected components
2. Create/modify files following module structure:
   - sites/{dist}/{vendor}/{module}/module.php
   - sites/{dist}/{vendor}/{module}/default/package.php
   - sites/{dist}/{vendor}/{module}/default/controller/
3. Update dist.php if adding new module
4. Use @llm docblocks for navigation hints
```

**File Checklist**:
- [ ] `module.php` - Module metadata with correct module_code
- [ ] `package.php` - Version entry with dependencies
- [ ] `{module}.php` - Main controller with `__onInit()`
- [ ] `{module}.{route}.php` - Route handlers as needed

---

### Stage 2: Workbook Test

**Trigger**: Implementation complete

**Actions**:
```powershell
# 1. Start test server
cd test-razy-cli
C:\MAMP\bin\php\php8.3.1\php.exe -S localhost:8080

# 2. Test endpoints
$wc = New-Object System.Net.WebClient
$wc.DownloadString("http://localhost:8080/{module}/{route}")

# 3. Validate response
# - JSON endpoints: Check for expected keys
# - HTML endpoints: Check for expected content
# - Events: Verify listener responses
```

**Validation Checklist**:
- [ ] All routes return expected content type
- [ ] JSON responses have correct structure
- [ ] Event listeners receive and respond correctly
- [ ] No PHP errors in server output
- [ ] 404s only for intentionally invalid patterns

---

### Stage 3: Summarize

**Trigger**: All tests pass

**Output Template**:
```markdown
## [Feature Name] Implementation Summary

### Files Created/Modified
- `path/to/file.php` - Description

### Key Patterns Used
- Pattern 1: Description and code example
- Pattern 2: Description and code example

### Issues Discovered
- Issue 1: Workaround applied

### Test Results
- Endpoint 1: ✅ Pass
- Endpoint 2: ✅ Pass
```

---

### Stage 4: Update Documentation

**Trigger**: Summary complete

**Actions**:

1. **Update Wiki** (`docs/wiki/`):
   - Add new page if new pattern/feature
   - Update existing pages if enhancement
   - Add to README.md quick links

2. **Update LLM-CAS.md**:
   - Add to Quick Navigation table if major feature
   - Update relevant sections
   - Add to Reference Modules table

3. **Update Workbook** (if applicable):
   - Add test case to `RazyProject-Building.ipynb`
   - Create example in `workbook/` folder

---

## Automation Rules

### When to Create Wiki Page
| Condition | Action |
|-----------|--------|
| New framework pattern | Create dedicated wiki page |
| Common mistake discovered | Add to Troubleshooting |
| New API method usage | Update METHOD-REFERENCE.md |
| Module structure change | Update MODULE-DEVELOPMENT.md |

### When to Update LLM-CAS.md
| Condition | Section to Update |
|-----------|-------------------|
| New reference module | Reference Modules table |
| New navigation path | Quick Navigation table |
| New troubleshooting | Troubleshooting Guide |
| Architecture change | Framework Architecture |

### Documentation Linking
```markdown
# In wiki pages, always link to:
- Related wiki pages: [Page Name](PAGE-NAME.md)
- LLM-CAS sections: See LLM-CAS.md "Section Name"
- Reference modules: `test-razy-cli/sites/mysite/demo/{module}/`
- Source code: `src/library/Razy/{Class}.php`
```

---

## Agent Task Completion Checklist

Before marking task complete:

- [ ] **Code**: All files created with correct structure
- [ ] **Test**: Server started, endpoints validated
- [ ] **Document**: Wiki updated with patterns/findings
- [ ] **Index**: LLM-CAS.md updated with references
- [ ] **Summary**: Provided to user with file list and test results

---

## Quick Reference Commands

```powershell
# Start server
cd test-razy-cli; C:\MAMP\bin\php\php8.3.1\php.exe -S localhost:8080

# Test JSON endpoint
[System.Net.WebClient]::new().DownloadString("http://localhost:8080/{url}")

# Test HTML endpoint
(Invoke-WebRequest -Uri "http://localhost:8080/{url}" -UseBasicParsing).Content

# Remove file
Remove-Item "path/to/file.php"

# Create directory
New-Item -ItemType Directory -Path "path/to/dir" -Force
```
