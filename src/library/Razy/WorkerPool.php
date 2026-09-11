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

namespace Razy;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Persistent PHP worker-process pool for CPU-heavy or boot-heavy jobs.
 *
 * Complements ThreadManager (which spawns ONE short-lived process per task and
 * pays PHP boot + cold autoload on every job): WorkerPool boots N workers once
 * and feeds them jobs over a line-delimited JSON protocol on stdin/stdout, so
 * the per-job cost drops to the job itself (opcache stays warm, startup work is
 * amortised).
 *
 * Wire protocol (one JSON object per line):
 *   parent → worker:  {"id":"..","job":"<abs path>","payload":{...},"timeout":3.0}
 *                     {"cmd":"shutdown"}
 *   worker → parent:  {"id":"..","ok":true,"result":..}
 *                     {"id":"..","ok":false,"error":"Class: message"}
 *
 * Job files return a `Closure(array $payload): mixed`; results must be
 * JSON-encodable (the universal cross-process constraint). Code is ALWAYS
 * file-based — the pool never evals transmitted strings, which is the
 * migration path off spawnPHPCode()/eval (RZ-011).
 *
 * TIMEOUT MODEL (why it lives in the worker): PHP on Windows cannot do a
 * non-blocking read with a deadline on anonymous pipes — fread/stream_get_contents
 * block until data or EOF, and stream_select misreports anonymous pipes as
 * always-readable. Verified empirically on this platform. So a job's deadline is
 * enforced INSIDE the worker via set_time_limit(): a CPU-bound job that exceeds
 * its budget trips "Maximum execution time" (a FATAL, not catchable), the worker
 * dies, and the parent's blocking read returns at that pipe EOF. Granularity is
 * 1 second (set_time_limit is integer seconds). A job blocked inside one
 * uninterruptible syscall (e.g. sleep()) is only reclaimed once that call returns
 * — matching the platform reality; the pool never hangs on CPU work.
 *
 * Delivery is AT-MOST-ONCE: when a worker dies (fatal or timeout), its in-flight
 * jobs fail with a reason and a replacement boots on the next dispatch — a job is
 * never silently re-run. Consumers needing retries belong on Queue + a store.
 *
 * Cross-platform by construction: proc_open pipes + cooperative shutdown frame,
 * no posix/pcntl, no signals — identical logic on Windows and Linux.
 *
 * @example
 * ```php
 * $pool = new WorkerPool(size: 2);
 * $id = $pool->submitCode('return fn(array $p) => array_sum($p["nums"]);',
 *     ['nums' => [1, 2, 3]], timeoutSeconds: 10);
 * $out = $pool->wait($id);            // ['ok' => true, 'result' => 6]
 * $pool->shutdown();
 * ```
 */
class WorkerPool
{
    /** Auto-generated worker runner, written once per pool into a 0700 dir. */
    private const RUNNER_SOURCE = <<<'PHP'
        <?php
        // Razy WorkerPool runner — auto-generated, do not edit on disk.
        stream_set_blocking(STDIN, true);

        $emit = static function (array $frame): void {
            echo \json_encode($frame, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), "\n";
            \flush();
        };

