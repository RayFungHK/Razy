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

use Razy\Config\ConfigLoader;
use Razy\Exception\ModuleVersionException;
use Razy\Util\PathUtil;
use Razy\Util\VersionUtil;
use Throwable;

/**
 * RZ-35: pick the version folder a distributor loads for a module.
 *
 * A module folder holds one folder per version next to module.php:
 *
 *     vendor/package/module.php
 *     vendor/package/default/package.php   the fallback, loaded when nothing is pinned
 *     vendor/package/1.4.0/package.php     a released version (folder name = semver)
 *     vendor/package/2.0.0/package.php
 *     vendor/package/dev/package.php       loaded only when pinned as 'dev'
 *
 * The pin comes from dist.php 'modules' ('*' merged with the dist tag):
 * - no pin: `default/`, or the module is not loaded when it has no `default/` (as before RZ-35);
 * - a folder name (`default`, `dev`, `1.4.0`): that folder;
 * - `*`: the highest version, else `default/`;
 * - a semver constraint (`^1.4`, `~2.0.0`, `>=1.0 <2.0`, `1.4.*`): the highest version that satisfies it.
 *
 * `default/` takes part in version matching with the 'version' its package.php
 * declares; a numbered folder wins over `default/` at the same version.
 */
final class ModuleVersionResolver
{
    /** The fallback version folder */
    public const DEFAULT_FOLDER = 'default';

    /** A version folder named as a semver number (same shape ModuleInfo accepts, without wildcards) */
    public const REGEX_VERSION = '/^\d+(?:\.\d+){0,3}$/';

    /**
     * Resolve the version folder to load.
     *
     * @param string $moduleCode The module code (vendor/package)
     * @param string $packageFolder The folder holding module.php and the version folders
     * @param string|null $pin The dist.php pin, null when the dist does not pin this module
     * @param string $distIdentifier The dist identifier (code@tag) for the error message
     *
     * @return string|null The version folder name, or null when an unpinned module has no `default/`
     *
     * @throws ModuleVersionException When a pin matches no version folder
     */
    public static function resolve(string $moduleCode, string $packageFolder, ?string $pin, string $distIdentifier): ?string
    {
        if (null === $pin) {
            return self::hasPackage($packageFolder, self::DEFAULT_FOLDER) ? self::DEFAULT_FOLDER : null;
        }

        // A pin naming a folder that exists loads that folder as it is.
        if ((\in_array($pin, [self::DEFAULT_FOLDER, 'dev'], true) || self::isVersion($pin)) && self::hasPackage($packageFolder, $pin)) {
            return $pin;
        }

        $candidates = self::candidates($packageFolder);
        foreach ($candidates as $folder => $version) {
            if ('*' === $pin || VersionUtil::vc($pin, $version)) {
                return $folder;
            }
        }

        if ('*' === $pin && self::hasPackage($packageFolder, self::DEFAULT_FOLDER)) {
            return self::DEFAULT_FOLDER;
        }

        throw new ModuleVersionException(
            "Distributor '{$distIdentifier}' pins module '{$moduleCode}' to '{$pin}' in dist.php, but no version folder of it "
            . 'satisfies that. Available: ' . self::describe($packageFolder, $candidates)
            . ". Add the version folder (with package.php) under '{$packageFolder}', or change the pin.",
        );
    }

    /**
     * Whether a loaded module version satisfies a package.php 'require' constraint.
     *
     * An empty or `*` constraint always holds. A module with no semver version
     * (an undeclared `default/` or `dev/`) is not checked and passes.
     */
    public static function satisfies(string $constraint, string $version): bool
    {
        $constraint = \trim($constraint);
        if ('' === $constraint || '*' === $constraint || !self::isVersion($version)) {
            return true;
        }

        return VersionUtil::vc($constraint, $version);
    }

    /**
     * Whether a string is a semver number as Razy versions use it.
     */
    public static function isVersion(string $version): bool
    {
        return 1 === \preg_match(self::REGEX_VERSION, $version);
    }

    /**
     * The 'version' a package.php declares, or null when it declares none (or not a semver number).
     */
    public static function declaredVersion(string $packageFile): ?string
    {
        if (!\is_file($packageFile)) {
            return null;
        }

        try {
            $settings = (new ConfigLoader())->loadRaw($packageFile);
        } catch (Throwable) {
            return null;
        }

        $declared = \is_array($settings) && \is_string($settings['version'] ?? null) ? \trim($settings['version']) : '';

        return self::isVersion($declared) ? $declared : null;
    }

    /**
     * Versions available for matching, highest first: numbered folders by name,
     * `default/` by its declared version. `dev/` and other names only load by exact pin.
     *
     * @return array<string, string> Folder name => version
     */
    private static function candidates(string $packageFolder): array
    {
        $candidates = [];
        foreach (\scandir($packageFolder) ?: [] as $folder) {
            if (self::isVersion($folder) && self::hasPackage($packageFolder, $folder)) {
                $candidates[$folder] = $folder;
            }
        }

        $declared = self::declaredVersion(PathUtil::append($packageFolder, self::DEFAULT_FOLDER, 'package.php'));
        if (null !== $declared) {
            $candidates[self::DEFAULT_FOLDER] = $declared;
        }

        \uksort($candidates, static function (string $a, string $b) use ($candidates): int {
            $order = \version_compare(
                (string) VersionUtil::standardize($candidates[$b]),
                (string) VersionUtil::standardize($candidates[$a]),
            );

            // Same version: the numbered folder wins over default/
            return 0 !== $order ? $order : (self::DEFAULT_FOLDER === $a ? 1 : (self::DEFAULT_FOLDER === $b ? -1 : 0));
        });

        return $candidates;
    }

    /**
     * Human list of the version folders for an error message.
     *
     * @param array<string, string> $candidates
     */
    private static function describe(string $packageFolder, array $candidates): string
    {
        $names = [];
        foreach ($candidates as $folder => $version) {
            $names[] = $folder === $version ? $folder : "{$folder} ({$version})";
        }
        foreach ([self::DEFAULT_FOLDER, 'dev'] as $folder) {
            if (!isset($candidates[$folder]) && self::hasPackage($packageFolder, $folder)) {
                $names[] = $folder . ' (no version)';
            }
        }

        return [] === $names ? 'none' : \implode(', ', $names);
    }

    private static function hasPackage(string $packageFolder, string $folder): bool
    {
        return \is_file(PathUtil::append($packageFolder, $folder, 'package.php'));
    }
}
