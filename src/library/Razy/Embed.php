<?php

/**
 * RZ-52: boot a distributor from your own PHP script.
 *
 * This file is part of Razy.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * @license MIT
 */

declare(strict_types=1);

namespace Razy;

use Phar;
use Razy\Config\SiteConfig;
use Razy\Exception\ConfigurationException;

/**
 * The documented entry for a process that is not the web front controller or the
 * `Razy.phar` command line: a worker, a queue consumer, a stdio server, a test
 * harness. One call does what the other entries do before they touch a module.
 *
 *   require 'phar:///srv/app/Razy.phar/library/Razy/Embed.php'; // or vendor/autoload.php
 *   $dist = \Razy\Embed::boot('/srv/app', 'main');
 *   $dist->getRegistry()->getLoadedAPIModule('acme/hello')->executeInternalCommand('greet', ['Ada']);
 *
 * `boot()` defines `SYSTEM_ROOT` and the other path constants, loads the framework
 * bootstrap (the same file `main.php` loads: autoloader, `.env` and config,
 * plugin folders), then builds the Distributor and runs `initialize()`, which
 * registers the distributor's module library autoloader. It does not run the CLI
 * dispatcher, so the script's own arguments are never read as a Razy command.
 *
 * The process serves one install: a second call with the same root is allowed (it
 * returns another Distributor, e.g. for another dist), a call with a different
 * root throws, because `SYSTEM_ROOT` and the folders under it are constants.
 */
final class Embed
{
    /** @var string|null The real path of the root this process booted */
    private static ?string $root = null;

    /**
     * Boot a distributor.
     *
     * @param string $root The Razy install: the folder that holds `sites/` (and `config/`, `shared/`, `.env`)
     * @param string $dist The distributor code, or `code@tag`; it is `sites/<code>/dist.php`
     *
     * @throws ConfigurationException When the root is not a folder, the process booted another root, or the dist is not in `sites/`
     */
    public static function boot(string $root, string $dist): Distributor
    {
        // Loaded by phar:// path the framework autoloader does not exist yet, and the checks below throw first
        \class_exists(ConfigurationException::class) || require_once __DIR__ . '/Exception/ConfigurationException.php';
        \class_exists(SiteConfig::class) || require_once __DIR__ . '/Config/SiteConfig.php';

        // realpath('') is the working directory and a NUL byte is a ValueError: neither names a root
        $path = $root === '' || \str_contains($root, "\0") ? false : \realpath($root);
        if ($path === false || !\is_dir($path)) {
            throw new ConfigurationException("Embed::boot(): '" . \addcslashes(\substr($root, 0, 200), "\0..\37") . "' is not a directory.");
        }

        $booted = self::$root ?? (\defined('SYSTEM_ROOT') ? (string) \realpath(SYSTEM_ROOT) : null);
        if ($booted !== null && $booted !== $path) {
            throw new ConfigurationException("Embed::boot(): this process already runs with SYSTEM_ROOT '{$booted}', it cannot also boot '{$path}'.");
        }

        if (\preg_match(SiteConfig::DIST_IDENTIFIER, $dist) !== 1) {
            throw new ConfigurationException("Embed::boot(): '" . \addcslashes(\substr($dist, 0, 200), "\0..\37") . "' is not a distributor code (code or code@tag).");
        }
        [$code, $tag] = \explode('@', $dist . '@', 2);
        $distFile = $path . \DIRECTORY_SEPARATOR . 'sites' . \DIRECTORY_SEPARATOR . $code . \DIRECTORY_SEPARATOR . 'dist.php';
        if (!\is_file($distFile)) {
            throw new ConfigurationException("Embed::boot(): no distributor '{$code}': {$distFile} is missing.");
        }

        self::$root = $path;
        if (!\defined('SYSTEM_ROOT')) {
            \define('SYSTEM_ROOT', $path);
        }
        if (!\defined('RAZY_VERSION')) {
            // The phar (phar://<file>) or the source tree (<checkout>/src): the folder above library/
            $core = \dirname(__DIR__, 2);
            \defined('PHAR_FILE') || \define('PHAR_FILE', \basename(Phar::running(false)));
            \defined('PHAR_PATH') || \define('PHAR_PATH', $core);
            \defined('CORE_FOLDER') || \define('CORE_FOLDER', PHAR_PATH . \DIRECTORY_SEPARATOR . 'system' . \DIRECTORY_SEPARATOR);

            require CORE_FOLDER . 'bootstrap.inc.php';
        }

        return (new Distributor($code, $tag !== '' ? $tag : '*', null, '', '/'))->initialize();
    }
}
