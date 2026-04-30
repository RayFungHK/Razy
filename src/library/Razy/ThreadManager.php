<?php

/**
 * This file is part of Razy v0.5.
 *
 * Thread pool manager for async task execution. Supports inline (synchronous
 * callable) and process (asynchronous shell command via proc_open) modes
 * with configurable concurrency limits.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 *
 * @license MIT
 */

namespace Razy;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * ThreadManager - Manages thread pool for async task execution.
 *
 * Supports two execution modes:
 * - **Inline Mode**: PHP callable executed synchronously (blocking)
 * - **Process Mode**: Shell command executed asynchronously (non-blocking)
 *
 * @note **Windows Shell Escaping Limitation**: When using process mode with PHP `-r`
 *       arguments containing nested quotes, Windows cmd.exe may strip or mangle quotes.
 *       Use `spawnPHPCode()` for complex PHP scripts, which uses base64 encoding to
 *       avoid escaping issues.
 *
 * @example Inline mode (synchronous)
 * ```php
 * $tm = new ThreadManager();
 * $thread = $tm->spawn(function() { return 42; });
 * $result = $thread->getResult(); // 42
 * ```
 * @example Process mode - simple command (works on all platforms)
 * ```php
 * $thread = $tm->spawn(fn() => null, [
 *     'command' => 'php',
 *     'args' => ['-r', 'echo getmypid();']  // Simple, no nested quotes
 * ]);
 * ```
 * @example Process mode - complex PHP code (recommended for Windows)
 * ```php
 * $thread = $tm->spawnPHPCode('echo json_encode(["key" => "value"]);');
 * $result = $tm->await($thread->getId());
 * ```
 */
class ThreadManager
{
    /**
     * Environment variable names that must not be overridden via $options['env'].
     * These can alter process/runtime behavior in security-sensitive ways.
     */
    private const BLOCKED_ENV_VARS = [
        'LD_PRELOAD',
        'LD_LIBRARY_PATH',
        'DYLD_INSERT_LIBRARIES',
        'DYLD_LIBRARY_PATH',
        'PHPRC',
        'PHP_INI_SCAN_DIR',
        'PHP_INI_DIR',
    ];

    /**
     * @var array<string, Thread> All managed threads indexed by ID
     */
    private array $threads = [];

    /** @var array Queued thread specs waiting for a concurrency slot */
    private array $queue = [];

    /** @var int Maximum number of concurrent process-mode threads */
    private int $maxConcurrency = 4;

    /**
     * Spawn a new thread to execute a task.
     *
     * If options contain a 'command' key, spawns in process mode;
     * otherwise executes the callable inline (synchronously).
     *
     * @param callable $task The callable to execute (inline mode)
     * @param array $options Options: 'command' (string), 'args' (array), 'cwd', 'env'
     *
     * @return Thread The spawned thread
     *
     * @throws InvalidArgumentException If 'command' option is provided but invalid
     */
    public function spawn(callable $task, array $options = []): Thread
    {
        // If a command is specified, delegate to process mode
        if (isset($options['command'])) {
            $command = $options['command'];
            if (!\is_string($command) || $command === '') {
                throw new InvalidArgumentException('Invalid thread command.');
            }

            return $this->spawnProcessCommand($command, $options['args'] ?? [], $options);
        }

        // Inline mode: generate unique ID and execute callable synchronously
        $id = \bin2hex(\random_bytes(8));
        $thread = new Thread($id);
        $this->threads[$id] = $thread;

        $thread->markRunning();
        try {
            $result = $task();
            $thread->resolve($result);
        } catch (Throwable $error) {
            $thread->fail($error);
        }

        return $thread;
    }

