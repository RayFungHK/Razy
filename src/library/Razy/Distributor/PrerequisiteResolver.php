<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * @package Razy
 *
 * @license MIT
 */

namespace Razy\Distributor;

use Closure;
use Exception;
use Razy\PackageManager;
use Razy\Util\PathUtil;
use Razy\Util\VersionUtil;

/**
 * Class PrerequisiteResolver.
 *
 * Manages package prerequisites registered by modules during initialization.
 * Handles version constraint tracking, conflict detection, and package composition.
 *
 * Extracted from the Distributor god class to follow Single Responsibility Principle.
 *
 * @class PrerequisiteResolver
 *
 * @package Razy\Distributor
 */
class PrerequisiteResolver
{
    /** @var array<string, string> Package prerequisites with combined version constraints */
    private array $prerequisites = [];

    /** @var array<string, array> Tracks which modules registered which prerequisite constraints */
    private array $prerequisiteModules = [];

    /** @var array<array> Detected version conflicts between module prerequisites */
    private array $prerequisiteConflicts = [];

    /** @var array|null Cached installed packages from lock.json */
    private ?array $installedPackages = null;

    /**
     * PrerequisiteResolver constructor.
     *
     * @param string $distCode The distribution code for looking up lock.json entries
     * @param object $distributor The parent Distributor instance (used by PackageManager)
     */
    public function __construct(
        private readonly string $distCode,
        private readonly object $distributor,
    ) {
    }

    /**
     * Load installed packages from lock.json.
     *
     * @return array
     */
    public function loadInstalledPackages(): array
    {
        if (null === $this->installedPackages) {
            $this->installedPackages = [];
            $lockFile = PathUtil::append(SYSTEM_ROOT, 'autoload', 'lock.json');
            if (\is_file($lockFile)) {
                try {
                    $content = \file_get_contents($lockFile);
                    $data = \json_decode($content, true);
                    if (\is_array($data) && isset($data[$this->distCode])) {
                        $this->installedPackages = $data[$this->distCode];
                    }
                } catch (Exception) {
                    $this->installedPackages = [];
                }
            }
        }
        return $this->installedPackages;
    }

    /**
     * Check if an installed package version satisfies a constraint.
     *
     * @param string $package Package name
     * @param string $constraint Version constraint
     *
     * @return bool|null True if satisfied, false if not, null if not installed
     */
    public function checkInstalledVersion(string $package, string $constraint): ?bool
    {
        $installed = $this->loadInstalledPackages();
        if (!isset($installed[$package])) {
            return null; // Package not installed
        }

        $installedVersion = $installed[$package]['version'] ?? '0.0.0.0';

        // Handle stability flag
        $versionConstraint = $constraint;
        if (\preg_match('/^(.+)@(dev|alpha|beta|RC|stable)$/i', $constraint, $matches)) {
            $versionConstraint = $matches[1];
        }

        // Use vc() function to check constraint
        if ('*' === $versionConstraint) {
            return true;
        }

        return VersionUtil::vc($versionConstraint, $installedVersion);
    }

    /**
     * Get prerequisite conflicts detected during module loading.
     *
     * @return array Array of conflicts: ['package' => ..., 'modules' => [...], 'constraints' => [...]]
     */
    public function getPrerequisiteConflicts(): array
    {
        return $this->prerequisiteConflicts;
    }

    /**
     * Register a prerequisite package requirement from a module.
     *
     * @param string $package Package name
     * @param string $version Version constraint
     * @param string $moduleCode Module code registering this requirement (optional)
     *
     * @return $this
     */
    public function prerequisite(string $package, string $version, string $moduleCode = ''): static
    {
        $package = \trim($package);
        $version = \trim($version);

        // Track which module registered this constraint
        if (!isset($this->prerequisiteModules[$package])) {
            $this->prerequisiteModules[$package] = [];
        }
        $this->prerequisiteModules[$package][] = [
            'module' => $moduleCode,
            'version' => $version,
        ];

        // Check if installed version satisfies this constraint
        $installed = $this->loadInstalledPackages();
        if (isset($installed[$package])) {
            $satisfies = $this->checkInstalledVersion($package, $version);
            if (false === $satisfies) {
                // Installed version doesn't satisfy this module's constraint
                $this->prerequisiteConflicts[] = [
                    'type' => 'installed_mismatch',
                    'package' => $package,
                    'module' => $moduleCode,
                    'required' => $version,
                    'installed' => $installed[$package]['version'] ?? 'unknown',
                ];
            }
        }

        // Combine version constraints for compose
        if (isset($this->prerequisites[$package])) {
            if ('*' !== $version) {
                $this->prerequisites[$package] .= ',' . $version;
            }
        } else {
            $this->prerequisites[$package] = $version;
        }

        return $this;
    }

    /**
     * Check if there are any prerequisite version conflicts.
     *
     * @return bool True if conflicts exist
     */
    public function hasPrerequisiteConflicts(): bool
    {
        return !empty($this->prerequisiteConflicts);
    }

    /**
     * Compose all modules — validate and fetch all prerequisite packages.
     *
     * @param Closure $closure Callback for reporting progress/conflicts
     *
     * @return bool True if all packages validated successfully
     *
     * @throws Error
     */
    public function compose(Closure $closure): bool
    {
        // Report any detected version conflicts before composing
        if (!empty($this->prerequisiteConflicts)) {
            foreach ($this->prerequisiteConflicts as $conflict) {
                $closure('version_conflict', $conflict['package'], $conflict['module'], $conflict['required'], $conflict['installed']);
            }
        }

        $validated = true;
        foreach ($this->prerequisites as $package => $versionRequired) {
            $packageManager = new PackageManager($this->distributor, $package, $versionRequired, $closure);
            if (!$packageManager->fetch() || !$packageManager->validate()) {
                $validated = false;
            }
        }
        PackageManager::updateLock();

        return $validated;
    }
}
