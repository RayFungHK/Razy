<?php

/**
 * This file is part of Razy v1.0.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 *
 * @license MIT
 */

namespace Razy\Scheduler;

use Closure;
use DateTimeZone;
use Throwable;

/**
 * A single scheduled task: cron expression + callable + execution policy.
 *
 * Created through the Scheduler fluent API (see Scheduler::call()), not directly.
 */
class Job
{
    private bool $overlappingBlocked = false;

    private int $maxLockSeconds = 3600;

    private bool $enabled = true;

    private ?int $lastRunAt = null;

    private ?int $lastDurationMs = null;

    private ?string $lastError = null;

    /**
     * @internal Use Scheduler::call() / Scheduler::command()
     */
    public function __construct(
        public readonly string $name,
        private CronExpression $cron,
        private readonly Closure $task,
    ) {
    }

    // ── Fluent policy ─────────────────────────────────────────

    public function cron(string $expression): static
    {
        $this->cron = CronExpression::parse($expression);

        return $this;
    }

    // ── Frequency sugar (cron-string presets) ─────────────────

    public function everyMinute(): static
    {
        return $this->cron('* * * * *');
    }

    public function everyFiveMinutes(): static
    {
        return $this->cron('*/5 * * * *');
    }

    public function everyFifteenMinutes(): static
    {
        return $this->cron('*/15 * * * *');
    }

    public function everyThirtyMinutes(): static
    {
        return $this->cron('*/30 * * * *');
    }

    public function hourly(): static
    {
        return $this->cron('0 * * * *');
    }

    public function daily(): static
    {
        return $this->cron('0 0 * * *');
    }

    /**
     * Daily at "HH:MM" (24h string, e.g. '03:30').
     */
    public function dailyAt(string $time): static
    {
        [$h, $m] = \array_pad(\explode(':', $time), 2, '0');

        return $this->cron(((int) $m) . ' ' . ((int) $h) . ' * * *');
    }

    public function weekly(): static
    {
        return $this->cron('0 0 * * 0');
    }

    public function monthly(): static
    {
        return $this->cron('0 0 1 * *');
    }

    /**
     * Skip execution while a previous run of this job is still holding its lock.
     *
     * @param int $maxSeconds Safety TTL for distributed backends (RedisLock);
     *                        advisory for FileLock (kernel auto-releases on death)
     */
    public function withoutOverlapping(int $maxSeconds = 3600): static
    {
        $this->overlappingBlocked = true;
        $this->maxLockSeconds = \max(1, $maxSeconds);

        return $this;
    }

    public function disabled(bool $disabled = true): static
    {
        $this->enabled = !$disabled;

        return $this;
    }

    // ── Execution ─────────────────────────────────────────────

    public function isDue(int $timestamp, DateTimeZone|string|null $tz = null): bool
    {
        return $this->enabled && $this->cron->isDue($timestamp, $tz);
    }

    /**
     * Run the task. Exceptions are captured (never rethrown) so one failing job
     * cannot take down the whole schedule pass; the error is recorded and
     * reported through the return value.
     *
     * @return bool True when the task completed without throwing
     */
    public function run(): bool
    {
        $start = \microtime(true);

        try {
            ($this->task)();
            $this->lastError = null;

            return true;
        } catch (Throwable $e) {
            $this->lastError = $e->getMessage();

            return false;
        } finally {
            $this->lastRunAt = \time();
            $this->lastDurationMs = (int) ((\microtime(true) - $start) * 1000);
        }
    }

    // ── Introspection ─────────────────────────────────────────

    public function getName(): string
    {
        return $this->name;
    }

    public function getExpression(): string
    {
        return $this->cron->expression;
    }

    public function getCron(): CronExpression
    {
        return $this->cron;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function overlapsPrevented(): bool
    {
        return $this->overlappingBlocked;
    }

    public function getLockTimeout(): int
    {
        return $this->maxLockSeconds;
    }

    public function lockName(): string
    {
        return 'job:' . $this->name;
    }

    public function getLastRunAt(): ?int
    {
        return $this->lastRunAt;
    }

    public function getLastDurationMs(): ?int
    {
        return $this->lastDurationMs;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }
}
