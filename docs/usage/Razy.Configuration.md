# Razy\Configuration

## Summary
- Config file wrapper built on `Collection`.
- Supports PHP, JSON, INI, and YAML formats.
- Auto-detects format by file extension (.php, .json, .ini, .yaml, .yml).

## Construction
- `new Configuration($path)` loads existing file if present.

## Key methods
- `offsetSet()`: marks changes.
- `save()`: persist changes in original format.

## Supported Formats
- **PHP**: `.php` - Returns PHP array
- **JSON**: `.json` - JSON encoding/decoding
- **INI**: `.ini` - INI file format
- **YAML**: `.yaml`, `.yml` - YAML format (see [Razy.YAML.md](Razy.YAML.md))

## Usage Example

```php
// Load any format
$config = new Configuration('config/app.yaml');

// Access data
echo $config['database']['host'];

// Modify
$config['database']['port'] = 5432;

// Save (preserves format)
$config->save();
```

## Usage notes
- Format is detected by file extension
- `save()` method automatically calls format-specific save method
- Changes are tracked via `offsetSet()` override
- Only modified configs need saving
