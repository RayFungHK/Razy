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

use Razy\Contract\Container\NotFoundExceptionInterface;

/**
 * Exception thrown when a requested entry is not found in the container.
 *
 * Implements PSR-11 NotFoundExceptionInterface for interoperability.
 */
class ContainerNotFoundException extends ContainerException implements NotFoundExceptionInterface
{
}
