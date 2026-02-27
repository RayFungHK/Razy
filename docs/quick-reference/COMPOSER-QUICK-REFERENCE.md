# Composer Integration Quick Reference

Quick lookup for Razy's built-in Composer package manager. See full documentation: [`COMPOSER-INTEGRATION.md`](COMPOSER-INTEGRATION.md)

## Installing Packages

```bash
php main.php compose <distributor-code>
```

## Declaring Dependencies

In your module's `package.php`:

```php
return [
    'label' => 'My Module',
    'version' => '1.0.0',
    'prerequisite' => [
        'vendor/package' => 'version-constraint',
    ],
];
```

## Version Constraint Cheat Sheet

| Constraint | Example | Matches | Description |
|------------|---------|---------|-------------|
| Exact | `1.2.3` | 1.2.3 | Exact version only |
| Wildcard | `1.0.*` | 1.0.0, 1.0.5, ... | Any patch version |
| Any | `*` | (any) | Any version |
| Tilde | `~1.2.3` | >=1.2.3 <1.3.0 | Patch-level updates |
| | `~1.2` | >=1.2.0 <2.0.0 | Minor-level updates |
| Caret | `^1.2.3` | >=1.2.3 <2.0.0 | Compatible updates |
| | `^0.2.3` | >=0.2.3 <0.3.0 | 0.x special handling |
| Range | `1.0 - 2.0` | >=1.0.0 <2.0.0 | Between versions |
| Greater | `>1.2.3` | >1.2.3 | Greater than |
| | `>=1.2.3` | >=1.2.3 | Greater or equal |
| Less | `<2.0.0` | <2.0.0 | Less than |
| | `<=2.0.0` | <=2.0.0 | Less or equal |
| Not equal | `!=1.2.3` | ≠1.2.3 | Not this version |
| AND | `>=1.0,<2.0` | >=1.0 AND <2.0 | Both constraints |
| OR | `^1.0\|\|^2.0` | ^1.0 OR ^2.0 | Either constraint |
| **Dev Branch** | `dev-master` | dev-master | Latest master branch |
| | `dev-develop` | dev-develop | Development branch |
| **Stability** | `1.0@dev` | 1.0-dev | Dev stability |
| | `*@alpha` | Any alpha | Alpha releases |
| | `*@beta` | Any beta | Beta releases |
| | `*@RC` | Any RC | Release candidates |

## Common Examples

```php
// QR Code library (any version)
'prerequisite' => ['chillerlan/php-qrcode' => '*']

// Spreadsheet library (2.0.x only)
'prerequisite' => ['phpoffice/phpspreadsheet' => '~2.0.0']

// HTTP client (1.x or 2.x)
'prerequisite' => ['guzzlehttp/guzzle' => '^1.0||^2.0']

// PDF library (at least 1.5.0)
'prerequisite' => ['mpdf/mpdf' => '>=1.5.0']

// Development branch
'prerequisite' => ['vendor/package' => 'dev-master']

// Beta testing
'prerequisite' => ['symfony/console' => '5.0@beta']

// Allow any unstable version
'prerequisite' => ['experimental/package' => '*@dev']
```

## Tilde (~) vs Caret (^)


## Dev Branches and Stability

### Dev Branches
- `dev-master` - Latest master branch
- `dev-develop` - Development branch
- `dev-feature-x` - Any feature branch

### Stability Flags
- `@stable` - Stable releases only (default)
- `@RC` - Release candidates
- `@beta` - Beta releases
- `@alpha` - Alpha releases  
- `@dev` - Dev/unstable versions

### Stability Order
**Stable** > **RC** > **Beta** > **Alpha** > **Dev**

Razy prefers stable versions unless you specify a lower stability flag.
### Tilde: Last Specified Digit
- `~1.2.3` = `>=1.2.3 <1.3.0` (patch updates)
- `~1.2` = `>=1.2.0 <2.0.0` (minor updates)
- `~1` = `>=1.0.0 <2.0.0` (all 1.x)

### Caret: Semantic Versioning
- `^1.2.3` = `>=1.2.3 <2.0.0` (compatible)
- `^0.2.3` = `>=0.2.3 <0.3.0` (0.x special)
- `^0.0.3` = `>=0.0.3 <0.0.4` (0.0.x special)

## Output Example

```
Update distributor module and package
Validating package: chillerlan/php-qrcode (4.3.4)
 - Downloading: chillerlan/php-qrcode @4.3.4 (45%)
 - Done.
 - chillerlan/php-qrcode: Extracting `chillerlan\QRCode` from `src/`
```

