<?php

/**
 * This file is part of Razy.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * @license MIT
 */

declare(strict_types=1);

namespace Razy\Rzx\Runtime;

use Razy\Exception\TemplateException;
use Throwable;

/**
 * A `.rzx` render-time error, located at the `.rzx` file and line that
 * produced it (mapped back from the compiled PHP).
 */
class RenderError extends TemplateException
{
    public function __construct(string $message, public readonly string $rzxLocation = '', ?Throwable $previous = null)
    {
        parent::__construct($rzxLocation === '' ? $message : $message . "\n  at " . $rzxLocation, 0, $previous);
    }
}
