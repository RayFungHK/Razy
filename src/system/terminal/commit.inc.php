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

return function (string $moduleCode = '', string $version = '', string $commitAsPhar = '') use (&$parameters) {
    $this->writeLineLogging('{@s:bu}Commit module', true);

    $path = SYSTEM_ROOT;
    $distCode = '';
    $commitAsPhar = !!$commitAsPhar;
    if (str_contains($moduleCode, '@')) {
        [$distCode, $moduleCode] = explode('@', $moduleCode, 2);
    }

    if (!preg_match('/^[a-z0-9]([_.-]?[a-z0-9]+)*\/[a-z0-9](([_.]?|-{0,2})[a-z0-9]+)*$/i', $moduleCode)) {
        $this->writeLineLogging('{@c:red}The module code ' . $moduleCode . ' is not a correct format, it should be `vendor/package`.', true);

        return;
    }

    if (!$distCode) {
        $path = append($path, 'shared', 'module');
    } else {
        $path = append($path, 'sites', $distCode);
    }
    $path = append($path, $moduleCode);

    if (!$version) {
        $this->writeLineLogging('{@c:red} Missing version code.', true);
        return;
    }

    if (!preg_match('/^(\d+)(?:\.(?:\d+|\*)){0,3}$/', $version)) {
        $this->writeLineLogging('{@c:red}' . $version . ' is not a valid format.', true);
        return;
    }

    $moduleConfigPath = append($path, 'module.php');
    if (is_file($moduleConfigPath)) {
        try {
            $config = require $moduleConfigPath;

            $config['module_code'] = $config['module_code'] ?? '';
            if (!preg_match(ModuleInfo::REGEX_MODULE_CODE, $config['module_code'])) {
                throw new Error('Incorrect module code format.');
            }
            $config['author'] = $config['author'] ?? '';
            $config['description'] = $config['description'] ?? '';
        } catch (Exception) {
            $this->writeLineLogging('{@c:red}Unable to read the module config.', true);
            return;
        }
    } else {
        $this->writeLineLogging('{@c:red}Module config file is not found.', true);
        return;
    }

    if ($module = new ModuleInfo($path, $config, 'default')) {
        if ($version !== 'default' && $version !== 'dev') {
            try {
                $path = append($module->getContainerPath(), $version);
                if ($commitAsPhar) {
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
                    $phar->buildFromDirectory(append($module->getContainerPath(), 'default'), '/^(?!(' . preg_quote(append($module->getContainerPath(), 'default'), '/') . '\/webassets))(.*)/');

                    if (count(glob(append($module->getContainerPath(), 'default', 'webassets/*'))) > 0) {
                        xcopy(append($module->getContainerPath(), 'default', 'webassets'), append($path, 'webassets'));
                    }

                    $phar->stopBuffering();

                    // plus - compressing it into gzip
                    $phar->compressFiles(Phar::GZ);
                } else {
                    if (is_dir($path)) {
                        return null;
                    }
                    xcopy(append($module->getContainerPath(), 'default'), $path);
                }

                $this->writeLineLogging('Module `' . $moduleCode . '` (' . ((!$distCode) ? 'Shared Module' : $distCode) . ') has committed as version ' . $version, true);
                $this->writeLineLogging('Location: ' . $path, true);
            }
            catch (\Exception) {
                $this->writeLineLogging('{@c:red}Failed to commit, the selected version may exist.', true);
            }
        }
    } else {
        $this->writeLineLogging('Module `' . $moduleCode . '` has not found', true);

    }
};
