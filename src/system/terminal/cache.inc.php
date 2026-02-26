<?php

/**
 * CLI Command: cache.
 *
 * Manage the Razy framework cache system. Supports clearing all cached data,
 * running garbage collection on expired entries, and displaying cache statistics.
 *
 * Usage:
 *   php Razy.phar cache clear      Clear all cached data
 *   php Razy.phar cache gc         Remove expired cache entries
 *   php Razy.phar cache stats      Display cache statistics
 *   php Razy.phar cache status     Show cache system status
 *
 * @license MIT
 */

namespace Razy;

use Razy\Cache\FileAdapter;
use ReflectionClass;

return function () {
    $args = \func_get_args();
    $subcommand = $args[0] ?? 'status';

    $formatBytes = function (int $bytes): string {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < \count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return \round($bytes, 2) . ' ' . $units[$i];
    };

    switch ($subcommand) {
        case 'clear':
            $this->writeLineLogging('Clearing cache...');
            if (Cache::clear()) {
                $this->writeLineLogging('{@c:green}Cache cleared successfully.{@reset}');
            } else {
                $this->writeLineLogging('{@c:red}Failed to clear cache.{@reset}');
            }
            break;

        case 'gc':
            $this->writeLineLogging('Running garbage collection...');
            $adapter = Cache::getAdapter();
            if ($adapter instanceof FileAdapter) {
                $removed = $adapter->gc();
                $this->writeLineLogging("{@c:green}Garbage collection complete. {$removed} expired entries removed.{@reset}");
            } else {
                $this->writeLineLogging('{@c:yellow}Garbage collection is only supported for the file-based cache adapter.{@reset}');
            }
            break;

        case 'stats':
            $adapter = Cache::getAdapter();
            if ($adapter instanceof FileAdapter) {
                $stats = $adapter->getStats();
                $this->writeLineLogging('Cache Statistics:');
                $this->writeLineLogging(\sprintf('  {@c:cyan}%-14s{@reset} %s', 'Directory:', $stats['directory']));
                $this->writeLineLogging(\sprintf('  {@c:cyan}%-14s{@reset} %d', 'Entries:', $stats['files']));
                $this->writeLineLogging(\sprintf('  {@c:cyan}%-14s{@reset} %s', 'Size:', $formatBytes($stats['size'])));
            } else {
                $adapterClass = \get_class($adapter);
                $this->writeLineLogging("Cache adapter: {$adapterClass}");
                $this->writeLineLogging('{@c:yellow}Detailed statistics are only available for the file-based cache adapter.{@reset}');
            }
            break;

        case 'status':
            $this->writeLineLogging('Cache System Status:');
            $this->writeLineLogging(\sprintf('  {@c:cyan}%-14s{@reset} %s', 'Initialized:', Cache::isInitialized() ? '{@c:green}Yes{@reset}' : '{@c:red}No{@reset}'));
            $this->writeLineLogging(\sprintf('  {@c:cyan}%-14s{@reset} %s', 'Enabled:', Cache::isEnabled() ? '{@c:green}Yes{@reset}' : '{@c:red}No{@reset}'));
            $adapter = Cache::getAdapter();
            $adapterClass = (new ReflectionClass($adapter))->getShortName();
            $this->writeLineLogging(\sprintf('  {@c:cyan}%-14s{@reset} %s', 'Adapter:', $adapterClass));
            if ($adapter instanceof FileAdapter) {
                $stats = $adapter->getStats();
                $this->writeLineLogging(\sprintf('  {@c:cyan}%-14s{@reset} %s', 'Directory:', $stats['directory']));
                $this->writeLineLogging(\sprintf('  {@c:cyan}%-14s{@reset} %d entries (%s)', 'Storage:', $stats['files'], $formatBytes($stats['size'])));
            }
            break;

        default:
            $this->writeLineLogging('{@c:yellow}Usage:{@reset} php Razy.phar cache [clear|gc|stats|status]');
            $this->writeLineLogging('');
            $this->writeLineLogging(\sprintf('  {@c:green}%-10s{@reset} %s', 'clear', 'Clear all cached data'));
            $this->writeLineLogging(\sprintf('  {@c:green}%-10s{@reset} %s', 'gc', 'Remove expired cache entries'));
            $this->writeLineLogging(\sprintf('  {@c:green}%-10s{@reset} %s', 'stats', 'Display cache statistics'));
            $this->writeLineLogging(\sprintf('  {@c:green}%-10s{@reset} %s', 'status', 'Show cache system status'));
            break;
    }
};
