<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * PSR-11 compatible container exception interface.
 * Fulfills the PSR-11 specification without requiring psr/container.
 *
 *
 * @license MIT
 *
 * @see https://www.php-fig.org/psr/psr-11/
 */

namespace Razy\Contract\Container;

use Throwable;

/**
 * Base interface representing a generic exception in a container.
 */
interface ContainerExceptionInterface extends Throwable
{
}
