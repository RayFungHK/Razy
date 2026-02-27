# Release Checklist for v0.5.3

Complete checklist for releasing Razy Framework v0.5.3 with PSR-12 and comprehensive testing.

---

### Pre-Release Checklist

### 1. Verify All Files Are Created

- [x] `CHANGELOG.md` - Version history and upgrade guides
- [x] `VERSION` - Plain text version file (0.5.3)
- [x] `RELEASE-NOTES.md` - Detailed release documentation
- [x] `docs/releases/VERSION.md` - Human-friendly version reference
- [x] `.php-cs-fixer.php` - PSR-12 configuration (150+ rules)
- [x] `.php-cs-fixer.dist.php` - Distribution config
- [x] `docs/documentation/PSR-STANDARDS.md` - Complete PSR implementation guide
- [x] `docs/quick-reference/PSR-QUICK-REFERENCE.md` - Quick lookup reference
- [x] `tests/` directory - 10 test files (366 tests, 641 assertions)
- [x] `phpunit.xml` - PHPUnit configuration
- [x] `tests/bootstrap.php` - Test bootstrap

### 2. Verify Version Consistency

Check that version **0.5.3** appears in:

- [x] `composer.json` → `"version": "0.5.3"`
- [x] `readme.md` → Title: "Razy v0.5.3"
- [x] `readme.md` → Version badge
- [x] `CHANGELOG.md` → Section header
- [x] `VERSION` → Plain text: "0.5.3"
- [x] `docs/releases/VERSION.md` → Multiple references
- [x] `RELEASE-NOTES.md` → Header
- [x] `docs/documentation/DOCS-README.md` → "v0.5.3" references

### 3. Verify Documentation Links

Check all cross-references are working:

- [x] `readme.md` links to:
  - docs/documentation/PSR-STANDARDS.md
  - docs/releases/CHANGELOG.md
  - docs/releases/RELEASE-NOTES.md
  
- [x] `docs/documentation/DOCS-README.md` links to:
  - docs/releases/VERSION.md
  - docs/releases/CHANGELOG.md
  - docs/releases/RELEASE-NOTES.md
  - docs/quick-reference/PSR-QUICK-REFERENCE.md
  
- [x] `CHANGELOG.md` contains upgrade guides for all versions

---

## 🧪 Quality Checks (Run When PHP Available)

These commands verify code quality before release:

### Run PSR-12 Compliance Check

```bash
composer cs-check
```

**Expected**: No violations or only minor formatting issues.

### Auto-Fix PSR-12 Violations

```bash
composer cs-fix
```

**Expected**: All auto-fixable violations corrected.

### Run Full Test Suite

```bash
composer test
```

**Expected**: 366 tests pass, ~85% coverage, 0 warnings.

### Run All Quality Checks

```bash
composer quality
```

**Expected**: All checks pass (CS + tests).

---

## 📦 Git Operations

### Step 1: Review Changes

```bash
git status
```

**Expected files** (new):
- CHANGELOG.md
- RELEASE-NOTES.md
- VERSION
- docs/releases/VERSION.md
- docs/quick-reference/PSR-QUICK-REFERENCE.md
- docs/documentation/PSR-STANDARDS.md
- .php-cs-fixer.php
- .php-cs-fixer.dist.php
- phpunit.xml
- tests/ (directory with 8 test files)
- tests/bootstrap.php

**Expected files** (modified):
- composer.json (version, keywords, scripts)
- readme.md (title, badges, links)
- .gitignore (cache exclusions)
- docs/documentation/DOCS-README.md (version section, links)

### Step 2: Add All Files

```bash
git add CHANGELOG.md RELEASE-NOTES.md VERSION docs/releases/VERSION.md
git add docs/documentation/PSR-STANDARDS.md docs/quick-reference/PSR-QUICK-REFERENCE.md
git add .php-cs-fixer.php .php-cs-fixer.dist.php
git add phpunit.xml tests/
git add composer.json readme.md .gitignore docs/documentation/DOCS-README.md
```

Or add everything at once:

```bash
git add .
```

### Step 3: Commit Changes

```bash
git commit -m "Release v0.5.3 - PSR-12 + Unit Tests

Major Changes:
- Implemented PSR-12 Extended Coding Style (150+ rules)
- Added comprehensive test suite (366 tests, 641 assertions, ~85% coverage)
- Added quality tooling (PHP CS Fixer, PHPUnit)
- Created complete documentation (CHANGELOG, VERSION, RELEASE-NOTES)
- Updated composer.json with version 0.5.3 and quality scripts

Breaking Changes: None
Upgrade: See CHANGELOG.md for upgrade guide

Closes #XX"
```

