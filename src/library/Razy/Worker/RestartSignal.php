<?php
/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Razy\Worker;

/**
 * File-based signal mechanism for worker lifecycle management.
 *
 * Provides a cross-process communication channel using the filesystem.
 * A deployer / CLI command writes a signal file, and worker processes
 * check for it periodically to trigger graceful restart or hot-swap.
 *
 * Signal file format (JSON):
 * {
 *     "action": "restart" | "swap" | "terminate",
 *     "timestamp": 1708700000,
 *     "modules": ["module_a", "module_b"],  // optional: specific modules for swap
 *     "reason": "Module update deployed"     // optional: human-readable reason
 * }
 *
 * Usage:
 *   Deploy side:  RestartSignal::send($path, 'restart', reason: 'v2.0 deployed');
 *   Worker side:  $signal = RestartSignal::check($path);
 *   After handle: RestartSignal::clear($path);
 */
class RestartSignal
{
    /** @var string Action: graceful restart (Strategy A) */
    public const ACTION_RESTART = 'restart';

    /** @var string Action: hot-swap specific modules (Strategy B/C) */
    public const ACTION_SWAP = 'swap';

    /** @var string Action: immediate terminate */
    public const ACTION_TERMINATE = 'terminate';

    /** @var string Default signal file name */
    public const DEFAULT_FILENAME = '.worker-signal';

    /**
     * Check for a pending signal.
     *
     * @param string $signalPath Absolute path to the signal file
     * @return array|null Parsed signal data, or null if no signal
     */
    public static function check(string $signalPath): ?array
    {
        if (!is_file($signalPath)) {
            return null;
        }

        $content = file_get_contents($signalPath);
        if ($content === false || $content === '') {
            return null;
        }

        $data = json_decode($content, true);
        if (!is_array($data) || !isset($data['action'])) {
            return null;
        }

        // Validate action
        $validActions = [self::ACTION_RESTART, self::ACTION_SWAP, self::ACTION_TERMINATE];
        if (!in_array($data['action'], $validActions, true)) {
            return null;
        }

        return $data;
    }

    /**
     * Send a signal to worker processes.
     *
     * @param string $signalPath Absolute path to the signal file
     * @param string $action One of ACTION_RESTART, ACTION_SWAP, ACTION_TERMINATE
     * @param array $modules Optional list of module codes (for swap action)
     * @param string $reason Optional human-readable reason
     * @return bool True if signal was written successfully
     */
    public static function send(
        string $signalPath,
        string $action,
        array $modules = [],
        string $reason = ''
    ): bool {
        $data = [
            'action' => $action,
            'timestamp' => time(),
        ];

        if (!empty($modules)) {
            $data['modules'] = $modules;
        }

        if ($reason !== '') {
            $data['reason'] = $reason;
        }

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return false;
        }

        // Atomic write: write to temp file then rename
        $tmpPath = $signalPath . '.tmp.' . getmypid();
        if (file_put_contents($tmpPath, $json, LOCK_EX) === false) {
            return false;
        }

        return rename($tmpPath, $signalPath);
    }

    /**
     * Clear a signal file after it has been processed.
     *
     * @param string $signalPath Absolute path to the signal file
     * @return bool True if cleared (or already absent)
     */
    public static function clear(string $signalPath): bool
    {
        if (!is_file($signalPath)) {
            return true;
        }

        return unlink($signalPath);
    }

    /**
     * Get the default signal file path for a site/distributor.
     *
     * @param string $basePath The site's data or config directory
     * @return string Absolute path to the signal file
     */
    public static function getDefaultPath(string $basePath): string
    {
        return rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR . self::DEFAULT_FILENAME;
    }

    /**
     * Check if a signal is stale (older than the given TTL).
     * Stale signals are auto-cleared to prevent stuck workers.
     *
     * @param array $signal Parsed signal data from check()
     * @param int $ttlSeconds Maximum age in seconds (default: 300 = 5 minutes)
     * @return bool True if the signal is stale
     */
    public static function isStale(array $signal, int $ttlSeconds = 300): bool
    {
        if (!isset($signal['timestamp'])) {
            return true;
        }

        return (time() - $signal['timestamp']) > $ttlSeconds;
    }
}
