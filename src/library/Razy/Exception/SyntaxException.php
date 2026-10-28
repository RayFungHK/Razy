<?php

/**
 * This file is part of Razy.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 *
 * @license MIT
 */

declare(strict_types=1);

namespace Razy\Exception;

use InvalidArgumentException;

/**
 * Thrown by SimpleSyntax when a syntax string is malformed: a stray `)` or an
 * unclosed `(` (RZ-47). Before, a stray `)` silently dropped the whole
 * expression and an unclosed `(` was read as if closed. Callers in the query
 * builder turn it into a QueryException.
 */
class SyntaxException extends InvalidArgumentException
{
}