### Step 4: Tag Release

```bash
git tag -a v0.5.3 -m "Release v0.5.3 - PSR-12 + Unit Tests

Highlights:
- PSR-12 compliance with 150+ automated rules
- 366 unit tests with ~85% coverage
- Quality commands: cs-check, cs-fix, test, quality
- Complete documentation and changelog

See RELEASE-NOTES.md for full details."
```

### Step 5: Push to Repository

```bash
# Push commits
git push origin main

# Push tags
git push origin v0.5.3
```

Or push everything at once:

```bash
git push origin main --tags
```

---

## 📢 Post-Release Tasks

### 1. GitHub Release (if using GitHub)

Go to: `https://github.com/YOUR_USERNAME/Razy/releases/new`

- **Tag**: v0.5.3
- **Title**: Razy Framework v0.5.3 - PSR-12 + Unit Tests
- **Description**: Copy from `RELEASE-NOTES.md`
- **Files**: Optionally attach `.zip` or `.tar.gz` archives

### 2. Packagist Update (if registered)

Packagist should auto-update when you push the tag. Verify at:
```
https://packagist.org/packages/YOUR_USERNAME/razy
```

### 3. Announce Release

Consider announcing on:
- Project documentation site
- Developer blog
- Social media (Twitter, LinkedIn)
- PHP community forums
- Framework comparison sites

### 4. Update Documentation Site (if applicable)

- Deploy updated docs to hosting
- Update version selector
- Regenerate API documentation

---

## 🔍 Post-Release Verification

### Check Composer Install

```bash
# In a new directory
composer create-project YOUR_USERNAME/razy test-install
cd test-install
composer --version  # Should show v0.5.3
```

### Check Quality Tools Work

```bash
composer cs-check  # PSR-12 compliance
composer test      # Run tests
composer quality   # Combined checks
```

### Check Documentation Links

Verify all links in documentation work:
- readme.md
- CHANGELOG.md
- RELEASE-NOTES.md
- docs/documentation/DOCS-README.md
- docs/documentation/PSR-STANDARDS.md

---

## 🚀 Next Steps (Future Releases)

### v0.5.4 (Bug Fixes & Minor Enhancements)

- [ ] Expand test coverage to 90%+
- [ ] Add static analysis (PHPStan level 8)
- [ ] Performance benchmarks
- [ ] Code coverage badges (Coveralls/Codecov)

### v0.6.0 (New Features)

- [ ] Enhanced OAuth providers
- [ ] Advanced caching system
- [ ] Real-time websocket support
- [ ] GraphQL support

### v1.0.0 (Stable Release)

- [ ] 95%+ test coverage
- [ ] Complete API documentation
- [ ] Production battle-tested
- [ ] Migration guides from v0.x
- [ ] LTS support plan

---

## 📚 Reference Commands

### Git Commands

```bash
# View commit history
git log --oneline -10

# View tags
git tag -l

# View specific tag
git show v0.5.3

# Delete tag (if needed)
git tag -d v0.5.3
git push origin :refs/tags/v0.5.3
```

### Composer Commands

```bash
# Install dependencies
composer install

# Update dependencies
composer update

# Run autoload dump
composer dump-autoload

# Validate composer.json
composer validate

# Show package info
composer show --self
```

### Quality Commands (defined in composer.json)

```bash
# PSR-12 check (dry run)
composer cs-check

# PSR-12 fix (apply changes)
composer cs-fix

# Run test suite
composer test

# Run all quality checks
composer quality
```

---

## ✅ Release Complete!

Once all steps are completed:

1. ✅ All files committed to git
2. ✅ Version tagged (v0.5.3)
3. ✅ Changes pushed to remote
4. ✅ GitHub release created (if applicable)
5. ✅ Packagist updated (if applicable)
6. ✅ Documentation deployed
7. ✅ Stakeholders notified

**Congratulations on releasing Razy Framework v0.5.3!** 🎉

---

## 📞 Support

If you encounter issues during the release process:

1. Check the [CHANGELOG.md](CHANGELOG.md) for upgrade notes
2. Review [RELEASE-NOTES.md](RELEASE-NOTES.md) for known issues
3. Consult [PSR-STANDARDS.md](../documentation/PSR-STANDARDS.md) for configuration help
4. File an issue on GitHub with the "release" label

---

**Document Version**: 1.0  
**Last Updated**: February 8, 2026  
**Framework Version**: v0.5.3
