<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * PSR-11 compatible container not-found exception interface.
 * Fulfills the PSR-11 specification without requiring psr/container.
 *
 * @package Razy
 *
 * @license MIT
 *
 * @see https://www.php-fig.org/psr/psr-11/
 */

namespace Razy\Contract\Container;

/**
 * No entry was found in the container.
 */
interface NotFoundExceptionInterface extends ContainerExceptionInterface
{
}
