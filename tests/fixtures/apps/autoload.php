<?php

/**
 * Test-only autoloader for razymod/apps library classes.
 *
 * At runtime the distributor's module autoloader resolves razymod\apps\… to
 * modules/apps/default/library/… (ModuleScanner::autoload). The repo suite
 * has no module runtime, so the tests map the same prefix to the same folder.
 */

declare(strict_types=1);

if (!\defined('RAZY_APPS_TEST_AUTOLOAD')) {
    \define('RAZY_APPS_TEST_AUTOLOAD', true);
    \spl_autoload_register(static function (string $class): void {
        $prefix = 'razymod\apps\\';
        if (!\str_starts_with($class, $prefix)) {
            return;
        }
        $file = __DIR__ . '/../../../modules/apps/default/library/' . \str_replace('\\', '/', \substr($class, \strlen($prefix))) . '.php';
        if (\is_file($file)) {
            require $file;
        }
    });
}
