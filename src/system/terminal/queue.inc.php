<?php

/**
 * CLI Command: queue.
 *
 * Manage the Razy job queue system. Supports running a worker loop,
 * checking queue status, retrying failed jobs, and clearing queues.
 *
 * Usage:
 *   php Razy.phar queue work [queue]    Process jobs in a loop
 *   php Razy.phar queue once [queue]    Process a single job and exit
 *   php Razy.phar queue status [queue]  Show queue statistics
 *   php Razy.phar queue clear [queue]   Remove completed/buried jobs
 *   php Razy.phar queue retry <id>      Re-queue a failed/buried job
 *
 * Work options (via environment or flags):
 *   --sleep=3       Seconds to sleep when queue is empty (default: 3)
 *   --max-jobs=0    Max jobs to process before exiting (0 = unlimited)
 *   --memory=128    Memory limit in MB before worker restarts (default: 128)
 *   --tries=3       Max attempts per job (default: 3)
 *   --timeout=60    Max execution time per job in seconds (default: 60)
 *
 * @license MIT
 */

namespace Razy;

use Razy\Queue\JobStatus;
use Razy\Queue\QueueManager;
use Throwable;

return function () {
    $args = \func_get_args();
    $subcommand = $args[0] ?? 'help';
    $queueName = $args[1] ?? 'default';

    // Parse --flag=value options from arguments
    $options = [];
    foreach ($args as $arg) {
        if (\str_starts_with($arg, '--')) {
            $parts = \explode('=', \substr($arg, 2), 2);
            $options[$parts[0]] = $parts[1] ?? true;
        }
    }

    // Resolve QueueManager — requires a database connection
    $getManager = function (): ?QueueManager {
        try {
            // Attempt to get the QueueManager from the framework
            if (\class_exists(QueueManager::class)) {
                // Try to create a QueueManager using the default database
                $db = Database::getSharedInstance();
                if ($db !== null) {
                    $store = new Queue\DatabaseStore($db);

                    return new QueueManager($store);
                }
            }
        } catch (Throwable $e) {
            // Fall through
        }

        return null;
    };

    switch ($subcommand) {
        case 'work':
            $sleep = (int) ($options['sleep'] ?? 3);
            $maxJobs = (int) ($options['max-jobs'] ?? 0);
            $memLimit = (int) ($options['memory'] ?? 128);
            $processed = 0;

            $this->writeLineLogging("Starting queue worker for [{$queueName}]...");
            $this->writeLineLogging(\sprintf(
                '  {@c:cyan}%-14s{@reset} sleep=%ds, max-jobs=%s, memory=%dMB',
                'Options:',
                $sleep,
                $maxJobs > 0 ? $maxJobs : 'unlimited',
                $memLimit
            ));

            $manager = $getManager();
            if ($manager === null) {
                $this->writeLineLogging('{@c:red}Error: Could not initialize QueueManager. Check database connection.{@reset}');
                break;
            }

            $manager->ensureStorage();

            // Register event listeners for logging
            $manager->on('reserved', function (array $ctx) {
                $this->writeLineLogging(\sprintf(
                    '{@c:cyan}[%s]{@reset} Processing job #%d (%s) attempt %d',
                    \date('H:i:s'),
                    $ctx['id'],
                    $ctx['handler'] ?? 'unknown',
                    $ctx['attempts']
                ));
            });

            $manager->on('completed', function (array $ctx) {
                $this->writeLineLogging(\sprintf(
                    '{@c:green}[%s]{@reset} Completed job #%d',
                    \date('H:i:s'),
                    $ctx['id']
                ));
            });

            $manager->on('failed', function (array $ctx) {
                $this->writeLineLogging(\sprintf(
                    '{@c:yellow}[%s]{@reset} Failed job #%d: %s',
                    \date('H:i:s'),
                    $ctx['id'],
                    $ctx['error'] ?? 'unknown error'
                ));
            });

            $manager->on('buried', function (array $ctx) {
                $this->writeLineLogging(\sprintf(
                    '{@c:red}[%s]{@reset} Buried job #%d: %s',
                    \date('H:i:s'),
                    $ctx['id'],
                    $ctx['error'] ?? 'max attempts exceeded'
                ));
            });

            while (true) {
                // Memory check
                $memUsage = \memory_get_usage(true) / 1024 / 1024;
                if ($memUsage >= $memLimit) {
                    $this->writeLineLogging(\sprintf(
                        '{@c:yellow}Memory limit reached (%.1fMB / %dMB). Stopping worker.{@reset}',
                        $memUsage,
                        $memLimit
                    ));
                    break;
                }

                $result = $manager->process($queueName);

                if ($result) {
                    $processed++;

                    if ($maxJobs > 0 && $processed >= $maxJobs) {
                        $this->writeLineLogging(\sprintf(
                            '{@c:green}Processed %d jobs (max reached). Stopping worker.{@reset}',
                            $processed
                        ));
                        break;
                    }
                } else {
                    // No job available — sleep
                    \sleep($sleep);
                }
            }

            $this->writeLineLogging(\sprintf(
                '{@c:green}Worker stopped. Total processed: %d{@reset}',
                $processed
            ));
            break;

        case 'once':
            $manager = $getManager();
            if ($manager === null) {
                $this->writeLineLogging('{@c:red}Error: Could not initialize QueueManager.{@reset}');
                break;
            }

            $manager->ensureStorage();
            $result = $manager->process($queueName);

            if ($result) {
                $this->writeLineLogging('{@c:green}Processed one job successfully.{@reset}');
            } else {
                $this->writeLineLogging('{@c:yellow}No jobs available in queue [{$queueName}].{@reset}');
            }
            break;

        case 'status':
            $manager = $getManager();
            if ($manager === null) {
                $this->writeLineLogging('{@c:red}Error: Could not initialize QueueManager.{@reset}');
                break;
            }

            $manager->ensureStorage();

            $pending = $manager->count($queueName, JobStatus::Pending);
            $reserved = $manager->count($queueName, JobStatus::Reserved);
            $completed = $manager->count($queueName, JobStatus::Completed);
            $failed = $manager->count($queueName, JobStatus::Failed);
            $buried = $manager->count($queueName, JobStatus::Buried);
            $total = $pending + $reserved + $completed + $failed + $buried;

            $this->writeLineLogging("Queue Status: [{$queueName}]");
            $this->writeLineLogging(\sprintf('  {@c:cyan}%-14s{@reset} %d', 'Total:', $total));
            $this->writeLineLogging(\sprintf('  {@c:cyan}%-14s{@reset} %d', 'Pending:', $pending));
            $this->writeLineLogging(\sprintf('  {@c:cyan}%-14s{@reset} %d', 'Reserved:', $reserved));
            $this->writeLineLogging(\sprintf('  {@c:green}%-14s{@reset} %d', 'Completed:', $completed));
            $this->writeLineLogging(\sprintf('  {@c:yellow}%-14s{@reset} %d', 'Failed:', $failed));
            $this->writeLineLogging(\sprintf('  {@c:red}%-14s{@reset} %d', 'Buried:', $buried));
            break;

        case 'clear':
            $manager = $getManager();
            if ($manager === null) {
                $this->writeLineLogging('{@c:red}Error: Could not initialize QueueManager.{@reset}');
                break;
            }

            $manager->ensureStorage();
            $cleared = $manager->clear($queueName);
            $this->writeLineLogging(\sprintf(
                '{@c:green}Cleared %d completed/buried jobs from [%s].{@reset}',
                $cleared,
                $queueName
            ));
            break;

        case 'retry':
            $jobId = isset($args[1]) ? (int) $args[1] : 0;
            if ($jobId <= 0) {
                $this->writeLineLogging('{@c:red}Usage: queue retry <job-id>{@reset}');
                break;
            }

            $manager = $getManager();
            if ($manager === null) {
                $this->writeLineLogging('{@c:red}Error: Could not initialize QueueManager.{@reset}');
                break;
            }

            $manager->ensureStorage();
            $store = $manager->getStore();
            $job = $store->find($jobId);

            if ($job === null) {
                $this->writeLineLogging("{@c:red}Job #{$jobId} not found.{@reset}");
                break;
            }

            if ($job->status !== JobStatus::Failed && $job->status !== JobStatus::Buried) {
                $this->writeLineLogging(\sprintf(
                    '{@c:yellow}Job #%d is in [%s] status. Only failed/buried jobs can be retried.{@reset}',
                    $jobId,
                    $job->status->value
                ));
                break;
            }

            $store->release($jobId, 0, null);
            $this->writeLineLogging(\sprintf(
                '{@c:green}Job #%d has been re-queued for retry.{@reset}',
                $jobId
            ));
            break;

        default:
            $this->writeLineLogging('{@c:yellow}Usage:{@reset} php Razy.phar queue [work|once|status|clear|retry]');
            $this->writeLineLogging('');
            $this->writeLineLogging(\sprintf('  {@c:green}%-14s{@reset} %s', 'work [queue]', 'Process jobs in a loop'));
            $this->writeLineLogging(\sprintf('  {@c:green}%-14s{@reset} %s', 'once [queue]', 'Process a single job'));
            $this->writeLineLogging(\sprintf('  {@c:green}%-14s{@reset} %s', 'status [queue]', 'Show queue statistics'));
            $this->writeLineLogging(\sprintf('  {@c:green}%-14s{@reset} %s', 'clear [queue]', 'Remove completed/buried jobs'));
            $this->writeLineLogging(\sprintf('  {@c:green}%-14s{@reset} %s', 'retry <id>', 'Re-queue a failed/buried job'));
            $this->writeLineLogging('');
            $this->writeLineLogging('{@c:yellow}Work Options:{@reset}');
            $this->writeLineLogging(\sprintf('  {@c:cyan}%-20s{@reset} %s', '--sleep=3', 'Seconds to sleep when idle'));
            $this->writeLineLogging(\sprintf('  {@c:cyan}%-20s{@reset} %s', '--max-jobs=0', 'Max jobs before exit (0=unlimited)'));
            $this->writeLineLogging(\sprintf('  {@c:cyan}%-20s{@reset} %s', '--memory=128', 'Memory limit in MB'));
            break;
    }
};