## File Locations

| What | Where |
|------|-------|
| Packages | `SYSTEM_ROOT/autoload/<dist>/<namespace>/` |
| Lock file | `SYSTEM_ROOT/data/packages/lock.json` |
| Temp downloads | System temp directory |

## Best Practices

✅ **DO**
- Use specific constraints: `~2.0.0` instead of `*`
- Test after running `compose`
- Commit `lock.json` to version control
- Document prerequisites in module README

❌ **DON'T**
- Use `*` unless package is very stable
- Forget to run `compose` after adding prerequisites
- Modify autoload directory manually

## Troubleshooting

| Problem | Solution |
|---------|----------|
| Package not found | Check spelling on packagist.org |
| No version available | Relax constraint or check available versions |
| Download failed | Check network, ensure `data/packages/` writable |
| Autoload not working | Verify package uses PSR-0/PSR-4 |
| Version conflict | Use Shared Service Pattern (see below) |

## Version Conflict Resolution

**Problem**: Two modules in same distributor need different versions of same package.

**Solution**: Shared Service Pattern

| Scope | Conflict? | Reason |
|-------|-----------|--------|
| Different distributors | ✅ No | Isolated autoloaders |
| Same distributor | ❌ Yes | Shared PHP process |

**Fix for Same Distributor**:

```php
// 1. Create service module with library
// system/markdown_service/default/package.php
'prerequisite' => ['league/commonmark' => '^2.0']

// 2. Consumer depends on SERVICE, not library
// blog/default/package.php
'required' => ['system/markdown_service' => '*']
'prerequisite' => []  // NO direct library dependency

// 3. Use via API
$html = $this->api('markdown')->parse($text);
```

**Demo modules**: `demo_modules/system/markdown_service/`, `demo_modules/demo/markdown_consumer/`

See full docs: [COMPOSER-INTEGRATION.md](../guides/COMPOSER-INTEGRATION.md#version-conflict-resolution)

## Architecture Flow

```
1. Module declares prerequisite in package.php
   ↓
2. Module loads, calls Distributor::prerequisite()
   ↓
3. Run: php main.php compose <dist>
   ↓
4. Distributor::compose() creates PackageManager
   ↓
5. PackageManager fetches from packagist.org
   ↓
6. Download ZIP, extract PSR-0/PSR-4 paths
   ↓
7. Update lock.json
   ↓
8. SPL autoloader resolves classes
```

## Version Constraint Logic Examples

```php
// In bootstrap.inc.php: vc($requirement, $version)

vc('~1.2.3', '1.2.4')  // ✅ true  (>=1.2.3 <1.3.0)
vc('~1.2.3', '1.3.0')  // ❌ false
vc('^1.2.3', '1.9.0')  // ✅ true  (>=1.2.3 <2.0.0)
vc('^1.2.3', '2.0.0')  // ❌ false
vc('^0.2.3', '0.2.9')  // ✅ true  (0.x special)
vc('^0.2.3', '0.3.0')  // ❌ false
vc('>=1.0,<2.0', '1.5') // ✅ true  (AND)
vc('^1.0||^2.0', '1.5') // ✅ true  (OR)
vc('^1.0||^2.0', '3.0') // ❌ false
```

## Real Production Example

From `document_uploader` module:

```php
'prerequisite' => [
    'chillerlan/php-qrcode' => '*',
    'khanamiryan/qrcode-detector-decoder' => '*',
]
```

Command:
```bash
php main.php compose mysite
```

Result:
- `autoload/mysite/chillerlan/QRCode/` ← QR code generator
- `autoload/mysite/Zxing/` ← QR code decoder

Usage in module:
```php
use chillerlan\QRCode\QRCode;

$qr = new QRCode();
$dataUri = $qr->render('https://example.com');
```

## Migration from Composer

If you have `composer.json`:

```json
{
  "require": {
    "monolog/monolog": "^2.0"
  }
}
```

Convert to Razy:

```php
// In package.php
'prerequisite' => [
    'monolog/monolog' => '^2.0',
]
```

Run: `php main.php compose <dist>`

## See Also

- Full documentation: [`COMPOSER-INTEGRATION.md`](COMPOSER-INTEGRATION.md)
- Production examples: [`usage/PRODUCTION-USAGE-ANALYSIS.md`](usage/PRODUCTION-USAGE-ANALYSIS.md)
- Source code: [`library/Razy/PackageManager.php`](../src/library/Razy/PackageManager.php)
