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
use InvalidArgumentException;
use Razy\Scheduler\Lock\LockInterface;
use RuntimeException;

/**
 * Registry + runner for cron-scheduled tasks.
 *
 * Designed to be driven by one crontab line — the framework equivalent of
 * Laravel's scheduler:
 *
 * ```
 * * * * * * php /app/Razy.phar schedule run >> /var/log/razy-schedule.log 2>&1
 * ```
 *
 * Job definitions live in `scheduler.inc.php` at the project root:
 *
 * ```php
 * <?php
 * return function (Razy\Scheduler\Scheduler $schedule): void {
 *     $schedule->command('php Razy.phar cache prune')
 *         ->daily()
 *         ->withoutOverlapping();
 *
 *     $schedule->call(fn () => warmOpcache())->hourly();
 * };
 * ```
 *
 * Known scope boundary (honest): missed-run policies (Laravel `->runsOnce()`-style
 * catch-up) are NOT implemented; a tick that finds a job no longer due skips it,
 * same as Laravel's default. Multi-node deployments MUST use RedisLock.
 */
class Scheduler
{
    /** @var array<string, Job> */
    private array $jobs = [];

    private int $anonymousIndex = 0;

    public function __construct(
        private ?LockInterface $lock = null,
    ) {
    }

    // ── Registration ──────────────────────────────────────────

    /**
     * Schedule an inline PHP callable.
     */
    public function call(Closure $task, ?string $name = null): Job
    {
        $name ??= 'closure#' . (++$this->anonymousIndex);

        return $this->register(new Job($name, CronExpression::parse('* * * * *'), $task));
    }

    /**
     * Schedule a shell command (developer-authored definitions only — never
     * construct these from user input; that is RZ-011 territory).
     *
     * The task fails (and reports through Job::getLastError()) on non-zero exit.
     */
    public function command(string $command, ?string $name = null): Job
    {
        $task = static function () use ($command): void {
            $exit = 127;
            // dev-null stderr into stdout keeps cron mail clean
            @\exec($command . ' 2>&1', $output, $exit);

            if ($exit !== 0) {
                throw new RuntimeException("Scheduled command exited with {$exit}: {$command}");
            }
        };

        $name ??= 'command#' . (++$this->anonymousIndex);

        return $this->register(new Job($name, CronExpression::parse('* * * * *'), $task));
    }

    /**
     * Replace the overlap lock backend (e.g. RedisLock for multi-node).
     */
    public function useLock(?LockInterface $lock): static
    {
        $this->lock = $lock;

        return $this;
    }

    // ── Query / run ───────────────────────────────────────────

    /**
     * @return list<Job> Jobs enabled and matching the given minute
     */
    public function due(int $now, DateTimeZone|string|null $tz = null): array
    {
        return \array_values(\array_filter(
            $this->jobs,
            static fn (Job $job): bool => $job->isDue($now, $tz),
        ));
    }

    /**
     * Execute everything due at $now (defaults to the current minute).
     *
     * @param callable|null $logger receives ['job'=>string,'status'=>string,'ms'=>int]
     *
     * @return array<string, string> job name => 'ok' | 'failed' | 'skipped-overlap' | 'no-lock'
     */
    public function run(?int $now = null, ?callable $logger = null, DateTimeZone|string|null $tz = null): array
    {
        $now ??= \time();
        $results = [];

        foreach ($this->due($now, $tz) as $job) {
            $needsLock = $job->overlapsPrevented();

            if ($needsLock && $this->lock === null) {
                // Fail closed: overlap protection was asked for but no backend exists.
                $results[$job->getName()] = 'no-lock';
                $this->emit($logger, ['job' => $job->getName(), 'status' => 'no-lock', 'ms' => 0]);

                continue;
            }

            if ($needsLock && !$this->lock->acquire($job->lockName(), $job->getLockTimeout())) {
                $results[$job->getName()] = 'skipped-overlap';
                $this->emit($logger, ['job' => $job->getName(), 'status' => 'skipped-overlap', 'ms' => 0]);

                continue;
            }

            $start = \microtime(true);
            $ok = $job->run();

            if ($needsLock) {
                $this->lock->release($job->lockName());
            }

            $results[$job->getName()] = $ok ? 'ok' : 'failed';
            $this->emit($logger, [
                'job' => $job->getName(),
                'status' => $ok ? 'ok' : 'failed',
                'ms' => (int) ((\microtime(true) - $start) * 1000),
                'error' => $ok ? null : $job->getLastError(),
            ]);
        }

        return $results;
    }

    /**
     * @return array<string, Job>
     */
    public function getJobs(): array
    {
        return $this->jobs;
    }

    // ── Internal ──────────────────────────────────────────────

    private function emit(?callable $logger, array $event): void
    {
        if ($logger !== null) {
            $logger($event);
        }
    }

    private function register(Job $job): Job
    {
        if (isset($this->jobs[$job->getName()])) {
            throw new InvalidArgumentException("Duplicate schedule job name: {$job->getName()}");
        }

        $this->jobs[$job->getName()] = $job;

        return $job;
    }
}
