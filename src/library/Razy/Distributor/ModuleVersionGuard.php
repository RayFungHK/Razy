<?php

/**
 * This file is part of Razy.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 *
 * @license MIT
 */

namespace Razy\Distributor;

use Razy\Exception\ModuleVersionException;

/**
 * RZ-35: one version of a module per PHP process.
 *
 * Module classes (library/, controllers that declare a class) carry no
 * version in their names, and a worker process (FrankenPHP worker mode) keeps
 * them across requests. Two versions of one module in one process would mix
 * their code, so the second version is refused before its code loads. Dists
 * that run different versions of a module are served by separate processes
 * (separate worker pools).
 *
 * Only a full boot (Distributor::initialize) claims a version; a metadata scan
 * (Distributor::scanModules, used by `rewrite`) loads no module code and claims nothing.
 */
final class ModuleVersionGuard
{
    /** @var array<string, array{version: string, dist: string, path: string}> Module code => the version this process loaded */
    private static array $claims = [];

    /**
     * Record that this process loads a module version, or refuse a second version.
     *
     * @param string $moduleCode The module code (vendor/package)
     * @param string $version The module version (ModuleInfo::getVersion())
     * @param string $distIdentifier The dist loading it (code@tag)
     * @param string $path The version folder
     *
     * @throws ModuleVersionException When this process already loaded another version of the module
     */
    public static function claim(string $moduleCode, string $version, string $distIdentifier, string $path): void
    {
        $held = self::$claims[$moduleCode] ?? null;
        if (null === $held) {
            self::$claims[$moduleCode] = ['version' => $version, 'dist' => $distIdentifier, 'path' => $path];

            return;
        }

        if ($held['version'] === $version) {
            return;
        }

        throw new ModuleVersionException(
            "Distributor '{$distIdentifier}' cannot load module '{$moduleCode}' version '{$version}' ({$path}): this PHP process "
            . "already loaded version '{$held['version']}' for distributor '{$held['dist']}' ({$held['path']}). One process runs one "
            . 'version of a module, because class names carry no version. Serve these distributors from separate processes '
            . '(separate worker pools), pin the same version in both dist.php files, or restart the workers after changing a pin.',
        );
    }

    /**
     * The version this process loaded for a module, or null.
     */
    public static function claimed(string $moduleCode): ?string
    {
        return self::$claims[$moduleCode]['version'] ?? null;
    }

    /**
     * Forget every claim. For tests that boot dists which never share a real process.
     *
     * @internal
     */
    public static function reset(): void
    {
        self::$claims = [];
    }
}
