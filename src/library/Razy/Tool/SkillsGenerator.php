<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * Defines the SkillsGenerator class for generating skills documentation.
 * Produces comprehensive context documents for LLM agents at root, distribution,
 * and module levels using the Razy Template Engine.
 *
 * @package Razy
 *
 * @license MIT
 */

namespace Razy\Tool;

use Exception;
use Razy\Application;
use Razy\Distributor;
use Razy\Module;
use Razy\Template;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use Throwable;

/**
 * Skills Documentation Generator.
 *
 * Generates comprehensive context documents (skills) for LLM agents working with
 * Razy projects at three levels:
 * 1. Root framework level (skills.md) - Framework overview and foundation
 * 2. Distribution level (skills/{dist_code}.md) - Distribution-specific configuration
 * 3. Module level (skills/{dist_code}/{module}.md) - Module API and implementation
 *
 * Uses Razy's Template Engine with blocks and entity assignment for flexible,
 * maintainable template rendering.
 *
 * Path resolution uses PHAR_PATH (framework templates) and SYSTEM_ROOT (user data).
 * No constructor parameters needed - uses global constants SYSTEM_ROOT and PHAR_PATH.
 */
class SkillsGenerator
{
    /** @var string Path to the skills output directory under SYSTEM_ROOT */
    private string $skillsDir;

    /** @var array<string, array> Discovered distributions indexed by distribution code */
    private array $distributions = [];

    /** @var array<string, array> Discovered modules indexed by "dist::module::version" key */
    private array $modules = [];

    /**
     * Initialize generator using framework constants.
     *
     * Uses SYSTEM_ROOT (application root) and PHAR_PATH (framework location).
     * All paths are resolved relative to these constants.
     */
    public function __construct()
    {
        if (!\defined('SYSTEM_ROOT') || !\defined('PHAR_PATH')) {
            throw new Exception('SYSTEM_ROOT and PHAR_PATH constants must be defined');
        }

        $this->sitesPath = SYSTEM_ROOT . DIRECTORY_SEPARATOR . 'sites';
        $this->skillsDir = SYSTEM_ROOT . DIRECTORY_SEPARATOR . 'skills';

        // Ensure skills directory exists
        @\mkdir($this->skillsDir, 0o755, true);
    }

    /**
     * Generate all skills documentation.
     *
     * This is the main entry point that orchestrates document generation at all levels:
     * - Root framework documentation
     * - Per-distribution documentation
     * - Per-module documentation
     *
     * @return array Generation results with status for each level
     */
    public function generate(): array
    {
        $results = [
            'root' => false,
            'distributions' => [],
            'modules' => [],
        ];

        try {
            // 1. Generate root skills.md
            $this->generateRootSkills();
            $results['root'] = true;

            // 2. Scan and generate per-distribution documentation
            $this->scanDistributions();

            foreach ($this->distributions as $distCode => $distData) {
                try {
                    $this->generateDistributionSkills($distCode, $distData);
                    $results['distributions'][$distCode] = 'generated';
                } catch (Throwable $e) {
                    $results['distributions'][$distCode] = 'error: ' . $e->getMessage();
                }
            }

            // 3. Generate per-module documentation
            foreach ($this->modules as $moduleKey => $moduleData) {
                try {
                    $this->generateModuleSkills($moduleData);
                    $results['modules'][$moduleKey] = 'generated';
                } catch (Throwable $e) {
                    $results['modules'][$moduleKey] = 'error: ' . $e->getMessage();
                }
            }
        } catch (Throwable $e) {
            $results['error'] = $e->getMessage();
        }

        return $results;
    }

