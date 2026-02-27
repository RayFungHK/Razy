# Razy\PackageManager

## Summary
- Composer package fetch/update for distributor autoload.
- Downloads packages from packagist and extracts autoload paths.

## Construction
- `new PackageManager($distributor, $packageName, $versionRequired, $notify)`.

## Key methods
- `fetch()`: resolve package metadata.
- `validate()`: download and extract if needed.
- `UpdateLock()`: persist lock.json.

## Usage notes
- Uses `vc()` for version matching.
- Notifies progress via callback types like `download_progress`.
