<?php
/**
 * This file is part of Razy v0.5.
 *
 * PSR-16 InvalidArgumentException for invalid cache keys.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * @package Razy
 * @license MIT
 */

namespace Razy\Cache;

/**
 * Exception thrown when a cache key contains invalid characters or is otherwise malformed.
 *
 * @class InvalidArgumentException
 */
class InvalidArgumentException extends \InvalidArgumentException
{
}