    /**
     * Scan distributions and their modules using framework's Application/Distributor classes.
     *
     * Discovers all registered distributions from the application configuration
     * and catalogs their modules for documentation generation.
     * Uses the same pattern as system/terminal/inspect.inc.php
     *
     * @throws Exception If application initialization fails
     */
    private function scanDistributions(): void
    {
        try {
            // Load application configuration
            $app = new Application();
            $config = $app->loadSiteConfig();

            // Parse domains config to find all unique distribution codes and tags
            $distIdentifiers = [];

            if (\is_array($config['domains'] ?? null)) {
                foreach ($config['domains'] as $domain => $distPaths) {
                    foreach ($distPaths as $path => $distIdentifier) {
                        if (\is_string($distIdentifier)) {
                            // Parse "code@tag" format (e.g., "mysite@*" where * is default tag)
                            [$code, $tag] = \explode('@', $distIdentifier . '@', 2);
                            $distIdentifiers[$code][$tag] = $domain;
                        }
                    }
                }
            }

            // Load each distributor and catalog its modules for documentation
            foreach ($distIdentifiers as $code => $tags) {
                try {
                    // Use first available tag (usually '*' for default)
                    $tag = \key($tags) ?: '*';

                    $distributor = new Distributor($code, $tag);
                    $distributor->initialize(true);

                    $this->distributions[$code] = [
                        'code' => $code,
                        'tag' => $tag,
                        'domains' => \array_values($tags),
                    ];

                    // Scan modules in this distributor
                    $modules = $distributor->getRegistry()->getModules();
                    foreach ($modules as $moduleCode => $module) {
                        $moduleInfo = $module->getModuleInfo();
                        $version = $moduleInfo->getVersion();
                        $moduleKey = "$code::$moduleCode::$version";

                        $this->modules[$moduleKey] = [
                            'dist_code' => $code,
                            'module_code' => $moduleCode,
                            'version' => $version,
                            'module' => $module,
                            'module_info' => $moduleInfo,
                        ];
                    }
                } catch (Throwable $e) {
                    // Skip failed distributions
                }
            }
        } catch (Throwable $e) {
            throw new Exception('Failed to scan distributions: ' . $e->getMessage());
        }
    }

    /**
     * Generate root skills.md for the entire Razy framework.
     *
     * Uses the root template (skills.md) from src/asset/prompt/.
     * The root entity receives framework-level parameters and renders the template.
     */
    private function generateRootSkills(): void
    {
        $version = 'unknown';
        $versionFile = SYSTEM_ROOT . DIRECTORY_SEPARATOR . 'VERSION';
        if (\is_file($versionFile)) {
            $version = \trim((string) \file_get_contents($versionFile));
        }

        // Load template and get root entity
        $source = Template::loadFile(PHAR_PATH . '/asset/prompt/skills.md.tpl');
        $root = $source->getRoot();

        if (!$root) {
            throw new Exception('Failed to load template: skills.md.tpl');
        }

        // Assign parameters to root entity
        $root->assign([
            'version' => $version,
            'date' => \date('F j, Y'),
        ]);

        // Render the populated template
        $content = $source->output();

        $filePath = SYSTEM_ROOT . DIRECTORY_SEPARATOR . 'skills.md';
        \file_put_contents($filePath, $content);
    }

    /**
     * Generate distribution-level skills documentation.
     *
     * Creates a context document for a specific distribution including:
     * - Distribution configuration and domains
     * - Enabled modules with links to their documentation
     * - Runtime settings
     *
     * @param string $distCode Distribution code
     * @param array $distData Distribution metadata
     */
    private function generateDistributionSkills(string $distCode, array $distData): void
    {
        // Get modules for this distribution
        $distModules = \array_filter($this->modules, fn ($m) => $m['dist_code'] === $distCode);

        // Get domain info from distData
        $domains = $distData['domains'] ?? [];
        $domainList = \implode(', ', $domains);
        if (!$domainList) {
            $domainList = '(not configured)';
        }

        // Build modules array for @each iteration in template
        $modulesList = [];
        foreach ($distModules as $moduleKey => $moduleData) {
            $modulesList[] = [
                'code' => $moduleData['module_code'],
                'version' => $moduleData['version'],
                'description' => $moduleData['module_info']->getDescription() ?? 'No description',
                'file' => "{$distCode}/{$moduleData['module_code']}-{$moduleData['version']}.md",
            ];
        }

        $generatedAt = \date('Y-m-d H:i:s');

        // Load template and get root entity
        $source = Template::loadFile(PHAR_PATH . '/asset/prompt/skills-distribution.md.tpl');
        $root = $source->getRoot();

        if (!$root) {
            throw new Exception('Failed to load template: skills-distribution.md.tpl');
        }

        // Assign parameters to root entity
        $root->assign([
            'dist_code' => $distCode,
            'domain_list' => $domainList,
            'modules_list' => $modulesList,
            'has_modules' => \count($modulesList) > 0,
            'generated_at' => $generatedAt,
            'updated_at' => $generatedAt,
        ]);

        // Render the populated template
        $content = $source->output();

        $dirPath = $this->skillsDir;
        @\mkdir($dirPath, 0o755, true);

        $filePath = $dirPath . '/' . $distCode . '.md';
        \file_put_contents($filePath, $content);
    }