    /**
     * Spawn a PHP code execution thread using base64 encoding.
     *
     * This method avoids Windows shell escaping issues by encoding the PHP code
     * as base64, which eliminates problems with nested quotes and special characters.
     *
     * @param string $phpCode The PHP code to execute (without <?php tag)
     * @param string|null $phpPath Path to PHP executable (auto-detected if null)
     * @param array $options Additional options: cwd, env
     *
     * @return Thread The spawned thread
     *
     * @example
     * ```php
     * // Complex code with quotes - works on Windows and Unix
     * $thread = $tm->spawnPHPCode('echo json_encode(["key" => "value"]);');
     * $result = $tm->await($thread->getId());
     * echo $result['stdout']; // {"key":"value"}
     * ```
     */
    public function spawnPHPCode(string $phpCode, ?string $phpPath = null, array $options = []): Thread
    {
        // Auto-detect PHP path
        if ($phpPath === null) {
            $phpPath = $this->findPHPExecutable();
        }

        // Encode PHP code as base64 to avoid shell escaping issues
        $encoded = \base64_encode($phpCode);

        // Build command that decodes and evaluates the code
        // Using eval(base64_decode('...')) avoids all quoting issues
        $evalCode = 'eval(base64_decode(\'' . $encoded . '\'));';

        return $this->spawnProcessCommand($phpPath, ['-r', $evalCode], $options);
    }

    /**
     * Spawn PHP code from a temporary file.
     *
     * Alternative to spawnPHPCode() that writes code to a temp file and executes it.
     * Useful for very long scripts or when base64 overhead is a concern.
     *
     * Security: The temp file is created in a private directory with restrictive
     * permissions (0600) and cleaned up deterministically when the thread finishes.
     * A shutdown-function fallback ensures cleanup even on unexpected termination.
     *
     * @param string $phpCode The PHP code to execute (without <?php tag)
     * @param string|null $phpPath Path to PHP executable (auto-detected if null)
     * @param array $options Additional options: cwd, env
     *
     * @return Thread The spawned thread
     */
    public function spawnPHPFile(string $phpCode, ?string $phpPath = null, array $options = []): Thread
    {
        // Use the provided PHP path or fall back to current binary
        if ($phpPath === null) {
            $phpPath = $this->findPHPExecutable();
        }

        // Create a private temp directory to isolate temp files from other users
        $privateTmpDir = $this->getPrivateTempDir();

        // Generate a cryptographically random filename to prevent prediction
        $phpFile = $privateTmpDir . DIRECTORY_SEPARATOR . 'razy_' . \bin2hex(\random_bytes(12)) . '.php';

        // Write to a staging file first, set permissions, then rename atomically
        $stagingFile = $phpFile . '.staging';
        if (@\file_put_contents($stagingFile, '<?php ' . $phpCode, LOCK_EX) === false) {
            @\unlink($stagingFile);
            throw new RuntimeException('Failed to write temporary PHP file for thread execution.');
        }
        \chmod($stagingFile, 0o600);

        // Atomic move to final path — prevents TOCTOU between write and execution
        if (!@\rename($stagingFile, $phpFile)) {
            @\unlink($stagingFile);
            throw new RuntimeException('Failed to finalize temporary PHP file for thread execution.');
        }

        $thread = $this->spawnProcessCommand($phpPath, [$phpFile], $options);

        // Register temp file on the Thread for deterministic cleanup on finish
        $thread->setTempFile($phpFile);

        // Fallback: shutdown function cleans up if process is killed unexpectedly
        \register_shutdown_function(static function () use ($phpFile) {
            if (\is_file($phpFile)) {
                @\unlink($phpFile);
            }
        });

        return $thread;
    }

    /**
     * Spawn a process-mode thread with a shell command and arguments.
     *
     * If the concurrency limit is reached, the thread is queued and
     * will be started once a running process completes.
     *
     * @param string $command The shell command to execute
     * @param array $args Command-line arguments
     * @param array $options Additional options: 'cwd', 'env'
     *
     * @return Thread The spawned (or queued) thread
     */
    public function spawnProcessCommand(string $command, array $args = [], array $options = []): Thread
    {
        $id = \bin2hex(\random_bytes(8));
        $thread = new Thread($id);
        $this->threads[$id] = $thread;

        $spec = [
            'command' => $this->buildCommand($command, $args),
            'cwd' => $options['cwd'] ?? null,
            'env' => $this->filterEnv($options['env'] ?? null),
        ];

        // If concurrency limit reached, queue the thread for later execution
        if ($this->countRunningProcesses() >= $this->maxConcurrency) {
            $this->queue[] = ['thread' => $thread, 'spec' => $spec];
            return $thread;
        }

        $this->startProcess($thread, $spec);
        return $thread;
    }

