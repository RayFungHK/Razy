# Test Reports

**Location**: `docs/test_reports/`  
**Purpose**: Mandatory documentation for all notebook test executions  
**Naming Pattern**: `<yyyy-ddmm>_<test-short-summary>.md`

---

## Report Index

| Report | Feature Tested | Date | Status |
|--------|---------------|------|--------|
| [2026-1002_dependency-auto-installation.md](2026-1002_dependency-auto-installation.md) | Module dependency auto-installation | 2026-02-10 | ✅ PASSED |
| [2026-1002_repository-system-github-releases.md](2026-1002_repository-system-github-releases.md) | Module repository with GitHub Releases | 2026-02-10 | ✅ PASSED |
| [2026-1002_composer-prerequisite-validation.md](2026-1002_composer-prerequisite-validation.md) | Composer version constraint validation | 2026-10-02 | ✅ PASSED |
| [2026-1002_module-compose-integration.md](2026-1002_module-compose-integration.md) | Module compose CLI integration | 2026-10-02 | ✅ PASSED |

---

## Report Requirements

Per **LLM-CAS.md Rule 14**, all notebook tests MUST have a corresponding report containing:

1. **Test name and purpose**
2. **All test steps** with code/commands
3. **Each run result** (pass/fail with actual output)
4. **Expected vs Actual** comparison table
5. **Date and notebook cell references**

---

## Report Template

**Filename**: `<yyyy-ddmm>_<test-short-summary>.md`  
**Example**: `2026-1002_composer-prerequisite-validation.md` (February 10, 2026)

```markdown
# Test Report: [Feature Name]

**Test Date**: [Date]  
**Notebook**: `RazyProject-Building.ipynb`  
**Feature**: [Feature description]  
**Status**: ✅ ALL TESTS PASSED / ❌ SOME TESTS FAILED

---

## Test Overview

[Brief description of what the tests validate]

---

## Test 1: [Test Name]

**Cell**: [Cell number] (Execution Count: [N])  
**Purpose**: [What this test validates]

### Test Steps

[Code or commands executed]

### Run Result

[Actual output from test execution]

### Expected vs Actual

| Test Case | Expected | Actual | Status |
|-----------|----------|--------|--------|
| ... | ... | ... | ✅/❌ |

**Test 1 Result**: ✅ **PASSED** / ❌ **FAILED**

---

## Final Summary

| Test | Description | Assertions | Result |
|------|-------------|------------|--------|
| ... | ... | ... | ✅/❌ |

---

## Related Files

| File | Purpose |
|------|---------|
| ... | ... |
```

---

## Related Documentation

- [LLM-CAS.md](../../LLM-CAS.md) - Framework rules (Rule 14)
- [TESTING.md](../guides/TESTING.md) - Test framework guide
- [RazyProject-Building.ipynb](../../RazyProject-Building.ipynb) - Main test notebook
