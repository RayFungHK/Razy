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

use RuntimeException;

/**
 * Thrown when a module or user code attempts to access a protected
 * system resource (e.g., resolve a blocked internal class from the
 * DI container, traverse the container hierarchy, or call a
 * worker-only method outside worker mode).
 *
 * @class SecurityException
 */
class SecurityException extends RuntimeException
{
}
