<?php
/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * PSR-3 compatible InvalidArgumentException.
 * Fulfills the PSR-3 specification without requiring psr/log.
 *
 * @package Razy
 * @license MIT
 * @see https://www.php-fig.org/psr/psr-3/
 */

namespace Razy\Contract\Log;

class InvalidArgumentException extends \InvalidArgumentException
{
}
