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

use Razy\Contract\Log\LoggerInterface;
use Razy\Exception\QueryException;
use Stringable;
use Throwable;

/**
 * Reports exceptions to the server-side log and hands out an error id.
 *
 * The id is the only thing a production error page shows, so operators can
 * find the full record (message, file, line, SQL, trace) in the log while
 * visitors learn nothing about the internals (RZ-01).
 *
 * With no logger configured the record goes to PHP's error_log().
 *
 * @license MIT
 */
class ErrorReporter
{
    /**
     * @var LoggerInterface|null Logger that receives reports; null = error_log().
     *                           Process-wide configuration: Error::reset() (the
     *                           per-request worker cleanup) does not clear it.
     *                           Register a process-wide logger at boot; do not
     *                           register request- or tenant-scoped loggers here.
     */
    private static ?LoggerInterface $logger = null;

    /**
     * Set the logger that receives error reports. Pass null to fall back to error_log().
     *
     * @param LoggerInterface|null $logger
     */
    public static function setLogger(?LoggerInterface $logger): void
    {
        self::$logger = $logger;
    }

    /**
     * Get the configured logger, if any.
     *
     * @return LoggerInterface|null
     */
    public static function getLogger(): ?LoggerInterface
    {
        return self::$logger;
    }

    /**
     * Create a new random error id (16 lowercase hex characters).
     *
     * @return string
     */
    public static function newErrorId(): string
    {
        return \bin2hex(\random_bytes(8));
    }

    /**
     * Log the exception with a fresh error id and return that id.
     *
     * @param Throwable $exception The exception to report
     * @param array<string, mixed> $context Extra context (route, user id, ...)
     * @param string|null $errorId Use this id instead of generating one
     *
     * @return string The error id written to the log
     */
    public static function report(Throwable $exception, array $context = [], ?string $errorId = null): string
    {
        $errorId ??= self::newErrorId();
        $message = \sprintf(
            '[%s] %s: %s in %s:%d',
            $errorId,
            $exception::class,
            self::plainMessage($exception),
            $exception->getFile(),
            $exception->getLine(),
        );

        $context['error_id'] = $errorId;
        $context['exception'] = $exception;
        if ($exception instanceof QueryException && null !== $exception->getSql()) {
            $context['sql'] = $exception->getSql();
        }

        $loggerFailure = null;
        if (null !== self::$logger) {
            try {
                self::$logger->error($message, $context);

                return $errorId;
            } catch (Throwable $failure) {
                // A broken logger must never hide the original error: fall through to error_log().
                $loggerFailure = $failure;
            }
        }

        $line = $message;
        if (isset($context['sql'])) {
            $line .= ' | SQL: ' . self::stringify($context['sql']);
        }
        \error_log('[Razy] ' . $line . "\n" . $exception->getTraceAsString());
        if (null !== $loggerFailure) {
            \error_log(\sprintf(
                '[Razy] [%s] configured error logger failed: %s: %s in %s:%d',
                $errorId,
                $loggerFailure::class,
                $loggerFailure->getMessage(),
                $loggerFailure->getFile(),
                $loggerFailure->getLine(),
            ));
        }

        return $errorId;
    }

    /**
     * Log a framework warning through the configured logger (or error_log()).
     *
     * @param string $message
     */
    public static function warn(string $message): void
    {
        if (null !== self::$logger) {
            try {
                self::$logger->warning($message);

                return;
            } catch (Throwable) {
                // fall through to error_log()
            }
        }
        \error_log('[Razy] ' . $message);
    }

    /**
     * The exception message as plain text (Razy\Error pre-escapes and nl2br()s its message).
     *
     * @param Throwable $exception
     *
     * @return string
     */
    public static function plainMessage(Throwable $exception): string
    {
        $message = $exception->getMessage();
        if ($exception instanceof \Razy\Error) {
            $message = \html_entity_decode(\str_replace(['<br />', '<br>'], '', $message), ENT_QUOTES, 'UTF-8');
        }

        return $message;
    }

    /**
     * Text form of an arbitrary context value for the error_log() fallback.
     *
     * @param mixed $value
     *
     * @return string
     */
    private static function stringify(mixed $value): string
    {
        if (\is_scalar($value) || $value instanceof Stringable) {
            return (string) $value;
        }
        $json = \json_encode($value, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return false === $json ? \get_debug_type($value) : $json;
    }
}