    /**
     * Generate module-level skills documentation.
     *
     * Creates comprehensive context for a specific module including:
     * - Module metadata and description
     * - API commands
     * - Event listeners
     * - File structure
     * - Implementation notes (extracted from code comments)
     * - Dependencies
     *
     * Uses Template Engine block system for populating sections via method chaining.
     *
     * @param array $moduleData Module metadata with module and module_info objects
     */
    private function generateModuleSkills(array $moduleData): void
    {
        $distCode = $moduleData['dist_code'];
        $moduleCode = $moduleData['module_code'];
        $version = $moduleData['version'];
        $module = $moduleData['module'];
        $moduleInfo = $moduleData['module_info'];

        // Get module path from module instance
        $modulePath = $module->getPath();

        $description = $moduleInfo->getDescription() ?? 'No description';
        $author = $moduleInfo->getAuthor() ?? 'Unknown';
        $dependencies = $moduleInfo->getRequire() ?? [];

        $generatedAt = \date('Y-m-d H:i:s');

        // Load template and get root entity
        $source = Template::loadFile(PHAR_PATH . '/asset/prompt/skills-module.md.tpl');
        $root = $source->getRoot();

        if (!$root) {
            throw new Exception('Failed to load template: skills-module.md.tpl');
        }

        // Assign root-level parameters
        $root->assign([
            'module_code' => $moduleCode,
            'version' => $version,
            'dist_code' => $distCode,
            'author' => $author,
            'description' => $description,
            'generated_at' => $generatedAt,
            'updated_at' => $generatedAt,
        ]);

        // Populate block sections using method chaining pattern
        // Each method populates template blocks and returns $this for chaining
        $this->buildAPISection($root, $module)
             ->buildEventsSection($root, $module)
             ->buildFileStructureSection($root, $modulePath)
             ->buildDependenciesSection($root, $dependencies)
             ->buildPromptsSection($root, $modulePath);

        // Render the populated template
        $content = $source->output();

        $distDir = $this->skillsDir . '/' . $distCode;
        @\mkdir($distDir, 0o755, true);

        $filename = $moduleCode . '-' . $version . '.md';
        $filePath = $distDir . '/' . $filename;
        \file_put_contents($filePath, $content);
    }

    /**
     * Build API commands blocks.
     *
     * Populates 'api_commands_section' block with API command data.
     * Template structure: <!-- START BLOCK: api_commands_section --> ... <!-- END BLOCK: api_commands_section -->
     *
     * @param Entity $root Root entity from template
     * @param Module $module Module instance
     *
     * @return self
     */
    private function buildAPISection(Entity $root, Module $module): self
    {
        // Get API commands from module if available
        $apiCommands = [];

        try {
            // Module may have getAPI() or similar method to retrieve registered commands
            if (\method_exists($module, 'getAPI')) {
                $api = $module->getAPI();
                if (\is_array($api)) {
                    foreach ($api as $command => $config) {
                        $apiCommands[] = [
                            'command' => $command,
                            'description' => $config['description'] ?? 'No description',
                            'path' => $config['path'] ?? 'unknown',
                        ];
                    }
                }
            }
        } catch (Throwable $e) {
            // Skip if API extraction fails
        }

        if (\count($apiCommands) > 0) {
            $sectionBlock = $root->newBlock('api_commands_section');
            foreach ($apiCommands as $api) {
                $sectionBlock->newBlock('api_command')->assign([
                    'command' => $api['command'] ?? 'unknown',
                    'description' => $api['description'] ?? 'No description',
                    'path' => $api['path'] ?? 'unknown',
                ]);
            }
        }
        return $this;
    }

    /**
     * Build events blocks.
     *
     * Populates 'events_section' block with event listener data.
     * Template structure: <!-- START BLOCK: events_section --> ... <!-- END BLOCK: events_section -->
     *
     * @param Entity $root Root entity from template
     * @param Module $module Module instance
     *
     * @return self
     */
    private function buildEventsSection(Entity $root, Module $module): self
    {
        $events = [];

        try {
            // Module may have getListeners() or similar method
            if (\method_exists($module, 'getListeners')) {
                $listeners = $module->getListeners();
                if (\is_array($listeners)) {
                    $events = \array_keys($listeners);
                }
            }
        } catch (Throwable $e) {
            // Skip if events extraction fails
        }

        if (\count($events) > 0) {
            $sectionBlock = $root->newBlock('events_section');
            foreach ($events as $event) {
                $sectionBlock->newBlock('event')->assign([
                    'event_name' => $event,
                ]);
            }
        }
        return $this;
    }