    /**
     * Wait for a thread to complete and return its result.
     *
     * Polls the process (if process mode) until it finishes or the timeout expires.
     *
     * @param string $id Thread identifier
     * @param int|null $timeoutMs Timeout in milliseconds, or null for no timeout
     *
     * @return mixed The thread result, or null if the thread failed
     *
     * @throws InvalidArgumentException If no thread exists with the given ID
     */
    public function await(string $id, ?int $timeoutMs = null): mixed
    {
        $thread = $this->getThread($id);
        if (!$thread) {
            throw new InvalidArgumentException('Thread not found: ' . $id);
        }

        // Drain queued threads to fill available concurrency slots
        $this->drainQueue();

        if ($thread->getMode() === Thread::MODE_PROCESS) {
            $this->pollProcess($thread, $timeoutMs);
        }

        // Prune completed threads to prevent unbounded memory growth
        $this->pruneCompleted();

        if ($thread->getStatus() === Thread::STATUS_FAILED) {
            return null;
        }

        return $thread->getResult();
    }

    /**
     * Wait for multiple threads and return all results keyed by thread ID.
     *
     * @param array $threads Array of Thread objects or thread ID strings
     * @param int|null $timeoutMs Per-thread timeout in milliseconds
     *
     * @return array<string, mixed> Results indexed by thread ID
     */
    public function joinAll(array $threads, ?int $timeoutMs = null): array
    {
        $results = [];
        foreach ($threads as $thread) {
            $id = $thread instanceof Thread ? $thread->getId() : (string) $thread;
            $results[$id] = $this->await($id, $timeoutMs);
        }

        return $results;
    }

    /**
     * Get the current status of a thread, polling if it is a process.
     *
     * @param string $id Thread identifier
     *
     * @return string Thread status constant
     *
     * @throws InvalidArgumentException If no thread exists with the given ID
     */
    public function status(string $id): string
    {
        $thread = $this->getThread($id);
        if (!$thread) {
            throw new InvalidArgumentException('Thread not found: ' . $id);
        }

        if ($thread->getMode() === Thread::MODE_PROCESS) {
            $this->pollProcess($thread, 0);
        }

        return $thread->getStatus();
    }

    /**
     * Set the maximum number of concurrent process-mode threads.
     *
     * @param int $maxConcurrency Must be >= 1
     */
    public function setMaxConcurrency(int $maxConcurrency): void
    {
        $this->maxConcurrency = \max(1, $maxConcurrency);
    }

    /**
     * Retrieve a thread by its identifier.
     *
     * @param string $id Thread identifier
     *
     * @return Thread|null The thread, or null if not found
     */
    public function getThread(string $id): ?Thread
    {
        return $this->threads[$id] ?? null;
    }

    /**
     * Get or create a private temporary directory for this process.
     *
     * Creates a subdirectory under sys_get_temp_dir() with 0700 permissions,
     * readable only by the current process owner. This prevents other local
     * users from reading/replacing temporary PHP files (symlink attack defense).
     *
     * @return string Absolute path to the private temp directory
     *
     * @throws RuntimeException If the directory cannot be created
     */
    private function getPrivateTempDir(): string
    {
        $dir = \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'razy_threads_' . \getmypid();
        if (!\is_dir($dir)) {
            if (!@\mkdir($dir, 0o700, true) && !\is_dir($dir)) {
                throw new RuntimeException('Failed to create private temp directory: ' . $dir);
            }
            \chmod($dir, 0o700);
        }

        return $dir;
    }

