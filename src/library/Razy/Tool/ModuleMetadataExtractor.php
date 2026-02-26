<?php
/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * Defines the ModuleMetadataExtractor class for extracting module metadata
 * from the file system without requiring full framework bootstrap. Used
 * primarily for generating skills documentation.
 *
 * @package Razy
 * @license MIT
 */

namespace Razy\Tool;

use Exception;

/**
 * Extracts module metadata from the file system without full framework bootstrap.
 *
 * Parses `package.json` for module configuration, scans `Controller.php` for
 * API command registrations and lifecycle event handlers using regex-based
 * static analysis. This avoids the overhead of initializing the entire Razy
 * application stack just to read module metadata.
 *
 * @class ModuleMetadataExtractor
 */
class ModuleMetadataExtractor
{
    /** @var string Absolute path to the module directory */
    private string $modulePath;

    /** @var array Parsed contents of the module's package.json */
    private array $packageData = [];

    /** @var array<int, array{command: string, path: string, internal_binding: bool}> Discovered API commands */
    private array $apiCommands = [];

    /** @var array<int, string> Discovered lifecycle event handler method names */
    private array $events = [];

    /** @var array<string, string> Module dependencies from package.json require section */
    private array $dependencies = [];

    /**
     * ModuleMetadataExtractor constructor.
     *
     * @param string $modulePath Absolute path to the module directory
     * @throws Exception If the module path does not exist
     */
    public function __construct(string $modulePath)
    {
        if (!is_dir($modulePath)) {
            throw new Exception("Module path not found: $modulePath");
        }
        $this->modulePath = rtrim($modulePath, '/\\');
    }

    /**
     * Extract all metadata from the module directory.
     *
     * Orchestrates extraction of package data, API commands, and events.
     *
     * @return array{package: array, api_commands: array, events: array, dependencies: array}
     */
    public function extract(): array
    {
        $this->extractPackageData();
        $this->extractAPICommands();
        $this->extractEvents();
        
        return [
            'package' => $this->packageData,
            'api_commands' => $this->apiCommands,
            'events' => $this->events,
            'dependencies' => $this->dependencies,
        ];
    }

    /**
     * Parse package.json for module metadata and dependencies.
     *
     * @throws Exception If package.json is not found in the module path
     */
    private function extractPackageData(): void
    {
        $packageFile = $this->modulePath . '/package.json';
        if (!is_file($packageFile)) {
            throw new Exception("package.json not found in {$this->modulePath}");
        }

        $content = file_get_contents($packageFile);
        $this->packageData = json_decode($content, true) ?? [];

        // Extract module dependencies from the 'require' section
        if (isset($this->packageData['require']) && is_array($this->packageData['require'])) {
            $this->dependencies = $this->packageData['require'];
        }
    }

    /**
     * Parse Controller.php for addAPICommand() calls using regex.
     *
     * Scans for both public and internal (#-prefixed) API command registrations
     * without requiring PHP class loading or initialization.
     */
    private function extractAPICommands(): void
    {
        $controllerFile = $this->modulePath . '/src/Controller.php';
        if (!is_file($controllerFile)) {
            return;
        }

        $content = file_get_contents($controllerFile);

        // Pattern: $agent->addAPICommand('command', 'path/to/file') - public API
        $pattern1 = '/\$agent\s*->\s*addAPICommand\s*\(\s*[\'"]([^\'"]+)[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]\s*\)/';
        
        // Pattern: $agent->addAPICommand('#internal', 'path/to/file') - internal binding
        $pattern2 = '/\$agent\s*->\s*addAPICommand\s*\(\s*[\'"]#([^\'"]+)[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]\s*\)/';

        $matches1 = [];
        $matches2 = [];
        
        preg_match_all($pattern1, $content, $matches1);
        preg_match_all($pattern2, $content, $matches2);

        // Extract from Pattern 1 (public)
        if (!empty($matches1[1])) {
            foreach ($matches1[1] as $i => $command) {
                // Skip if it's an internal binding (starts with #)
                if (str_starts_with($command, '#')) continue;
                
                $this->apiCommands[] = [
                    'command' => $command,
                    'path' => $matches1[2][$i],
                    'internal_binding' => false,
                ];
            }
        }

        // Extract from Pattern 2 (internal)
        if (!empty($matches2[1])) {
            foreach ($matches2[1] as $i => $command) {
                $this->apiCommands[] = [
                    'command' => $command,
                    'path' => $matches2[2][$i],
                    'internal_binding' => true,
                ];
            }
        }
    }

    /**
     * Parse Controller.php for lifecycle event handlers (public __on* methods).
     *
     * Discovers all public methods matching the `__on*` naming convention
     * which are used as lifecycle event callbacks in the Razy module system.
     */
    private function extractEvents(): void
    {
        $controllerFile = $this->modulePath . '/src/Controller.php';
        if (!is_file($controllerFile)) {
            return;
        }

        $content = file_get_contents($controllerFile);

        // Pattern: public function __onEventName(...)
        $pattern = '/public\s+function\s+(__on\w+)\s*\(/';
        $matches = [];
        preg_match_all($pattern, $content, $matches);

        if (!empty($matches[1])) {
            // Filter to only custom implementations (not default no-ops)
            $this->events = array_unique(array_filter($matches[1], function($event) use ($content) {
                // Simple heuristic: if event body has more than just return/closing brace,
                // it's likely implemented
                return true; // For now, include all discovered events
            }));
        }
    }

    /**
     * Pretty-print extracted metadata as JSON (for debugging).
     *
     * @return string JSON-encoded metadata with pretty formatting
     */
    public function pretty(): string
    {
        return json_encode($this->extract(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
