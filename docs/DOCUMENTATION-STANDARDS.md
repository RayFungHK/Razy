# Documentation Standards

Style guide for all markdown documentation in the Razy framework.

---

## Overview

This document defines the standardized markdown format by document type to ensure consistency, readability, and proper organization across all documentation.

---

## Format Standards by Document Type

### 1. Guides (`docs/guides/`)

**Purpose**: How-to tutorials, feature guides, complete explanations

**Structure**:
```markdown
# Title of Guide

Brief description (1-2 sentences) about what this guide covers.

---

## Table of Contents (for guides with 5+ sections)

1. [Section 1](#section-1)
2. [Section 2](#section-2)
3. [Section 3](#section-3)

---

### Section 1

Content with subsections using #### headings.

#### Subsection A

Details and code examples.

### Section 2

More content...
```

**Rules**:
- H1 title only
- Single-line description with context
- `---` separator before content
- Include Table of Contents if 5+ main sections
- Use `###` for main section headings (not `##`)
- Use `####` for subsection headings
- Minimal emoji (use for visual interest, not required)
- Code blocks with language specified

**Examples**:
- `TEMPLATE-ENGINE-GUIDE.md`
- `PLUGIN-SYSTEM.md`
- `DATABASE-QUERY-SYNTAX.md`
- `TESTING.md`

---

### 2. Quick References (`docs/quick-reference/`)

**Purpose**: Lookup tables, syntax reference, cheat sheets

**Structure**:
```markdown
# Topic Quick Reference

One-line description of what this reference covers.

---

### Section 1

#### Subsection (optional)

| Header | Header | Header |
|--------|--------|--------|
| Data   | Data   | Data   |

### Section 2

Table or code block content...
```

**Rules**:
- H1 title only
- Single-line description
- `---` separator before content
- Use `###` for primary sections
- Use `####` for subsections
- Prefer tables and code blocks over narrative text
- Minimal/no emoji
- Focus on facts, not explanation

**Examples**:
- `DATABASE-QUERY-QUICK-REFERENCE.md`
- `PLUGIN-QUICK-REFERENCE.md`
- `PSR-QUICK-REFERENCE.md`
- `YAML-QUICK-REFERENCE.md`

---

### 3. Documentation (`docs/documentation/`)

**Purpose**: Detailed explanations, implementation details, comprehensive guides

**Structure**:
```markdown
# Document Title

Brief description (1-2 sentences) with optional context.

---

### Main Topic 1

Content and explanations.

#### Subtopic A

Details...

### Main Topic 2

More comprehensive content...
```

**Rules**:
- H1 title only
- Description with context about source/verification if applicable
- `---` separator before content
- Use `###` for main sections (not `##`)
- Use `####` for subsections
- Minimal/no emoji
- Support narrative explanation with code examples
- Include references to related files

**Examples**:
- `IMPLEMENTATION-COMPLETE.md`
- `MODULE-STRUCTURE.md`
- `DISTRIBUTOR-GUIDE.md`
- `ADVANCED_BLOCKS_DOCUMENTATION.md`

---

### 4. Status Files (`docs/status/`)

**Purpose**: Progress tracking, test coverage, verification records

**Structure**:
```markdown
# Document Title

Brief description with status indicator.

**Status**: ✓ Complete | **Date**: February 9, 2026

---

### Summary Section

Key metrics and overview.

### Detailed Sections

Comprehensive status information...
```

**Rules**:
- H1 title only
- Single-line description
- Metadata line with status/date
- `---` separator before content
- Use `###` for main sections (not `##`)
- Use checkmarks (✓, ✅) for verified items
- Professional emoji only (✓, ✅ for status)
- Clear metrics and verification points

**Examples**:
- `STATUS-COMPLETE.md`
- `TEST-COVERAGE-SUMMARY.md`
- `UNIT-TEST-STATUS.md`

---

### 5. Usage/API Reference (`docs/usage/`)

#### Class API Reference (Razy.ClassName.md)

**Purpose**: API documentation for framework classes

**Structure**:
```markdown
# Razy\ClassName

## Summary
Brief description of what the class does.

## Construction
How to instantiate or obtain instances.

## Key methods
List of primary methods with brief descriptions.

## Usage notes
Important patterns, caveats, or design notes.
```

**Rules**:
- H1 title with full namespace (Razy\ClassName)
- Use `##` for section headings (Summary, Construction, Key methods, Usage notes)
- Keep descriptions concise
- Minimal code examples
- Link to related classes in Usage notes

**Examples**:
- `Razy.Agent.md`
- `Razy.Database.md`
- `Razy.Template.md`
- All `Razy.*.md` files