        while (($line = \fgets(STDIN)) !== false) {
            $line = \trim($line);
            if ($line === '') {
                continue;
            }
            try {
                $frame = \json_decode($line, true, 16, JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                $emit(['id' => null, 'ok' => false, 'error' => 'malformed frame']);
                continue;
            }
            if (($frame['cmd'] ?? '') === 'shutdown') {
                break;
            }
            $id = $frame['id'] ?? null;
            $job = $frame['job'] ?? '';
            $payload = $frame['payload'] ?? [];
            $timeout = $frame['timeout'] ?? null;
            if (!\is_string($job) || !\is_file($job)) {
                $emit(['id' => $id, 'ok' => false, 'error' => 'job file missing']);
                continue;
            }
            // Enforce the deadline HERE: exceeding it raises a FATAL that kills this
            // worker (parent sees pipe EOF and reaps). 0 = unlimited while idle/between.
            $secs = (\is_numeric($timeout) && $timeout > 0) ? \max(1, (int) \ceil((float) $timeout)) : 0;
            @\set_time_limit($secs);
            try {
                $handler = require $job;
                if (!$handler instanceof \Closure) {
                    throw new \RuntimeException('job file must return a Closure');
                }
                $result = $handler(\is_array($payload) ? $payload : []);
                @\set_time_limit(0);
                $emit(['id' => $id, 'ok' => true, 'result' => $result]);
            } catch (\Throwable $e) {
                @\set_time_limit(0);
                $emit(['id' => $id, 'ok' => false, 'error' => $e::class . ': ' . $e->getMessage()]);
            }
        }
        exit(0);
        PHP;

    /** @var array<int, array{proc: resource, stdin: resource, stdout: resource, alive: bool, buf: string}> */
    private array $workers = [];

    /** @var array<string, int> job id → owning worker index */
    private array $jobs = [];

    /** @var array<string, array{ok: bool, result?: mixed, error?: string}> */
    private array $responses = [];

    private readonly string $poolDir;

    private ?string $runnerPath = null;

    private int $submitCounter = 0;

    private int $nextWorker = 0;

    private bool $shutDown = false;

    private bool $shutdownHookRegistered = false;

    /**
     * @param int $size Number of persistent workers (1–64)
     * @param string|null $phpPath PHP binary (defaults to PHP_BINARY)
     * @param float|null $defaultTimeout Per-job deadline in SECONDS applied when
     *                                   a submit omits one (null = no limit).
     *                                   Enforced in-worker via set_time_limit
     *                                   (integer-second granularity).
     */
    public function __construct(
        private readonly int $size = 2,
        private readonly ?string $phpPath = null,
        private readonly ?float $defaultTimeout = 30.0,
    ) {
        if ($size < 1 || $size > 64) {
            throw new InvalidArgumentException('WorkerPool size must be between 1 and 64.');
        }

        $this->poolDir = \sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'razy_pool_' . \getmypid() . '_' . \bin2hex(\random_bytes(4));
    }

    // ── Public API ────────────────────────────────────────────

    /**
     * Dispatch a pre-authored job file (must return a Closure).
     *
     * The file path is developer-controlled — RZ-011 still applies to the
     * file's CONTENT: never generate it from user input.
     *
     * @param float|null $timeoutSeconds in-worker deadline (null = pool default)
     *
     * @return string Job id for wait()
     *
     * @throws InvalidArgumentException when the job file does not exist
     */
    public function submit(string $jobPath, array $payload = [], ?float $timeoutSeconds = null): string
    {
        if ($this->shutDown) {
            throw new RuntimeException('WorkerPool already shut down.');
        }

        if (!\is_file($jobPath)) {
            throw new InvalidArgumentException('Job file not found: ' . $jobPath);
        }

        $id = 'j' . (++$this->submitCounter) . '_' . \bin2hex(\random_bytes(4));
        $worker = $this->assignWorker();
        $this->jobs[$id] = $worker;

        $timeout = $timeoutSeconds ?? $this->defaultTimeout;
        $frame = ['id' => $id, 'job' => $jobPath, 'payload' => $payload];

        if ($timeout !== null && $timeout > 0) {
            $frame['timeout'] = $timeout;
        }

        $this->writeFrame($worker, (string) \json_encode($frame, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $id);

        return $id;
    }

    /**
     * Store `return function (array $payload) {...};` source (a plain string,
     * same trust level as spawnPHPFile) in the pool's 0600 jobs dir and submit it.
     *
     * @param float|null $timeoutSeconds in-worker deadline (null = pool default)
     */
    public function submitCode(string $phpReturnClosure, array $payload = [], ?float $timeoutSeconds = null): string
    {
        $dir = $this->poolDir . DIRECTORY_SEPARATOR . 'jobs';

        if (!\is_dir($dir) && !@\mkdir($dir, 0o700, true) && !\is_dir($dir)) {
            throw new RuntimeException('WorkerPool: cannot create jobs dir.');
        }

        $final = $dir . DIRECTORY_SEPARATOR . 'job_' . \bin2hex(\random_bytes(8)) . '.php';
        $staging = $final . '.staging';

        if (@\file_put_contents($staging, '<?php ' . $phpReturnClosure, LOCK_EX) === false) {
            @\unlink($staging);
            throw new RuntimeException('WorkerPool: cannot write job file.');
        }
        \chmod($staging, 0o600);

        if (!@\rename($staging, $final)) {
            @\unlink($staging);
            throw new RuntimeException('WorkerPool: cannot finalise job file.');
        }

        return $this->submit($final, $payload, $timeoutSeconds);
    }

    /**
     * Block until the job's worker emits its result (or dies) and return it.
     *
     * Reads FIFO from the owning worker, so waiting on a later job also collects
     * (and caches) earlier responses from that same worker. Because every job
     * carries an in-worker deadline, a CPU-bound job always converges (result or
     * fatal→pipe EOF) — this read terminates without a parent-side poll.
     *
     * @return array{ok: bool, result?: mixed, error?: string}
     */
    public function wait(string $id): array
    {
        if (isset($this->responses[$id])) {
            return $this->take($id);
        }

        if (!isset($this->jobs[$id])) {
            throw new InvalidArgumentException('Unknown job id: ' . $id);
        }

        $workerIndex = $this->jobs[$id];

        while (!isset($this->responses[$id])) {
            $worker = $this->workers[$workerIndex] ?? null;

            if ($worker === null || !$worker['alive']) {
                $this->failWorkerJobs($workerIndex, 'worker died before completion');

                break;
            }

            if (!$this->pumpOne($workerIndex)) {
                // pipe closed / process gone: reap this worker's outstanding jobs
                $this->killWorker($workerIndex);
                $this->failWorkerJobs($workerIndex, 'worker died before completion');

                break;
            }
        }

        return $this->take($id) ?? ['ok' => false, 'error' => 'worker died before completion'];
    }

    /**
     * Pool counters.
     *
     * @return array{size: int, alive: int, inflight: int}
     */
    public function stats(): array
    {
        $alive = 0;
        foreach ($this->workers as $worker) {
            if ($worker['alive']) {
                ++$alive;
            }
        }

        return ['size' => $this->size, 'alive' => $alive, 'inflight' => \count($this->jobs)];
    }

    /**
     * Stop all workers (shutdown frame with terminate fallback) and remove every
     * file the pool created (runner + queued job files).
     */
    public function shutdown(): void
    {
        if ($this->shutDown) {
            return;
        }
        $this->shutDown = true;

        foreach ($this->workers as $index => $worker) {
            if (!$worker['alive']) {
                continue;
            }
            @\fwrite($worker['stdin'], "{\"cmd\":\"shutdown\"}\n");
            @\fflush($worker['stdin']);
            @\fclose($worker['stdin']);
            @\fclose($worker['stdout']);
            @\proc_terminate($worker['proc']);
            @\proc_close($worker['proc']);
            $this->workers[$index]['alive'] = false;
        }

        if ($this->runnerPath !== null && \is_file($this->runnerPath)) {
            @\unlink($this->runnerPath);
        }

        foreach (\glob($this->poolDir . DIRECTORY_SEPARATOR . 'jobs' . DIRECTORY_SEPARATOR . '*.php') ?: [] as $jobFile) {
            @\unlink($jobFile);
        }
        @\rmdir($this->poolDir . DIRECTORY_SEPARATOR . 'jobs');
        @\rmdir($this->poolDir);
    }

    // ── Internals ─────────────────────────────────────────────

    /**
     * Pick a worker: fill the pool first (boot any dead/unused slot while
     * alive < size), then round-robin across live workers — their pipe buffers
     * ARE the backlog once the pool is saturated.
     */
    private function assignWorker(): int
    {
        $alive = 0;
        foreach ($this->workers as $worker) {
            if ($worker['alive']) {
                ++$alive;
            }
        }

        if ($alive < $this->size) {
            for ($index = 0; $index < $this->size; ++$index) {
                if (($this->workers[$index]['alive'] ?? false) !== true) {
                    $this->bootWorker($index);
                    $this->nextWorker = ($index + 1) % $this->size;

                    return $index;
                }
            }
        }

        for ($attempt = 0; $attempt < $this->size; ++$attempt) {
            $index = ($this->nextWorker + $attempt) % $this->size;

            if (($this->workers[$index]['alive'] ?? false) === true) {
                $this->nextWorker = ($index + 1) % $this->size;

                return $index;
            }
        }

        throw new RuntimeException('WorkerPool: no worker available.'); // unreachable: boot path above
    }

    private function bootWorker(int $index): void
    {
        if ($this->runnerPath === null) {
            $this->runnerPath = $this->ensureRunner();
        }

        if (!\function_exists('proc_open')) {
            throw new RuntimeException('WorkerPool requires proc_open.');
        }

        // Array-form command (PHP >= 8.0): no cmd.exe wrapper, so proc_terminate
        // targets the php worker DIRECTLY on Windows (a string command runs it as
        // a cmd.exe grandchild whose termination would orphan a wedged worker).
        $pipes = [];
        $proc = \proc_open(
            [$this->phpPath ?? PHP_BINARY, $this->runnerPath],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        if (!\is_resource($proc)) {
            throw new RuntimeException('WorkerPool: failed to boot worker process.');
        }

        // stdout read is BLOCKING; a job's in-worker deadline guarantees the worker
        // converges (emits a line or dies → pipe EOF), so blocking read terminates.
        \fclose($pipes[2]); // runner writes nothing to stderr; drop the pipe

        $this->workers[$index] = ['proc' => $proc, 'stdin' => $pipes[0], 'stdout' => $pipes[1], 'alive' => true, 'buf' => ''];

        if (!$this->shutdownHookRegistered) {
            $this->shutdownHookRegistered = true;
            \register_shutdown_function(function (): void {
                try {
                    $this->shutdown();
                } catch (Throwable) {
                }
            });
        }
    }

    private function ensureRunner(): string
    {
        if (!\is_dir($this->poolDir) && !@\mkdir($this->poolDir, 0o700, true) && !\is_dir($this->poolDir)) {
            throw new RuntimeException('WorkerPool: cannot create pool dir.');
        }

        $path = $this->poolDir . DIRECTORY_SEPARATOR . 'worker_runner.php';

        if (@\file_put_contents($path, self::RUNNER_SOURCE, LOCK_EX) === false) {
            throw new RuntimeException('WorkerPool: cannot write runner.');
        }
        \chmod($path, 0o600);

        return $path;
    }

    private function writeFrame(int $index, string $frame, string $jobId): void
    {
        $worker = $this->workers[$index];

        if (@\fwrite($worker['stdin'], $frame . "\n") === false || @\fflush($worker['stdin']) === false) {
            $this->killWorker($index);
            $this->responses[$jobId] = ['ok' => false, 'error' => 'worker died before completion'];
            unset($this->jobs[$jobId]);
        }
    }

    /**
     * Read one response line (blocking) and cache it by its job id.
     *
     * @return bool false when the pipe closed / process died before a line
     */
    private function pumpOne(int $index): bool
    {
        $worker = &$this->workers[$index];

        // Serve an already-buffered complete line first (e.g. collected earlier).
        $line = $this->shiftLine($worker['buf']);

        if ($line === null) {
            $line = \fgets($worker['stdout']);

            if ($line === false) {
                unset($worker);

                return false; // EOF: worker exited / died
            }
        }

        unset($worker);

        $line = \trim($line);
        if ($line === '') {
            return true; // tolerate blank noise, keep waiting
        }

        try {
            $frame = \json_decode($line, true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return true; // tolerate stray non-protocol noise
        }

        if (\is_array($frame) && isset($frame['id']) && \is_string($frame['id'])) {
            $this->responses[$frame['id']] = ($frame['ok'] ?? false) === true
                ? ['ok' => true, 'result' => $frame['result'] ?? null]
                : ['ok' => false, 'error' => (string) ($frame['error'] ?? 'unknown worker error')];
        }

        return true;
    }

    /**
     * Pop the first complete line out of a buffer, or null when incomplete.
     */
    private function shiftLine(string &$buf): ?string
    {
        $pos = \strpos($buf, "\n");

        if ($pos === false) {
            return null;
        }

        $line = \substr($buf, 0, $pos);
        $buf = \substr($buf, $pos + 1);

        return $line;
    }

    private function killWorker(int $index): void
    {
        $worker = $this->workers[$index] ?? null;

        if ($worker === null || !$worker['alive']) {
            return;
        }

        @\fclose($worker['stdin']);
        @\fclose($worker['stdout']);
        @\proc_terminate($worker['proc']);
        @\proc_close($worker['proc']);
        $this->workers[$index]['alive'] = false;
    }

    /**
     * Fail every unfinished job owned by a worker (at-most-once: no re-run).
     */
    private function failWorkerJobs(int $index, string $reason): void
    {
        foreach ($this->jobs as $id => $ownerIndex) {
            if ($ownerIndex === $index && !isset($this->responses[$id])) {
                $this->responses[$id] = ['ok' => false, 'error' => $reason];
            }
        }
    }

    /**
     * Pop a stored response, freeing its in-flight slot.
     */
    private function take(string $id): ?array
    {
        if (!isset($this->responses[$id])) {
            return null;
        }

        $result = $this->responses[$id];
        unset($this->responses[$id], $this->jobs[$id]);

        return $result;
    }
}
