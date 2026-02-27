# Razy Agent Pipeline

## 0. Task Intake
- Clarify the task.
- Identify affected modules, routes, templates, plugins, or APIs.

## 1. Load Context
- Read LLM-CAS.md
- Read relevant guides, quick-reference, usage docs
- Read test-specs if tests are involved

## 2. Locate Code
- Use search_codebase
- Read relevant files with read_document

## 3. Plan Change
- Describe minimal required changes
- Identify code, tests, docs, logbook, and test report updates

## 4. Apply Patch
- Generate unified diff
- Apply using apply_patch

## 5. Run Tests
- Call run_tests
- Fix failures iteratively

## 6. Generate Test Report
- Write to memory/test_reports/YYYY-MMDD_<task>.md

## 7. Update Logbook
- Append entry to memory/logbook/LLM_LOGBOOK.md

## 8. Update Documentation
- Update any affected docs using write_document

## 9. Final Summary
- Summarize changes, tests, docs, and logbook updates
