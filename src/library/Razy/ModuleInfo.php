<?php

/**
 * This file is part of Razy v0.4.
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
    private string $alias = '';
    /**
     * The API command alias
     *
     * @var string
     */
    private string $apiAlias = '';

    /**
     * @var array
     */
    private array $assets = [];

    /**
     * The module author
     *
     * @var string
     */
    private string $author;
    /**
     * The module code
     *
     * @var string
     */
    private string $code;
    /**
     * The module package folder system path
     *
     * @var string
     */
    private string $modulePath;

    /**
     * The module container folder system path
     *
     * @var string
     */
    private string $containerPath;

    /**
     * The package name
     *
     * @var string
     */
    private string $packageName = '';

    /**
     * @var array
     */
    private array $prerequisite = [];

    /**
     * @var string
     */
    private string $relativePath = '';

    /**
     * The storage of the required modules
     *
     * @var string[]
     */
    private array $require = [];
    /**
     * Is the module a shared module?
     *
     * @var bool
     */
    private bool $sharedModule = false;

    /**
     * The module version
     *
     * @var string
     */
    private string $version;

    /**
     * Is the module's asset link to modules direct via rewrite, false will copy all assets into shared view folder
     *
     * @var bool
     */
    private bool $shadowAsset = false;

    /**
     * @var bool
     */
    private bool $isPhar = false;

    /**
     * Module constructor.
     *
     * @param string $path The path of module located
     *
     * @throws Throwable
     */
    public function __construct(string $path, string $version = 'default', bool $isShared = false)
    {
        $this->containerPath = $path;
        $this->relativePath = getRelativePath($path, SYSTEM_ROOT);
        $version = trim($version);
        if (is_dir($path)) {
            $this->modulePath = $path;
            if ($version !== 'default' && $version !== 'dev') {
                if (!preg_match('/^(\d+)(?:\.(?:\d+|\*)){0,3}$/', $version)) {
                    throw new Error('Invalid version format, failed to load the module.');
                }
            }
            $this->modulePath = append($this->modulePath, $version);
            $this->relativePath = append($this->relativePath, $version);
            if (is_file(append($this->modulePath, 'app.phar'))) {
                $this->isPhar = true;
                $this->modulePath = 'phar://' . append($this->modulePath, 'app.phar');
            }

            try {
                $settings = require(append($this->modulePath, 'package.php'));
                if (!is_array($settings)) {
                    throw new Error('Invalid module settings.');
                }
            } catch (Exception $e) {
                throw new Error('Unable to load the module.');
            }

            if (isset($settings['module_code'])) {
                if (!is_string($settings['module_code'])) {
                    throw new Error('The module code should be a string');
                }
                $code = trim($settings['module_code']);

                if (!preg_match('/^[a-z0-9]([_.-]?[a-z0-9]+)*\/[a-z0-9](([_.]?|-{0,2})[a-z0-9]+)*$/i', $code)) {
                    throw new Error('The module code ' . $code . ' is not a correct format, it should be `vendor/package`.');
                }

                $this->code = $code;
                [, $package] = explode('/', $code);
                $this->packageName = $package;
            } else {
                throw new Error('Missing module code.');
            }

            $this->version = $version;

            $this->author = trim($settings['author'] ?? '');
            if (!$this->author) {
                throw new Error('Missing module author.');
            }

            $this->alias = trim($settings['alias'] ?? '');
            if (empty($this->alias)) {
                $this->alias = $this->packageName;
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

            $this->apiAlias = trim($settings['api'] ?? '');
            if (strlen($this->apiAlias) > 0) {
                if (!preg_match('/^[a-z]\w*$/i', $this->apiAlias)) {
                    throw new Error('Invalid API code format.');
                }
            }

            $this->shadowAsset = !!$settings['shadow_asset'] && !preg_match('/^phar:\/\/]/', $this->modulePath);

            if (isset($settings['require']) && is_array($settings['require'])) {
                foreach ($settings['require'] as $moduleCode => $version) {
                    $moduleCode = trim($moduleCode);
                    if (preg_match('/^[a-z0-9]([_.-]?[a-z0-9]+)*\/[a-z0-9](([_.]?|-{0,2})[a-z0-9]+)*$/i', $moduleCode) && is_string($version)) {
                        $this->require[$moduleCode] = trim($version);
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
        return $this->packageName;
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
     * Get the API code.
     *
     * @return string
     */
    public function getAPICode(): string
    {
        return $this->apiAlias;
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
     * Get the module status.
     *
     * @return int
     */
    public function getStatus(): int
    {
        return $this->status;
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
     * Pack the current version of the module into a phar file.
     *
     * @param string $version  The version of the package
     * @param bool   $savePhar Commit the version as a phar file
     *
     * @return string
     * @throws Error
     */
    public function commit(string $version, bool $savePhar = false): ?string
    {
        if ($version !== 'default' && $version !== 'dev') {
            $path = append($this->getContainerPath(), $version);
            if ($savePhar) {
                if (ini_get('phar.readonly') == 1) {
                    throw new Error('System has set phar.readonly, you cannot commit this version as phar file.');
                }

                if (!is_dir($path)) {
                    mkdir($path);
                }
                $pharPath = append($path, '/app.phar');
                $phar = new Phar($pharPath);
                $phar->startBuffering();

                // Archive all files exclude the web asset folder
                $phar->buildFromDirectory(append($this->getContainerPath(), 'default'), '/^(?!(' . preg_quote(append($this->getContainerPath(), 'default'), '/') . '\/webassets))(.*)/');

                if (count(glob(append($this->getContainerPath(), 'default', 'webassets/*'))) > 0) {
                    xcopy(append($this->getContainerPath(), 'default', 'webassets'), append($path, 'webassets'));
                }

                $phar->stopBuffering();

                // plus - compressing it into gzip
                $phar->compressFiles(Phar::GZ);
            } else {
                if (is_dir($path)) {
                    return null;
                }
                xcopy(append($this->getPath(), 'default'), $path);
            }

            return $path;
        }

        return null;
    }

    /**
     * Unpack the module asset to shared view folder.
     *
     * @param string $folder
     *
     * @return array
     */
    public function cloneAsset(string $folder): array
    {
        $clonedAsset = [];
        if (empty($this->assets)) {
            return [];
        }

        $folder = append(trim($folder), $this->alias);
        if (!is_dir($folder)) {
            try {
                mkdir($folder, 0777, true);
            } catch (Exception $e) {
                return [];
            }
        }

        foreach ($this->assets as $destPath => $pathInfo) {
            xcopy($pathInfo['system_path'], append($folder, $destPath), '', $unpacked);
            $clonedAsset = array_merge($clonedAsset, $unpacked);
        }

        return $clonedAsset;
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
