<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * PSR-3 compatible logger interface.
 * Fulfills the PSR-3 specification without requiring psr/log.
 *
 * @package Razy
 *
 * @license MIT
 *
 * @see https://www.php-fig.org/psr/psr-3/
 */

namespace Razy\Contract\Log;

use Stringable;

/**
 * Describes a logger instance.
 *
 * The message MUST be a string or object implementing __toString().
 *
 * The message MAY contain placeholders in the form: {foo} where foo
 * will be replaced by the context data in key "foo".
 *
 * The context array can contain arbitrary data. The only assumption that
 * can be made by implementors is that if an Exception instance is given
 * to produce a stack trace, it MUST be in a key named "exception".
 */
interface LoggerInterface
{
    /**
     * System is unusable.
     *
     * @param mixed[] $context
     */
    public function emergency(string|Stringable $message, array $context = []): void;

    /**
     * Action must be taken immediately.
     *
     * @param mixed[] $context
     */
    public function alert(string|Stringable $message, array $context = []): void;

    /**
     * Critical conditions.
     *
     * @param mixed[] $context
     */
    public function critical(string|Stringable $message, array $context = []): void;

    /**
     * Runtime errors that do not require immediate action but should typically
     * be logged and monitored.
     *
     * @param mixed[] $context
     */
    public function error(string|Stringable $message, array $context = []): void;

    /**
     * Exceptional occurrences that are not errors.
     *
     * @param mixed[] $context
     */
    public function warning(string|Stringable $message, array $context = []): void;

    /**
     * Normal but significant events.
     *
     * @param mixed[] $context
     */
    public function notice(string|Stringable $message, array $context = []): void;

    /**
     * Interesting events.
     *
     * @param mixed[] $context
     */
    public function info(string|Stringable $message, array $context = []): void;

    /**
     * Detailed debug information.
     *
     * @param mixed[] $context
     */
    public function debug(string|Stringable $message, array $context = []): void;

    /**
     * Logs with an arbitrary level.
     *
     * @param mixed $level
     * @param mixed[] $context
     *
     * @throws InvalidArgumentException
     */
    public function log(mixed $level, string|Stringable $message, array $context = []): void;
}
