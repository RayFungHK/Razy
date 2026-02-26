<?php

/**
 * This file is part of Razy v0.5.
 *
 * Represents a single execution thread (inline or process-based) managed by ThreadManager.
 * Tracks status, result, timing, and I/O streams for the executed task.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 *
 * @license MIT
 */

namespace Razy;

use Throwable;

/**
 * Represents a managed thread of execution in the Razy framework.
 *
 * A Thread can operate in two modes:
 * - Inline: synchronous PHP callable execution
 * - Process: asynchronous shell command execution via proc_open
 *
 * Tracks lifecycle state (pending → running → completed/failed), timing,
 * stdout/stderr output, and exit codes.
 *
 * @class Thread
 */
class Thread
{
    /** @var string Thread is created but not yet started */
    public const STATUS_PENDING = 'pending';

    /** @var string Thread is currently executing */
    public const STATUS_RUNNING = 'running';

    /** @var string Thread finished successfully */
    public const STATUS_COMPLETED = 'completed';

    /** @var string Thread terminated with an error */
    public const STATUS_FAILED = 'failed';

    /** @var string Inline mode: callable executed synchronously */
    public const MODE_INLINE = 'inline';

    /** @var string Process mode: shell command executed asynchronously */
    public const MODE_PROCESS = 'process';

    /** @var string Current lifecycle status of this thread */
    private string $status = self::STATUS_PENDING;

    /** @var string Execution mode (inline or process) */
    private string $mode = self::MODE_INLINE;

    /** @var mixed The result value upon successful completion */
    private mixed $result = null;

    /** @var Throwable|null The error thrown on failure */
    private ?Throwable $error = null;

    /** @var float Microtime timestamp when execution started */
    private float $startedAt = 0.0;

    /** @var float Microtime timestamp when execution ended */
    private float $endedAt = 0.0;

    /** @var mixed The proc_open process resource (process mode only) */
    private mixed $process = null;

    /**
     * @var array<int, resource> Pipe resources for stdin/stdout/stderr (process mode only)
     */
    private array $pipes = [];

    /** @var string Accumulated standard output from the process */
    private string $stdout = '';

    /** @var string Accumulated standard error from the process */
    private string $stderr = '';

    /** @var int|null Process exit code (null if not yet exited or inline mode) */
    private ?int $exitCode = null;

    /** @var string The shell command executed (process mode only) */
    private string $command = '';

    /**
     * Thread constructor.
     *
     * @param string $id Unique identifier for this thread
     */
    public function __construct(private readonly string $id)
    {
    }

    /**
     * Get the unique thread identifier.
     *
     * @return string
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * Get the current lifecycle status.
     *
     * @return string One of STATUS_PENDING, STATUS_RUNNING, STATUS_COMPLETED, STATUS_FAILED
     */
    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * Get the result value from a completed thread.
     *
     * @return mixed The result, or null if not yet completed
     */
    public function getResult(): mixed
    {
        return $this->result;
    }

    /**
     * Get the execution mode.
     *
     * @return string One of MODE_INLINE or MODE_PROCESS
     */
    public function getMode(): string
    {
        return $this->mode;
    }

    /**
     * Get the error thrown during execution, if any.
     *
     * @return Throwable|null
     */
    public function getError(): ?Throwable
    {
        return $this->error;
    }

    /**
     * Get the microtime timestamp when execution started.
     *
     * @return float
     */
    public function getStartedAt(): float
    {
        return $this->startedAt;
    }

    /**
     * Get the microtime timestamp when execution ended.
     *
     * @return float
     */
    public function getEndedAt(): float
    {
        return $this->endedAt;
    }

    /**
     * Get accumulated stdout output (process mode only).
     *
     * @return string
     */
    public function getStdout(): string
    {
        return $this->stdout;
    }

    /**
     * Get accumulated stderr output (process mode only).
     *
     * @return string
     */
    public function getStderr(): string
    {
        return $this->stderr;
    }

    /**
     * Get the process exit code.
     *
     * @return int|null Exit code, or null if not applicable
     */
    public function getExitCode(): ?int
    {
        return $this->exitCode;
    }

    /**
     * Get the shell command executed (process mode only).
     *
     * @return string
     */
    public function getCommand(): string
    {
        return $this->command;
    }

    /**
     * Check whether the thread has finished (completed or failed).
     *
     * @return bool
     */
    public function isFinished(): bool
    {
        return $this->status === self::STATUS_COMPLETED || $this->status === self::STATUS_FAILED;
    }

    /**
     * Transition the thread to running state and record the start time.
     */
    public function markRunning(): void
    {
        $this->status = self::STATUS_RUNNING;
        $this->startedAt = \microtime(true);
    }

    /**
     * Configure the thread for process mode with the given process handle and pipes.
     *
     * @param mixed $process The proc_open process resource
     * @param array $pipes Array of pipe resources [stdin, stdout, stderr]
     * @param string $command The shell command being executed
     */
    public function markProcess(mixed $process, array $pipes, string $command): void
    {
        $this->mode = self::MODE_PROCESS;
        $this->process = $process;
        $this->pipes = $pipes;
        $this->command = $command;
        $this->markRunning();
    }

    /**
     * Mark the thread as successfully completed with a result value.
     *
     * @param mixed $result The execution result
     */
    public function resolve(mixed $result): void
    {
        $this->status = self::STATUS_COMPLETED;
        $this->result = $result;
        $this->endedAt = \microtime(true);
    }

    /**
     * Set the result value without changing the thread status.
     *
     * @param mixed $result The result to store
     */
    public function setResult(mixed $result): void
    {
        $this->result = $result;
    }

    /**
     * Mark the thread as failed with the given error.
     *
     * @param Throwable $error The error that caused the failure
     */
    public function fail(Throwable $error): void
    {
        $this->status = self::STATUS_FAILED;
        $this->error = $error;
        $this->endedAt = \microtime(true);
    }

    /**
     * Append a chunk of data to the stdout buffer.
     *
     * @param string $chunk The output data to append
     */
    public function appendStdout(string $chunk): void
    {
        if ($chunk !== '') {
            $this->stdout .= $chunk;
        }
    }

    /**
     * Append a chunk of data to the stderr buffer.
     *
     * @param string $chunk The error data to append
     */
    public function appendStderr(string $chunk): void
    {
        if ($chunk !== '') {
            $this->stderr .= $chunk;
        }
    }

    /**
     * Set the process exit code.
     *
     * @param int|null $exitCode The exit code to store
     */
    public function setExitCode(?int $exitCode): void
    {
        $this->exitCode = $exitCode;
    }

    /**
     * Get the pipe resources for the process.
     *
     * @return array<int, resource>
     */
    public function getPipes(): array
    {
        return $this->pipes;
    }

    /**
     * Get the proc_open process resource.
     *
     * @return mixed The process resource or null
     */
    public function getProcess(): mixed
    {
        return $this->process;
    }

    /**
     * Release the process resource and associated pipes.
     */
    public function clearProcess(): void
    {
        $this->process = null;
        $this->pipes = [];
    }
}
