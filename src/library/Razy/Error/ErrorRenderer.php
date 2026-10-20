<?php

/**
 * This file is part of Razy.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Razy\Error;

use Razy\Error;
use Razy\Exception\NotFoundException;
use Razy\Exception\QueryException;
use Razy\Http\SecurityHeaders;
use Razy\Template;
use Razy\Template\Source;
use Razy\Terminal;
use Razy\Util\PathUtil;
use Throwable;

/**
 * Handles rendering of exception pages and error output.
 *
 * Extracted from the Error class to separate rendering/display logic
 * from exception semantics. Supports HTML template-based exception pages
 * in web mode and plain text output in CLI mode.
 *
 *
 * @license MIT
 */
class ErrorRenderer
{
    /**
     * Display 404 Not Found error page.
     *
     * @throws NotFoundException
     */
    public static function show404(): void
    {
        // Only discard a buffer when one exists. Worker-mode dispatch runs
        // with NO output buffer (main.php drains it per request), and the
        // naked ob_clean() raised a PHP notice there — the notice itself is
        // output, so headers counted as "already sent" and the 404 status
        // header below silently failed: live-caught 2026-09 on the benchmark
        // gate site, where curl reported `[200]` around a 404 body. Every
        // crawler, monitor, and CDN cache in front of a worker deploy was
        // being served wrong status codes.
        if (\ob_get_level() > 0) {
            \ob_clean();
        }

        // Replace form (true, 404) for the same reason as showException's
        // status header below: a long-lived worker process has already
        // "chosen" 200 for this response, and PHP 8.5 warns on late bare
        // status assignments — the replace form is the 2026-09 house style.
        \header('HTTP/1.1 404 Not Found', true, 404);

        if (WEB_MODE) {
            // RZ-10: the dist's security headers (or the baseline) on error pages too.
            SecurityHeaders::sendCurrent();
            echo '<h1>404 Not Found</h1>';
            echo 'The requested URL was not found on this server.';
        } else {
            Terminal::WriteLine('{@c:red}404 Not Found', true);
            Terminal::WriteLine('The requested URL was not found on this server', true);
        }

        throw new NotFoundException();
    }

    /**
     * Render the custom exception page for the given exception.
     *
     * In web mode the exception is reported first (Error::report(): full details
     * go to the server log under a fresh error id), then a page is rendered:
     * in production it shows the heading and the error id only; in debug mode
     * it adds the escaped message, file, line, SQL, console and stack trace.
     * In CLI mode, outputs the exception as plain text.
     *
     * @param Throwable $exception The exception to display
     *
     * @throws Throwable
     */
    public static function showException(Throwable $exception): void
    {
        if (WEB_MODE) {
            $errorId = ErrorReporter::report($exception);
            $source = self::buildPage($exception, $errorId);

            // Capture any buffered output before replacing with the error page
            if (\ob_get_level() > 0) {
                ErrorConfig::setCached(\ob_get_contents());
                \ob_clean();
            }

            // Set the HTTP status BEFORE emitting the page. Once the first
            // byte is out, header() is a no-op that merely warns — in worker
            // mode (no buffer) the old echo-first order fired exactly that
            // warning on this line all through the 2026-09 gate-site boot
            // logs, and the error page shipped under the previous
            // response's status.
            // header()-replace form: same wire result, and unlike
            // http_response_code() it never trips PHP 8.5's new late-status
            // warning when the (CLI/test) process already chose one (2026-09).
            \header('HTTP/1.1 ' . (\is_numeric($exception->getCode()) ? $exception->getCode() : 400), true, \is_numeric($exception->getCode()) ? (int) $exception->getCode() : 400);
            \header('X-Error-Id: ' . $errorId);
            SecurityHeaders::sendCurrent();

            echo $source->output();
        } else {
            // CLI is operator-facing: print the exception and, for a failed
            // query, the SQL that QueryException keeps out of its message.
            echo $exception;
            if ($exception instanceof QueryException && null !== $exception->getSql()) {
                echo PHP_EOL . 'SQL: ' . $exception->getSql() . PHP_EOL;
            }
        }
    }

    /**
     * Render the exception page HTML without sending it.
     *
     * @param Throwable $exception The exception to render
     * @param string $errorId The id from Error::report(), shown to the visitor
     *
     * @return string
     */
    public static function render(Throwable $exception, string $errorId): string
    {
        return self::buildPage($exception, $errorId)->output();
    }

    /**
     * Build the exception page template. Every value is HTML-escaped here; the
     * template prints them raw.
     *
     * @param Throwable $exception
     * @param string $errorId
     *
     * @return Source
     */
    private static function buildPage(Throwable $exception, string $errorId): Source
    {
        // Resolve the exception template: use status-code-specific file if available, fallback to generic
        $tplFolder = PathUtil::append(PHAR_PATH, 'asset', 'exception');
        if (\is_file(PathUtil::append($tplFolder, $exception->getCode() . '.html'))) {
            $tplFile = PathUtil::append($tplFolder, $exception->getCode() . '.html');
        } else {
            $tplFile = PathUtil::append($tplFolder, 'any.html');
        }

        $template = new Template();
        $source = $template->load($tplFile);
        $root = $source->getRoot();

        $root->assign([
            'heading' => self::escape(($exception instanceof Error) ? $exception->getHeading() : Error::DEFAULT_HEADING),
            'error_id' => self::escape($errorId),
            // RZ-10: the nonce the dist's CSP allows, for the page's inline style/script.
            'csp_nonce' => SecurityHeaders::current()->nonce(),
        ]);

        if (!ErrorConfig::isDebug()) {
            return $source;
        }

        // Debug information: message, location, SQL, debug message, console output, stack trace
        $debugBlock = $root->newBlock('debug');
        $debugBlock->assign([
            // Razy\Error pre-escapes (and nl2br()s) its message; everything else is escaped here
            'message' => ($exception instanceof Error) ? $exception->getMessage() : \nl2br(self::escape($exception->getMessage())),
            'file' => self::escape($exception->getFile()),
            'line' => $exception->getLine(),
        ]);
        if ($exception instanceof Error && $debugMessage = $exception->getDebugMessage()) {
            $debugBlock->assign([
                'debug_message' => self::escape($debugMessage),
            ]);
        }
        if ($exception instanceof QueryException && null !== $exception->getSql()) {
            $debugBlock->newBlock('sql')->assign([
                'sql' => self::escape($exception->getSql()),
            ]);
        }
        $debugConsole = ErrorConfig::getDebugConsole();
        if (\count($debugConsole)) {
            $debugBlock->newBlock('console')->assign([
                'console' => self::escape(\implode("\n", $debugConsole)),
            ]);
        }

        // Parse the stack trace string into individual frames for template rendering
        $stacktrace = \explode("\n", $exception->getTraceAsString());
        \array_pop($stacktrace);

        $index = 0;
        foreach ($stacktrace as $trace) {
            // Extract trace detail after the "#N " prefix
            \preg_match('/^#\d+ (.+)$/', $trace, $matches);
            $debugBlock->newBlock('backtrace')->assign([
                'index' => $index++,
                'stack' => self::escape($matches[1] ?? $trace),
            ]);
        }

        return $source;
    }

    /**
     * HTML-escape a value for the exception page.
     *
     * @param string $value
     *
     * @return string
     */
    private static function escape(string $value): string
    {
        return \htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
