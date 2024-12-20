<?php
/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Razy;

use Exception;
use Phar;
use Throwable;

class ModuleInfo
{
    const REGEX_MODULE_CODE = '/^[a-z0-9]([_.-]?[a-z0-9]+)*(\/[a-z0-9](([_.]?|-{0,2})[a-z0-9]+)*)+$/i';

    private string $alias = '';
    private string $apiName = '';
    private array $assets = [];
    private string $description = '';
    private string $author;
    private string $code;
    private string $modulePath;
    private string $className = '';
    private array $prerequisite = [];
    private string $relativePath;
    private array $require = [];
    private bool $shadowAsset = false;
    private bool $pharArchive = false;

    /**
     * Module constructor.
     *
     * @param string $containerPath
     * @param array $moduleConfig
     * @param string $version
     * @param bool $sharedModule
     * @throws Error
     */
    public function __construct(private readonly string $containerPath, array $moduleConfig, private string $version = 'default', private readonly bool $sharedModule = false)
    {
        $this->relativePath = getRelativePath($this->containerPath, SYSTEM_ROOT);
        $this->version = trim($this->version);
        if (is_dir($this->containerPath)) {
            $this->modulePath = $this->containerPath;
            if ($this->version !== 'default' && $this->version !== 'dev') {
                if (!preg_match('/^(\d+)(?:\.(?:\d+|\*)){0,3}$/', $this->version)) {
                    throw new Error('Invalid version format, failed to load the module.');
                }
            }
            $this->modulePath = append($this->modulePath, $this->version);
            $this->relativePath = append($this->relativePath, $this->version);
            if (is_file(append($this->modulePath, 'app.phar'))) {
                $this->pharArchive = true;
                $this->modulePath = 'phar://' . append($this->modulePath, 'app.phar');
            }

            try {
                $settings = require(append($this->modulePath, 'package.php'));
                if (!is_array($settings)) {
                    throw new Error('Invalid module settings.');
                }
            } catch (Exception) {
                throw new Error('Unable to load the module.');
            }

            if (isset($moduleConfig['module_code'])) {
                if (!is_string($moduleConfig['module_code'])) {
                    throw new Error('The module code should be a string');
                }
                $code = trim($moduleConfig['module_code']);

                if (!preg_match(self::REGEX_MODULE_CODE, $code)) {
                    throw new Error('The module code ' . $code . ' is not a correct format, it should be `vendor/package`.');
                }

                $this->code = $code;
                $namespaces = explode('/', $code);
                $vendor = array_shift($namespaces);
                $className = array_pop($namespaces);

                $this->className = $className;
            } else {
                throw new Error('Missing module code.');
            }

            $this->description = trim($moduleConfig['description'] ?? '');

            $this->author = trim($moduleConfig['author'] ?? '');
            if (!$this->author) {
                throw new Error('Missing module author.');
            }

            $this->alias = trim($settings['alias'] ?? '');
            if (empty($this->alias)) {
                $this->alias = $this->className;
            }

            if (!is_array($settings['assets'] = $settings['assets'] ?? [])) {
                $settings['assets'] = [];
            }
            foreach ($settings['assets'] as $asset => $destPath) {
                $assetPath = fix_path(append($this->modulePath, $asset), DIRECTORY_SEPARATOR, true);
                if (false !== $assetPath) {
                    $this->assets[$destPath] = [
                        'path' => $asset,
                        'system_path' => realpath($assetPath),
                    ];
                }
            }

            if (!is_array($settings['prerequisite'] = $settings['prerequisite'] ?? [])) {
                $settings['prerequisite'] = [];
            }
            foreach ($settings['prerequisite'] as $package => $version) {
                if (is_string($package) && ($version)) {
                    $this->prerequisite[$package] = $version;
                }
            }

            $this->apiName = trim($settings['api_name'] ?? '');
            if (strlen($this->apiName) > 0) {
                if (!preg_match('/^[a-z]\w*$/i', $this->apiName)) {
                    throw new Error('Invalid API code format.');
                }
            }

            $settings['shadow_asset'] = $settings['shadow_asset'] ?? false;
            $this->shadowAsset = !!$settings['shadow_asset'] && !preg_match('/^phar:\/\/]/', $this->modulePath);

            if (isset($settings['require']) && is_array($settings['require'])) {
                foreach ($settings['require'] as $moduleCode => $version) {
                    $moduleCode = trim($moduleCode);
                    if (preg_match(self::REGEX_MODULE_CODE, $moduleCode) && is_string($version)) {
                        $this->require[$moduleCode] = trim($version);
                    }
                }

                // Add the parent class into require list
                if (count($namespaces)) {
                    $requireNamespace = $vendor;
                    foreach ($namespaces as $namespace) {
                        $requireNamespace .= '/' . $namespace;
                        if (!isset($this->require[$requireNamespace])) {
                            $this->require[$requireNamespace] = '*';
                        }
                    }
                }
            }
        } else {
            throw new Error('The folder does not exists.');
        }
    }

    /**
     * Get the module class name.
     *
     * @return string
     */
    public function getClassName(): string
    {
        return $this->className;
    }

    /**
     * Get the module file path.
     *
     * @param bool $relative
     *
     * @return string
     */
    public function getPath(bool $relative = false): string
    {
        return ($relative) ? $this->relativePath : $this->modulePath;
    }

    /**
     * Get the API name.
     *
     * @return string
     */
    public function getAPIName(): string
    {
        return $this->apiName;
    }

    /**
     * Get the prerequisite list.
     *
     * @return array
     */
    public function getPrerequisite(): array
    {
        return $this->prerequisite;
    }

    /**
     * Get the module alias.
     *
     * @return string
     */
    public function getAlias(): string
    {
        return $this->alias;
    }

    /**
     * Return the module description
     *
     * @return string
     */
    public function getDescription(): string
    {
        return $this->description;
    }

    /**
     * Return the module author.
     *
     * @return string
     */
    public function getAuthor(): string
    {
        return $this->author;
    }

    /**
     * Get the module code.
     *
     * @return string
     */
    public function getCode(): string
    {
        return $this->code;
    }

    /**
     * Get the module container path
     *
     * @param bool $isRelative
     * @return string
     */
    public function getContainerPath(bool $isRelative = false): string
    {
        return ($isRelative) ? getRelativePath($this->containerPath, SYSTEM_ROOT) : $this->containerPath;
    }

    /**
     * Get the `require` list of the module.
     *
     * @return array
     */
    public function getRequire(): array
    {
        return $this->require;
    }

    /**
     * Check if the module is shadow asset mode.
     *
     * @return bool
     */
    public function isShadowAsset(): bool
    {
        return $this->shadowAsset;
    }

    /**
     * Return the module version.
     *
     * @return string
     */
    public function getVersion(): string
    {
        return $this->version;
    }

    /**
     * Return true if the module is a shared module.
     *
     * @return bool
     */
    public function isShared(): bool
    {
        return $this->sharedModule;
    }

    /**
     * Return turn if the module is a phar archive
     *
     * @return bool
     */
    public function isPharArchive(): bool
    {
        return $this->pharArchive;
    }

    /**
     * Return the asset list
     *
     * @return array
     */
    public function getAssets(): array
    {
        return $this->assets;
    }
}
