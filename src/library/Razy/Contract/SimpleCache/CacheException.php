<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * PSR-16 compatible cache exception interface.
 * Fulfills the PSR-16 specification without requiring psr/simple-cache.
 *
 *
 * @license MIT
 *
 * @see https://www.php-fig.org/psr/psr-16/
 */

namespace Razy\Contract\SimpleCache;

use Throwable;

/**
 * Interface used for all types of exceptions thrown by the implementing library.
 */
interface CacheException extends Throwable
{
}
