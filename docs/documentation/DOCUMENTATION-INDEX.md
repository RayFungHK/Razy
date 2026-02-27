# Razy Module Documentation Index

**Framework**: Razy v0.5.0-237  
**Last Updated**: Based on source code verification  
**Status**: ✅ Complete and Verified

## Quick Start

### "I want to create a module"
👉 Start with: [MODULE-STRUCTURE-DIAGRAM.md#quick-copying-template](MODULE-STRUCTURE-DIAGRAM.md#quick-copying-template)

### "I need reference for exact requirements"
👉 Start with: [MODULE-STRUCTURE.md](MODULE-STRUCTURE.md)

### "I need to set up a distribution"
👉 Start with: [DISTRIBUTOR-GUIDE.md](DISTRIBUTOR-GUIDE.md)

### "I want to understand the corrections made"
👉 Start with: [VERIFIED-MODULE-STRUCTURE.md](VERIFIED-MODULE-STRUCTURE.md)

---

## Documentation Files

### 1. [MODULE-STRUCTURE.md](MODULE-STRUCTURE.md) ⭐ PRIMARY REFERENCE
**Type**: Comprehensive Reference Guide  
**Best For**: Understanding exact requirements and patterns  
**Length**: ~400 lines  
**Sections**:
- Quick Reference
- Mandatory Requirements (Critical!)
- Complete Folder Structure
- File Content Guidelines
- Version Resolution
- Using @llm Prompts for Documentation
- Troubleshooting

**Use When**:
- Creating a new module structure
- Understanding why a module fails to load
- Implementing @llm prompt patterns for LLMCASGenerator
- Need exact file naming and location requirements

---

### 2. [MODULE-STRUCTURE-DIAGRAM.md](MODULE-STRUCTURE-DIAGRAM.md) 📊 VISUAL GUIDE
**Type**: Diagrams and Visual Examples  
**Best For**: Understanding directory structure visually  
**Length**: ~300 lines  
**Sections**:
- Directory Hierarchy Visualization
- Real-World Example (Blog Engine)
- Module Code Mapping
- Version Selection Flow Diagram
- File Type Organization
- @llm Documentation Points
- Quick Copying Template

**Use When**:
- Need visual understanding of folder structure
- Creating new module from scratch
- Understanding how Razy maps module codes to folders
- Want to see real-world example structure

---

### 3. [DISTRIBUTOR-GUIDE.md](DISTRIBUTOR-GUIDE.md) 🏗️ INFRASTRUCTURE REFERENCE
**Type**: Distributor Creation and Configuration  
**Best For**: Setting up distributions and understanding dist.php  
**Updated Sections**:
- Module File Structure (Per Version) - **UPDATED with mandatory requirements**
- Version Folder Requirements - **NEW: Version resolution details**
- Complete Folder Structure
- Distributor Configuration Examples

**Use When**:
- Creating a new distribution
- Configuring dist.php
- Understanding sites.inc.php binding
- Setting up module organization patterns

---

### 4. [VERIFIED-MODULE-STRUCTURE.md](VERIFIED-MODULE-STRUCTURE.md) 🔍 CHANGE SUMMARY
**Type**: Summary of Corrections and Verification  
**Best For**: Understanding what changed and why  
**Length**: ~250 lines  
**Sections**:
- What Changed (Before/After)
- Key Corrections Explained
- Updated Documentation List
- Code Source References with Line Numbers
- Implementation Summary
- Verification Evidence (with code snippets)
- Best Practices

**Use When**:
- Want to understand corrections made to examples
- Need to know which documentation files are updated
- Want to verify claims with source code references
- Learning about version resolution and defaults

---

### 5. [RazyProject-Building.ipynb](RazyProject-Building.ipynb) 📓 INTERACTIVE WORKBOOK
**Type**: Jupyter Notebook with Executable Steps  
**Best For**: Following a complete project workflow  
**Steps Included**:
- Step 0.5: Initialize Razy Application
- Step 1: Define Environment Constants  
- Step 2: Setup Site Configuration (with CLI)
- Step 3: Create Test Module (✅ UPDATED with correct structure)
- Step 4: Distributor Setup
- Step 4b: CLI Management
- Step 5+: Additional configuration and setup

**Use When**:
- Following a complete guided workflow
- Want to execute examples interactively
- Learning step-by-step process

---

### 6. [test-workbook.php](test-workbook.php) 🧪 STANDALONE TEST
**Type**: PHP Script that tests all steps  
**Best For**: Quick verification without Jupyter  
**Updates**:
- ✅ Module creation now uses correct structure
- ✅ Creates package.php and controller/hello.php
- ✅ Proper version folder hierarchy

**Use When**:
- Running quick tests without Jupyter
- Verifying complete workflow on command line
- Debugging initialization process

---

## Mandatory Module Requirements Checklist

Every module MUST have:

```
✅ Version Folder          → sites/mysite/modules/{vendor}/{module}/{version}/
✅ package.php             → {version}/package.php (entry point)
✅ Controller Folder       → {version}/controller/
✅ Main Controller File    → {version}/controller/{module_code}.php
✅ Controller Class        → Anonymous class extending \Razy\Controller
```

Defaults:
```
⚙️  Default Version         → 'default' (if not specified in dist.php)
⚙️  Version Naming          → 'default', 'dev', or semantic (1.0.0, 2.1.3)
```

Optional but Recommended:
```
⭕ View Templates          → {version}/view/*.tpl
⭕ Source Code             → {version}/src/*.php
⭕ Module Plugins          → {version}/plugin/
⭕ Data Files              → {version}/data/
⭕ @llm Documentation      → In docblocks of above files
```

---

## Key Code References (Line Numbers)

### Version Resolution
- **File**: [src/library/Razy/Distributor.php](src/library/Razy/Distributor.php#L333)
- **Line**: 333
- **Code**: `$version = isset($this->requires[$code]) ? ... : 'default'`

### Controller Requirement
- **File**: [src/library/Razy/Module.php](src/library/Razy/Module.php#L131)
- **Lines**: 131-140
- **Code**: Checks if controller file exists, throws error if missing

### Package Entry Point
- **File**: [src/library/Razy/Distributor.php](src/library/Razy/Distributor.php#L307)
- **Line**: 307
- **Code**: Looks for `{version}/package.php`

### Version Validation
- **File**: [src/library/Razy/ModuleInfo.php](src/library/Razy/ModuleInfo.php#L51)
- **Line**: 51
- **Code**: Validates version format and appends to path

---

## Workflow Guide

### For Creating a New Module

1. **Understand Structure** → Read [MODULE-STRUCTURE-DIAGRAM.md](MODULE-STRUCTURE-DIAGRAM.md)
2. **Check Requirements** → Use checklist from [MODULE-STRUCTURE.md](MODULE-STRUCTURE.md)
3. **Create Folders** → Use template from [MODULE-STRUCTURE-DIAGRAM.md](MODULE-STRUCTURE-DIAGRAM.md#quick-copying-template)
4. **Add @llm Prompts** → Follow pattern from [MODULE-STRUCTURE.md](MODULE-STRUCTURE.md#using-llm-prompts-for-documentation)
5. **Configure dist.php** → Reference [DISTRIBUTOR-GUIDE.md](DISTRIBUTOR-GUIDE.md)

### For Troubleshooting

1. **Module won't load?** → See [MODULE-STRUCTURE.md#troubleshooting](MODULE-STRUCTURE.md#troubleshooting)
2. **Version not found?** → Read [VERIFIED-MODULE-STRUCTURE.md](VERIFIED-MODULE-STRUCTURE.md#key-corrections)
3. **Controller error?** → Check [MODULE-STRUCTURE.md#controller-error](MODULE-STRUCTURE.md#controller-error)
4. **dist.php issues?** → Reference [DISTRIBUTOR-GUIDE.md](DISTRIBUTOR-GUIDE.md)

### For LLMCASGenerator Preparation

1. **Understand @llm syntax** → [MODULE-STRUCTURE.md](MODULE-STRUCTURE.md#using-llm-prompts-for-documentation)
2. **See examples** → [MODULE-STRUCTURE-DIAGRAM.md](MODULE-STRUCTURE-DIAGRAM.md#llm-documentation-points)
3. **Check real module** → Step 3 in [RazyProject-Building.ipynb](RazyProject-Building.ipynb)

---

## File Sizes and Complexity

| Document | Size | Complexity | Best For |
|----------|------|-----------|----------|
| [MODULE-STRUCTURE.md](MODULE-STRUCTURE.md) | ~400 lines | Reference | Detailed requirements |
| [MODULE-STRUCTURE-DIAGRAM.md](MODULE-STRUCTURE-DIAGRAM.md) | ~300 lines | Visual | Understanding structure |
| [DISTRIBUTOR-GUIDE.md](DISTRIBUTOR-GUIDE.md) | ~400 lines | Configuration | Distribution setup |
| [VERIFIED-MODULE-STRUCTURE.md](VERIFIED-MODULE-STRUCTURE.md) | ~250 lines | Summary | Understanding changes |
| [test-workbook.php](test-workbook.php) | ~328 lines | Executable | Quick verification |
| [RazyProject-Building.ipynb](RazyProject-Building.ipynb) | ~2200 lines | Interactive | Complete workflow |

---

## Navigation Quick Links

### By Task
- **Create Module** → [MODULE-STRUCTURE-DIAGRAM.md#quick-copying-template](MODULE-STRUCTURE-DIAGRAM.md#quick-copying-template)
- **Understand Requirements** → [MODULE-STRUCTURE.md#mandatory-requirements](MODULE-STRUCTURE.md#mandatory-requirements)
- **Setup Distribution** → [DISTRIBUTOR-GUIDE.md](DISTRIBUTOR-GUIDE.md)
- **Add @llm Documentation** → [MODULE-STRUCTURE.md#using-llm-prompts-for-documentation](MODULE-STRUCTURE.md#using-llm-prompts-for-documentation)
- **Troubleshoot** → [MODULE-STRUCTURE.md#troubleshooting](MODULE-STRUCTURE.md#troubleshooting)
- **View Real Examples** → [MODULE-STRUCTURE-DIAGRAM.md#real-world-example](MODULE-STRUCTURE-DIAGRAM.md#real-world-example)

### By Document Type
- **References** → [MODULE-STRUCTURE.md](MODULE-STRUCTURE.md), [DISTRIBUTOR-GUIDE.md](DISTRIBUTOR-GUIDE.md)
- **Visuals** → [MODULE-STRUCTURE-DIAGRAM.md](MODULE-STRUCTURE-DIAGRAM.md)
- **Summaries** → [VERIFIED-MODULE-STRUCTURE.md](VERIFIED-MODULE-STRUCTURE.md)
- **Interactive** → [RazyProject-Building.ipynb](RazyProject-Building.ipynb)
- **Executable** → [test-workbook.php](test-workbook.php)

---

## Version Information

**Framework Version**: Razy v0.5.0-237  
**PHP Version Required**: 8.2+  
**Last Verification**: Source code analysis complete  
**Documentation Status**: ✅ Complete and Verified  

---

## Summary

This documentation package provides:
- ✅ **Verified Structure** based on Razy framework source code
- ✅ **Comprehensive Reference** with exact requirements
- ✅ **Visual Guides** for understanding directory hierarchy
- ✅ **Practical Examples** with real-world scenarios
- ✅ **Interactive Workbook** for learning by doing
- ✅ **Quick Templates** for creating modules
- ✅ **Troubleshooting Guide** for common issues
- ✅ **Source References** with line numbers for verification

**Start with a few key documents based on your need, then reference others as needed.**

---

**Last Updated**: Based on Razy v0.5.0-237 source code verification
