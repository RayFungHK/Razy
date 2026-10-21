<?php

/**
 * This file is part of Razy.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Razy\Health;

use Closure;
use InvalidArgumentException;
use Razy\Error\ErrorReporter;
use Throwable;

/**
 * RZ-13: named readiness checks, run by `GET /_razy/ready`.
 *
 * A check is a callable that returns `true` (or nothing) when the dependency
 * is usable, and returns `false` or throws when it is not. Checks run in
 * registration order, one after another, on every probe; keep them cheap
 * (`SELECT 1`, a cache ping) and give network calls their own short timeout,
 * because PHP cannot interrupt a check that hangs.
 *
 * A failing **critical** check makes the probe answer 503 (`fail`); a failing
 * non-critical one keeps 200 but reports `degraded`. Each dist owns one
 * registry, filled by modules with `$agent->addHealthCheck()`.
 *
 * @license MIT
 */
final class HealthRegistry
{
    public const STATUS_OK = 'ok';

    public const STATUS_DEGRADED = 'degraded';

    public const STATUS_FAIL = 'fail';

    /** Check names: letters, digits, `.`, `_`, `-`, `/`, `:`; up to 100 characters. */
    private const NAME = '/^[A-Za-z0-9][A-Za-z0-9._\/:\-]{0,99}$/';

    /** @var array<string, array{check: Closure, critical: bool}> */
    private array $checks = [];

    /**
     * Add (or replace) a check.
     *
     * @param string $name Unique name, e.g. `vendor/module:db`
     * @param callable $check fn(): bool|void — false or a throw means "not ready"
     * @param bool $critical Whether a failure makes the whole probe fail (503)
     *
     * @throws InvalidArgumentException on an invalid name
     */
    public function register(string $name, callable $check, bool $critical = true): static
    {
        if (\preg_match(self::NAME, $name) !== 1) {
            throw new InvalidArgumentException("Invalid health check name '{$name}'.");
        }
        $this->checks[$name] = ['check' => $check(...), 'critical' => $critical];

        return $this;
    }

    /**
     * Remove a check; no-op when absent.
     */
    public function remove(string $name): static
    {
        unset($this->checks[$name]);

        return $this;
    }

    public function has(string $name): bool
    {
        return isset($this->checks[$name]);
    }

    /**
     * Registered check names, in run order.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return \array_keys($this->checks);
    }

    /**
     * Run every check.
     *
     * Each entry has `status` (`ok`/`fail`), `critical` and `duration_ms`;
     * a failure also carries `error` (the exception message or "returned
     * false"). The caller decides whether to show `error` (it can hold
     * internal detail such as a host name). Failures are logged through
     * `Razy\Error\ErrorReporter::warn()`.
     *
     * @return array{status: string, checks: array<string, array{status: string, critical: bool, duration_ms: float, error?: string}>}
     */
    public function run(): array
    {
        $status = self::STATUS_OK;
        $results = [];
        foreach ($this->checks as $name => $entry) {
            $result = $this->runOne($name, $entry['check'], $entry['critical']);
            $results[$name] = $result;
            if ($result['status'] === self::STATUS_FAIL) {
                $status = $entry['critical'] ? self::STATUS_FAIL : ($status === self::STATUS_FAIL ? $status : self::STATUS_DEGRADED);
            }
        }

        return ['status' => $status, 'checks' => $results];
    }

    /**
     * @return array{status: string, critical: bool, duration_ms: float, error?: string}
     */
    private function runOne(string $name, Closure $check, bool $critical): array
    {
        $started = \hrtime(true);
        $error = null;
        try {
            if ($check() === false) {
                $error = 'returned false';
            }
        } catch (Throwable $e) {
            $error = $e::class . ': ' . ErrorReporter::plainMessage($e);
        }
        $result = [
            'status' => $error === null ? self::STATUS_OK : self::STATUS_FAIL,
            'critical' => $critical,
            'duration_ms' => \round((\hrtime(true) - $started) / 1e6, 2),
        ];
        if ($error !== null) {
            $result['error'] = $error;
            ErrorReporter::warn("Health check '{$name}' failed: {$error}");
        }

        return $result;
    }
}