    /**
     * Build file structure blocks.
     *
     * Populates 'files_section' block with directory structure.
     * Template structure: <!-- START BLOCK: files_section --> ... <!-- END BLOCK: files_section -->
     *
     * @param Entity $root Root entity from template
     * @param string $modulePath Module path
     *
     * @return self
     */
    private function buildFileStructureSection(Entity $root, string $modulePath): self
    {
        $dirs = [
            'src' => 'Source code and classes',
            'controller' => 'API command handlers',
            'view' => 'Template files (.tpl)',
            'plugin' => 'Module-specific plugins',
            'data' => 'Persistent data storage',
        ];

        $foundDirs = [];
        foreach ($dirs as $dir => $description) {
            $dirPath = $modulePath . '/' . $dir;
            if (\is_dir($dirPath)) {
                $foundDirs[] = [
                    'name' => $dir,
                    'description' => $description,
                ];
            }
        }

        if (\count($foundDirs) > 0) {
            $sectionBlock = $root->newBlock('files_section');
            foreach ($foundDirs as $dirInfo) {
                $sectionBlock->newBlock('directory')->assign($dirInfo);
            }
        }
        return $this;
    }

    /**
     * Build dependencies blocks.
     *
     * Populates 'dependencies_section' block with module dependencies.
     * Template structure: <!-- START BLOCK: dependencies_section --> ... <!-- END BLOCK: dependencies_section -->
     *
     * @param Entity $root Root entity from template
     * @param array $dependencies Module dependencies
     *
     * @return self
     */
    private function buildDependenciesSection(Entity $root, array $dependencies): self
    {
        if (\count($dependencies) > 0) {
            $sectionBlock = $root->newBlock('dependencies_section');
            foreach ($dependencies as $module => $version) {
                $sectionBlock->newBlock('dependency')->assign([
                    'module' => $module,
                    'version' => $version,
                ]);
            }
        }
        return $this;
    }

    /**
     * Build prompts blocks.
     *
     * Populates 'prompts_section' block with LLM prompt directives from code.
     * Template structure: <!-- START BLOCK: prompts_section --> ... <!-- END BLOCK: prompts_section -->
     *
     * @param Entity $root Root entity from template
     * @param string $modulePath Module path
     *
     * @return self
     */
    private function buildPromptsSection(Entity $root, string $modulePath): self
    {
        $prompts = [];
        $this->scanPHPPrompts($modulePath, $prompts);
        $this->scanTPLPrompts($modulePath, $prompts);

        if (\count($prompts) > 0) {
            $sectionBlock = $root->newBlock('prompts_section');
            foreach ($prompts as $file => $items) {
                $fileBlock = $sectionBlock->newBlock('prompt_file')->assign(['file' => $file]);
                foreach ($items as $item) {
                    $fileBlock->newBlock('prompt')->assign($item);
                }
            }
        }
        return $this;
    }

