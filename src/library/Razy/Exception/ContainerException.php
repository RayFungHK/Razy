<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Razy\Exception;

use Razy\Contract\Container\ContainerExceptionInterface;
use RuntimeException;

/**
 * General container exception for the Razy DI container.
 *
 * Implements PSR-11 ContainerExceptionInterface for interoperability.
 */
class ContainerException extends RuntimeException implements ContainerExceptionInterface
{
}