    /**
     * Count how many threads are currently running in process mode.
     *
     * @return int
     */
    private function countRunningProcesses(): int
    {
        $count = 0;
        foreach ($this->threads as $thread) {
            if ($thread->getMode() === Thread::MODE_PROCESS && $thread->getStatus() === Thread::STATUS_RUNNING) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Remove completed/failed threads from the internal map to prevent unbounded memory growth.
     * Keeps only threads that are still pending or running.
     */
    private function pruneCompleted(): void
    {
        foreach ($this->threads as $id => $thread) {
            $status = $thread->getStatus();
            if ($status === Thread::STATUS_COMPLETED || $status === Thread::STATUS_FAILED) {
                unset($this->threads[$id]);
            }
        }
    }

    /**
     * Start queued threads until the concurrency limit is reached.
     */
    private function drainQueue(): void
    {
        while ($this->queue && $this->countRunningProcesses() < $this->maxConcurrency) {
            $item = \array_shift($this->queue);
            $thread = $item['thread'];
            $spec = $item['spec'];
            $this->startProcess($thread, $spec);
        }
    }

    /**
     * Start a process for the given thread using proc_open.
     *
     * Sets up stdin/stdout/stderr pipes with non-blocking I/O.
     *
     * @param Thread $thread The thread to start
     * @param array $spec Process specification with 'command', 'cwd', 'env'
     */
    private function startProcess(Thread $thread, array $spec): void
    {
        if (!\function_exists('proc_open')) {
            $thread->fail(new Error('Process backend is not available.'));
            return;
        }

        // Define pipe descriptors: 0=stdin(r), 1=stdout(w), 2=stderr(w)
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = \proc_open($spec['command'], $descriptors, $pipes, $spec['cwd'], $spec['env']);
        if (!\is_resource($process)) {
            $thread->fail(new Error('Failed to start thread process.'));
            return;
        }

        // Set pipes to non-blocking mode for polling reads
        foreach ($pipes as $pipe) {
            \stream_set_blocking($pipe, false);
        }

        $thread->markProcess($process, $pipes, $spec['command']);
    }

    /**
     * Poll a process thread until it finishes or the timeout expires.
     *
     * Reads stdout/stderr output and checks process status in a loop
     * with 10ms sleep intervals to avoid busy-waiting.
     *
     * @param Thread $thread The process-mode thread to poll
     * @param int|null $timeoutMs Timeout in milliseconds, or null for no timeout
     */
    private function pollProcess(Thread $thread, ?int $timeoutMs): void
    {
        if ($thread->isFinished()) {
            return;
        }

        $start = \microtime(true);
        $timeout = $timeoutMs !== null ? $timeoutMs / 1000 : null;

        do {
            $this->collectProcessOutput($thread);
            $status = $this->getProcessStatus($thread);
            if ($status !== null && !$status['running']) {
                $this->finalizeProcess($thread, $status);
                $this->drainQueue();
                return;
            }

            if ($timeout !== null && (\microtime(true) - $start) >= $timeout) {
                $this->terminateProcess($thread);
                return;
            }

            if ($timeoutMs === 0) {
                break;
            }

            // Sleep 10ms to avoid busy-waiting
            \usleep(10000);
        } while (true);
    }

    /**
     * Get the process status from proc_get_status.
     *
     * @param Thread $thread The thread whose process status to check
     *
     * @return array|null Process status array, or null if invalid
     */
    private function getProcessStatus(Thread $thread): ?array
    {
        $process = $thread->getProcess();
        if (!$process || !\is_resource($process)) {
            return null;
        }

        return \proc_get_status($process);
    }

    /**
     * Read available output from process pipes into the thread's buffers.
     *
     * @param Thread $thread The thread to collect output from
     */
    private function collectProcessOutput(Thread $thread): void
    {
        // Read from stdout (pipe index 1) and stderr (pipe index 2)
        foreach ($thread->getPipes() as $index => $pipe) {
            if (!\is_resource($pipe)) {
                continue;
            }

            $chunk = \stream_get_contents($pipe);
            if ($index === 1) {
                $thread->appendStdout($chunk ?: '');
            } elseif ($index === 2) {
                $thread->appendStderr($chunk ?: '');
            }
        }
    }

    /**
     * Finalize a completed process: collect remaining output, close pipes,
     * clean up temp files, and resolve/fail.
     *
     * @param Thread $thread The completed thread
     * @param array $status Process status from proc_get_status
     */
    private function finalizeProcess(Thread $thread, array $status): void
    {
        $this->collectProcessOutput($thread);
        $this->closePipes($thread);
        $thread->cleanupTempFile();
        $exitCode = $status['exitcode'] ?? null;
        $thread->setExitCode($exitCode);

        $result = [
            'stdout' => $thread->getStdout(),
            'stderr' => $thread->getStderr(),
            'exit_code' => $exitCode,
            'command' => $thread->getCommand(),
        ];

        if ($exitCode !== 0) {
            $thread->fail(new Error('Thread process failed with exit code ' . $exitCode . '.'));
            $thread->setResult($result);
            return;
        }

        $thread->resolve($result);
    }

    /**
     * Terminate a running process (e.g., on timeout) and mark the thread as failed.
     *
     * @param Thread $thread The thread to terminate
     */
    private function terminateProcess(Thread $thread): void
    {
        $process = $thread->getProcess();
        if ($process && \is_resource($process)) {
            \proc_terminate($process);
        }

        $this->collectProcessOutput($thread);
        $this->closePipes($thread);
        $thread->cleanupTempFile();
        $thread->setExitCode(null);
        $thread->setResult([
            'stdout' => $thread->getStdout(),
            'stderr' => $thread->getStderr(),
            'exit_code' => null,
            'command' => $thread->getCommand(),
        ]);
        $thread->fail(new Error('Thread timeout.'));
    }

    /**
     * Close all pipe resources and the process handle, then clear references.
     *
     * @param Thread $thread The thread whose pipes/process to close
     */
    private function closePipes(Thread $thread): void
    {
        foreach ($thread->getPipes() as $pipe) {
            if (\is_resource($pipe)) {
                \fclose($pipe);
            }
        }

        $process = $thread->getProcess();
        if ($process && \is_resource($process)) {
            \proc_close($process);
        }

        $thread->clearProcess();
    }

    /**
     * Build command string with escaped arguments.
     *
     * @note **Windows Limitation**: On Windows, `escapeshellarg()` uses double quotes,
     *       which can cause issues with arguments containing nested quotes (e.g., PHP `-r`
     *       code with string literals). For complex PHP code, use `spawnPHPCode()` instead
     *       which uses base64 encoding to avoid escaping issues.
     *
     * @param string $command The command to execute
     * @param array $args Arguments to pass to the command
     *
     * @return string The complete command string
     *
     * @example Safe arguments (work on all platforms):
     * ```php
     * // Simple values without nested quotes
     * buildCommand('php', ['-r', 'echo 123;']);            // OK
     * buildCommand('php', ['-r', 'echo getmypid();']);     // OK
     * buildCommand('php', ['-v']);                         // OK
     * ```
     * @example Problematic arguments (use spawnPHPCode instead):
     * ```php
     * // Nested quotes fail on Windows
     * buildCommand('php', ['-r', 'echo "hello";']);        // May fail on Windows
     * buildCommand('php', ['-r', 'echo json_encode([]);']); // May fail on Windows
     * ```
     */
    private function buildCommand(string $command, array $args): string
    {
        if (!$args) {
            return $command;
        }

        $escaped = \array_map(function (string $arg): string {
            return \escapeshellarg($arg);
        }, $args);

        return $command . ' ' . \implode(' ', $escaped);
    }

    /**
     * Filter environment variables, removing security-sensitive overrides.
     *
     * Blocks env vars that could alter the PHP runtime (PHPRC, PHP_INI_SCAN_DIR)
     * or inject shared libraries (LD_PRELOAD, DYLD_INSERT_LIBRARIES).
     *
     * @param array|null $env User-supplied environment variables
     *
     * @return array|null Filtered env, or null if nothing was provided
     */
    private function filterEnv(?array $env): ?array
    {
        if ($env === null) {
            return null;
        }

        foreach (self::BLOCKED_ENV_VARS as $blocked) {
            unset($env[$blocked]);
        }

        return $env;
    }

    /**
     * Auto-detect the PHP executable path.
     *
     * Uses PHP_BINARY (the running PHP binary) as the canonical source.
     *
     * @return string Path to the PHP executable
     *
     * @throws RuntimeException If the PHP binary cannot be determined
     */
    private function findPHPExecutable(): string
    {
        $binary = PHP_BINARY;
        if (!\is_file($binary)) {
            throw new RuntimeException('Unable to determine PHP executable path.');
        }

        return $binary;
    }
}