    /**
     * Extract LLM prompt directives from module source code.
     *
     * Scans PHP and TPL files for special directives:
     * - PHP: @llm prompt: or // @llm prompt:
     * - TPL: {#llm prompt}...{/}
     *
     * Used by buildPromptsSection() to populate prompt blocks.

    /**
     * Scan PHP files for @llm directives using Reflection.
     *
     * Uses PHP Reflection API to parse valid docblocks only, preventing false positives
     * from regular comments. Extracts @llm prompt directives from class/method docblocks.
     *
     * @param string $modulePath Module path
     * @param array $prompts Prompts array reference
     */
    private function scanPHPPrompts(string $modulePath, array &$prompts): void
    {
        $srcPath = $modulePath . '/src';
        if (!\is_dir($srcPath)) {
            return;
        }

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($srcPath, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            try {
                // Load and reflect on the file content
                $content = \file_get_contents($file->getPathname());

                // Extract class/function definitions using regex
                if (\preg_match_all('/(?:class|function)\s+(\w+)/', $content, $matches)) {
                    foreach ($matches[1] as $name) {
                        try {
                            // Try to use Reflection on the loaded class/function
                            if (\class_exists($name, false)) {
                                $reflection = new ReflectionClass($name);

                                // Get class docblock
                                $docBlock = $reflection->getDocComment();
                                $this->parseDocBlockPrompts($docBlock, $file->getPathname(), $modulePath, $prompts);

                                // Get methods docblocks
                                foreach ($reflection->getMethods() as $method) {
                                    $docBlock = $method->getDocComment();
                                    $this->parseDocBlockPrompts($docBlock, $file->getPathname(), $modulePath, $prompts, $method->getStartLine());
                                }
                            }
                        } catch (Throwable $e) {
                            // Skip if reflection fails for this class
                        }
                    }
                }

                // Also check for standalone @llm prompt comments in docblocks using regex
                // This catches function docblocks
                if (\preg_match_all('/\/\*\*.*?@llm\s+prompt:\s*(.+?).*?\*\//s', $content, $matches)) {
                    foreach ($matches[1] as $prompt) {
                        $relPath = \str_replace($modulePath . '/', '', $file->getPathname());
                        if (!isset($prompts[$relPath])) {
                            $prompts[$relPath] = [];
                        }

                        $prompts[$relPath][] = [
                            'line' => 0, // Line number uncertain with regex approach
                            'prompt' => \trim(\preg_replace('/\s+/', ' ', $prompt)),
                        ];
                    }
                }
            } catch (Throwable $e) {
                // Skip files that fail to parse
            }
        }
    }

    /**
     * Parse docblock for @llm prompt directives.
     *
     * @param string|false $docBlock Docblock from getDocComment()
     * @param string $filePath File path
     * @param string $modulePath Module base path
     * @param array $prompts Prompts array reference
     * @param int $lineNum Line number (optional)
     */
    private function parseDocBlockPrompts($docBlock, string $filePath, string $modulePath, array &$prompts, int $lineNum = 0): void
    {
        if (!\is_string($docBlock)) {
            return;
        }

        // Extract @llm prompt: directive from docblock
        if (\preg_match('/@llm\s+prompt:\s*(.+?)(?:\n|\*|$)/', $docBlock, $matches)) {
            $relPath = \str_replace($modulePath . '/', '', $filePath);
            if (!isset($prompts[$relPath])) {
                $prompts[$relPath] = [];
            }

            $prompts[$relPath][] = [
                'line' => $lineNum ?: 0,
                'prompt' => \trim(\preg_replace('/\s+/', ' ', $matches[1])),
            ];
        }
    }

    /**
     * Scan TPL files for {#llm} directives using Template Engine.
     *
     * Uses Template::readComment() to properly parse template comments and extract
     * LLM prompt directives.
     *
     * @param string $modulePath Module path
     * @param array $prompts Prompts array reference
     */
    private function scanTPLPrompts(string $modulePath, array &$prompts): void
    {
        $viewPath = $modulePath . '/view';
        if (!\is_dir($viewPath)) {
            return;
        }

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($viewPath, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($files as $file) {
            if ($file->getExtension() !== 'tpl') {
                continue;
            }

            try {
                // Load template using framework's Template engine
                $filePath = $file->getPathname();
                $source = Template::loadFile($filePath);

                // Read comments from template
                $comments = Template::readComment($filePath);
                if (empty($comments) || !\is_array($comments)) {
                    continue;
                }

                $relPath = \str_replace($modulePath . '/', '', $filePath);

                // Filter and extract LLM prompt comments
                foreach ($comments as $commentData) {
                    if (!\is_array($commentData)) {
                        continue;
                    }

                    $content = $commentData['content'] ?? '';
                    $line = $commentData['line'] ?? 0;

                    // Look for @llm prompt: tag within the comment
                    if (\preg_match('/@llm\s+prompt:\s*(.+?)(?:\n|$)/', $content, $matches)) {
                        if (!isset($prompts[$relPath])) {
                            $prompts[$relPath] = [];
                        }

                        $prompts[$relPath][] = [
                            'line' => $line,
                            'prompt' => \trim(\preg_replace('/\s+/', ' ', $matches[1])),
                        ];
                    }
                }
            } catch (Throwable $e) {
                // Skip files that fail to parse
            }
        }
    }
}