#### Usage Guides (PRODUCTION-USAGE-ANALYSIS.md, etc.)

**Purpose**: Real-world usage patterns and analysis

**Structure**:
```markdown
# Document Title

Description with source information.

**Source**: ... | **Updated**: Date

---

### Main Topic 1

Content...

### Main Topic 2

More content...
```

**Rules**:
- H1 title with version if applicable
- Description with source/date metadata
- `---` separator before content
- Use `###` for main sections
- Include real code examples
- Reference production patterns

---

### 6. Release Documentation (`docs/releases/`)

**Purpose**: Version information, changelog, release checklist

#### VERSION.md

**Structure**:
```markdown
# Razy vX.Y.Z

Brief description of release focus.

**Release Date**: Date | **Status**: Status | **PHP**: Requirements

---

### What's New

#### Feature Category

Feature details...
```

#### RELEASE-NOTES.md / CHANGELOG.md

**Structure**:
```markdown
# Document Title

Description with release info.

**Release Date**: Date | **Type**: Type | **Stability**: Status

---

### Release Highlights

Key points...

### Major Features

Detailed features...
```

**Rules**:
- H1 title with version number
- Single-line description with context
- Metadata line: Date, Status/Type, Requirements
- `---` separator before content
- Use `###` for main sections
- Minimal emoji (only for section interest)
- Reference issue/PR numbers if applicable

**Examples**:
- `VERSION.md`
- `RELEASE-NOTES.md`
- `CHANGELOG.md`
- `RELEASE-CHECKLIST.md`

---

## General Rules (All Documents)

### Headings
- **H1 (#)**: Document title only (one per document)
- **H2 (##)**: Use only for API reference files (Summary, Construction, etc.)
- **H3 (###)**: Main content sections in all other documents
- **H4 (####)**: Subsections or nested content

### Metadata
- Place after H1 title
- Use format: `**Key**: Value` on same line
- Separate multiple metadata with `|`
- Include only essential info (date, status, source)

### Separators
- Use `---` after metadata and before main content
- Creates visual break between header and body

### Code Blocks
- Always specify language: ` ```php `, ` ```bash `, etc.
- Use for code examples, not explanatory text
- Keep blocks focused and concise

### Links
- Use markdown links: `[text](path)`
- Use relative paths from docs folder: `../guides/FILE.md`
- Always link: file names, cross-references, resources

### Lists
- Use `-` for unordered lists (not `*` or `+`)
- Use `1.` for ordered lists
- Nest with proper indentation (2 spaces)

### Tables
- Use for structured data, quick references, comparisons
- Always include header separator: `|---|`
- Align content for readability

### Emoji Usage
- **Discourage** excessive emoji in titles and section headers
- **Allow** minimal emoji for status indicators (✓, ✅) in status files
- **Keep** emoji out of main content flow
- **Use** emoji sparingly for visual interest in long guides

---

## Examples by Category

| Category | File | Key Example | Heading Pattern |
|----------|------|-------------|-----------------|
| Guide | TEMPLATE-ENGINE-GUIDE.md | Tutorial format | H1 → ### sections → #### subsections |
| Quick Ref | PLUGIN-QUICK-REFERENCE.md | Lookup tables | H1 → ### sections → tables |
| Documentation | MODULE-STRUCTURE.md | Deep explanation | H1 → ### sections → examples |
| Status | STATUS-COMPLETE.md | Progress tracking | H1 → ### sections → checklists |
| API Ref | Razy.Agent.md | Class documentation | H1 → ## sections → methods |
| Release | RELEASE-NOTES.md | Version info | H1 → ### sections → features |

---

## Verification Checklist

Before publishing or updating documentation, verify:

- [ ] Title is H1 only (one per document)
- [ ] Description/metadata provided after title
- [ ] `---` separator placed correctly
- [ ] Heading levels follow pattern (### for main sections in most docs)
- [ ] Code blocks have language specified
- [ ] Links use relative paths and markdown syntax
- [ ] Tables are properly formatted with headers
- [ ] No excessive emoji outside status files
- [ ] Related documents are cross-referenced
- [ ] Content is current and verified

---

## Quick Reference

**New document?**
1. Identify document type (Guide, Reference, Documentation, Status, Usage, Release)
2. Use the template structure above
3. Apply heading levels and formatting rules
4. Add to appropriate folder
5. Update index files/TOC if needed

**Updating existing at document?**
1. Follow format of similar documents in same folder
2. Maintain heading level consistency
3. Preserve metadata structure
4. Review links and references

---

**Last Updated**: February 9, 2026  
**Framework**: Razy v0.5.4
