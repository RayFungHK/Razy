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

namespace Razy\Session;

use PDO;
use Razy\Contract\SessionDriverInterface;
use Razy\Database;
use Razy\Exception\ConfigurationException;
use Razy\Session\Driver\ArrayDriver;
use Razy\Session\Driver\DatabaseDriver;
use Razy\Session\Driver\FileDriver;
use Razy\Session\Driver\RedisDriver;
use Redis;

/**
 * RZ-05: builds a dist's one Session from dist.php `'session'`.
 *
 * ```php
 * 'session' => [
 *     'driver'   => 'database',          // file (default) | database | redis | array
 *     'database' => ['connection' => 'main', 'table' => 'sessions', 'create_table' => false],
 *     'name'     => 'app_session',       // cookie options: see SessionConfig::fromArray()
 *     'secure'   => 'auto',
 * ],
 * ```
 *
 * Driver sections: `file` => [`path`, `prefix`]; `database` => [`connection`
 * (a connected Database::getSharedInstance() name) or `pdo` (a PDO, for code
 * that builds the config), `table`, `create_table`]; `redis` => [`host`,
 * `port`, `prefix`, `password`, `database`].
 */
final class SessionFactory
{
    private const DRIVERS = ['file', 'database', 'redis', 'array'];

    /**
     * Check a dist.php `'session'` value at boot without building anything.
     *
     * @throws ConfigurationException
     */
    public static function validate(mixed $session, string $distCode): void
    {
        if (!\is_array($session)) {
            throw new ConfigurationException("dist.php 'session' must be an array for dist '{$distCode}'.");
        }
        [$driver, , $cookie] = self::split($session);
        SessionConfig::fromArray($cookie);
        if ($driver === 'database' && !isset($session['database']['connection']) && !isset($session['database']['pdo'])) {
            throw new ConfigurationException("Session driver 'database' needs database.connection (a Database instance name) for dist '{$distCode}'.");
        }
    }

    /**
     * @param array<string, mixed> $session dist.php `'session'` (empty = file driver, default cookie)
     *
     * @throws ConfigurationException
     */
    public static function create(array $session, string $distCode): Session
    {
        [$driver, $options, $cookie] = self::split($session);

        return new Session(self::driver($driver, $options, $distCode), SessionConfig::fromArray($cookie));
    }

    /**
     * @param array<string, mixed> $session
     *
     * @return array{0: string, 1: array<string, mixed>, 2: array<string, mixed>}
     */
    private static function split(array $session): array
    {
        $driver = $session['driver'] ?? 'file';
        if (!\is_string($driver) || !\in_array($driver, self::DRIVERS, true)) {
            throw new ConfigurationException("Session 'driver' must be one of: " . \implode(', ', self::DRIVERS) . '.');
        }
        $options = $session[$driver] ?? [];
        if (!\is_array($options)) {
            throw new ConfigurationException("Session '{$driver}' options must be an array.");
        }
        $cookie = $session;
        unset($cookie['driver']);
        foreach (self::DRIVERS as $name) {
            unset($cookie[$name]);
        }

        return [$driver, $options, $cookie];
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function driver(string $driver, array $options, string $distCode): SessionDriverInterface
    {
        return match ($driver) {
            'array' => new ArrayDriver(),
            'file' => new FileDriver(
                (string) ($options['path'] ?? \rtrim(\str_replace('\\', '/', \sys_get_temp_dir()), '/') . '/razy_sessions'),
                (string) ($options['prefix'] ?? $distCode . '_sess_'),
            ),
            'database' => self::databaseDriver($options),
            'redis' => self::redisDriver($options, $distCode),
            default => throw new ConfigurationException("Unknown session driver '{$driver}'."),
        };
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function databaseDriver(array $options): DatabaseDriver
    {
        $pdo = $options['pdo'] ?? null;
        if (!$pdo instanceof PDO) {
            $name = $options['connection'] ?? null;
            $db = \is_string($name) ? Database::getSharedInstance($name) : null;
            $pdo = $db?->getDBAdapter();
            if (!$pdo instanceof PDO) {
                throw new ConfigurationException("Session driver 'database' needs database.connection naming a connected Database instance.");
            }
        }
        $driver = new DatabaseDriver($pdo, (string) ($options['table'] ?? 'sessions'));
        if (($options['create_table'] ?? false) === true) {
            $driver->createTable();
        }

        return $driver;
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function redisDriver(array $options, string $distCode): RedisDriver
    {
        if (!\class_exists(Redis::class)) {
            throw new ConfigurationException("Session driver 'redis' needs the phpredis extension.");
        }
        $redis = new Redis();
        $redis->connect((string) ($options['host'] ?? '127.0.0.1'), (int) ($options['port'] ?? 6379));
        if (isset($options['password'])) {
            $redis->auth((string) $options['password']);
        }
        if (isset($options['database'])) {
            $redis->select((int) $options['database']);
        }

        return new RedisDriver($redis, (string) ($options['prefix'] ?? $distCode . ':sess:'));
    }
}
