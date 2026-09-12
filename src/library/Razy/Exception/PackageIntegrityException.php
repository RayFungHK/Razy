<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 *
 * @license MIT
 */

namespace Razy\Exception;

use RuntimeException;
use Throwable;

/**
 * Exception thrown when a downloaded pack/phar artifact fails integrity or
 * transport-policy verification: sha256 mismatch, a checksum claim that is
 * missing while asserted, a malformed digest, or an insecure (plain-HTTP)
 * distribution URL without the operator opt-in.
 *
 * Fail-closed by contract: callers must abort the install before extraction
 * and must not leave partial artifacts behind (see Razy\PackageVerifier).
 */
class PackageIntegrityException extends RuntimeException
{
    public function __construct(string $message = 'Package integrity verification failed.', int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
