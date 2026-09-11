<?php

/**
 * CLI Command: schedule.
 *
 * Runs cron-scheduled jobs defined in scheduler.inc.php at the project root.
 * Designed for a single crontab line:
 *
 *   * * * * * cd /app && php Razy.phar schedule run >> /var/log/razy-schedule.log 2>&1
 *
 * Usage:
 *   php Razy.phar schedule run            Execute all jobs due this minute
 *   php Razy.phar schedule list           Show registered jobs + next run time
 *   php Razy.phar schedule test <name>    Run one job immediately, ignoring cron
 *
 * Options:
 *   --tz=Asia/Hong_Kong   Evaluate cron expressions in this timezone
 *                         (default: the process timezone)
 *
 * @license MIT
 */

namespace Razy;

use Razy\Scheduler\Lock\FileLock;
use Razy\Scheduler\Scheduler;

return function () {
    $args = \func_get_args();
    $subcommand = $args[0] ?? 'help';

    $options = [];
    foreach ($args as $arg) {
        if (\str_starts_with($arg, '--')) {
            $parts = \explode('=', \substr($arg, 2), 2);
            $options[$parts[0]] = $parts[1] ?? true;
        }
    }

    $tz = isset($options['tz']) && \is_string($options['tz']) ? $options['tz'] : null;

    $projectRoot = \getcwd();
    $definitionFile = $projectRoot . DIRECTORY_SEPARATOR . 'scheduler.inc.php';

    /**
     * @return array{0: Scheduler, 1: bool} scheduler instance + whether the definition file loaded
     */
    $buildScheduler = function () use ($projectRoot, $definitionFile): array {
        if (!\is_file($definitionFile)) {
            return [new Scheduler(new FileLock($projectRoot . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'scheduler' . DIRECTORY_SEPARATOR . 'locks')), false];
        }

        $schedule = new Scheduler(new FileLock($projectRoot . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'scheduler' . DIRECTORY_SEPARATOR . 'locks'));
        $definition = require $definitionFile;

        if (\is_callable($definition)) {
            $definition($schedule);
        }

        return [$schedule, true];
    };

    switch ($subcommand) {
        case 'run':
            [$schedule, $loaded] = $buildScheduler();

            if (!$loaded) {
                $this->writeLineLogging('{@c:red}Error: scheduler.inc.php not found in project root.{@reset}');
                $this->writeLineLogging('Create it:');
                $this->writeLineLogging(\sprintf('  {@c:cyan}%s{@reset}', '<?php return function (Razy\Scheduler\Scheduler $s): void { $s->command(\'php Razy.phar cache purge\')->daily(); };'));

                break;
            }

            $results = $schedule->run(null, function (array $event): void {
                $color = match ($event['status']) {
                    'ok' => 'green',
                    'failed' => 'red',
                    default => 'yellow',
                };
                $suffix = ($event['error'] ?? null) !== null ? ' — ' . $event['error'] : '';
                $this->writeLineLogging(\sprintf(
                    '{@c:cyan}[%s]{@reset} {@c:%s}%-15s{@reset} %s (%dms)%s',
                    \date('H:i:s'),
                    $color,
                    $event['status'],
                    $event['job'],
                    $event['ms'],
                    $suffix,
                ));
            }, $tz);

            if ($results === []) {
                $this->writeLineLogging('{@c:yellow}No jobs due at this minute.{@reset}');
            }
            break;

        case 'list':
            [$schedule, $loaded] = $buildScheduler();

            if (!$loaded) {
                $this->writeLineLogging('{@c:red}Error: scheduler.inc.php not found in project root.{@reset}');
                break;
            }

            $jobs = $schedule->getJobs();

            if ($jobs === []) {
                $this->writeLineLogging('{@c:yellow}scheduler.inc.php registered no jobs.{@reset}');
                break;
            }

            $this->writeLineLogging(\sprintf('{@c:cyan}%-22s %-18s %s{@reset}', 'JOB', 'CRON', 'NEXT RUN'));
            foreach ($jobs as $job) {
                $next = $job->getCron()->nextRun(\time(), $tz);
                $this->writeLineLogging(\sprintf(
                    '  {@c:green}%-22s {@reset}%-18s %s%s',
                    $job->getName(),
                    $job->getExpression(),
                    $next !== null ? \date('Y-m-d H:i', $next) : '—',
                    $job->overlapsPrevented() ? '  {@c:cyan}[no-overlap]{@reset}' : '',
                ));
            }
            break;

        case 'test':
            $name = $args[1] ?? '';

            if ($name === '' || \str_starts_with($name, '--')) {
                $this->writeLineLogging('{@c:red}Usage: schedule test <job-name>{@reset}');
                break;
            }

            [$schedule] = $buildScheduler();
            $jobs = $schedule->getJobs();

            if (!isset($jobs[$name])) {
                $this->writeLineLogging(\sprintf('{@c:red}No job named "%s". Known: {@reset}%s', $name, \implode(', ', \array_keys($jobs))));
                break;
            }

            $this->writeLineLogging(\sprintf('Running {@c:green}%s{@reset} immediately (cron ignored)...', $name));
            $ok = $jobs[$name]->run();
            $this->writeLineLogging($ok
                ? \sprintf('{@c:green}Done (%dms).{@reset}', $jobs[$name]->getLastDurationMs() ?? 0)
                : \sprintf('{@c:red}Failed: %s{@reset}', $jobs[$name]->getLastError() ?? 'unknown'));
            break;

        default:
            $this->writeLineLogging('{@c:yellow}Usage:{@reset} php Razy.phar schedule [run|list|test]');
            $this->writeLineLogging('');
            $this->writeLineLogging(\sprintf('  {@c:green}%-14s{@reset} %s', 'run', 'Execute all jobs due this minute (crontab entry point)'));
            $this->writeLineLogging(\sprintf('  {@c:green}%-14s{@reset} %s', 'list', 'Show registered jobs and next run time'));
            $this->writeLineLogging(\sprintf('  {@c:green}%-14s{@reset} %s', 'test <name>', 'Run one job immediately, ignoring its schedule'));
            $this->writeLineLogging('');
            $this->writeLineLogging('{@c:yellow}Options:{@reset}');
            $this->writeLineLogging(\sprintf('  {@c:cyan}%-20s{@reset} %s', '--tz=UTC', 'Timezone cron expressions are authored in'));
            $this->writeLineLogging('');
            $this->writeLineLogging('{@c:yellow}Crontab:{@reset}');
            $this->writeLineLogging('  * * * * * cd /app && php Razy.phar schedule run >> /var/log/razy-schedule.log 2>&1');
            break;
    }
};
