<?php
/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * PSR-16 compatible invalid argument exception interface.
 * Fulfills the PSR-16 specification without requiring psr/simple-cache.
 *
 * @package Razy
 * @license MIT
 * @see https://www.php-fig.org/psr/psr-16/
 */

namespace Razy\Contract\SimpleCache;

/**
 * Exception interface for invalid cache arguments.
 *
 * When an invalid argument is passed, it must throw an exception which
 * implements this interface.
 */
interface InvalidArgumentException extends CacheException
{
}
