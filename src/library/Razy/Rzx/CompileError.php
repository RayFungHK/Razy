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

namespace Razy\Rzx;

use Razy\Exception\TemplateException;

/**
 * A `.rzx` compile error: stable code, file:line:col, and a code frame.
 *
 * The message reads
 *
 *     RZX012 Expected </rui-dialog> to close <rui-dialog> opened at 3:1, found </div>
 *       at FormDialog.rzx:9:3
 *       8 |   ...
 *     > 9 | </div>
 *         |   ^
 *
 * Codes RZX0nn are lexer / parser errors (RZ-28a); later stages use higher ranges.
 */
class CompileError extends TemplateException
{
    public readonly int $sourceLine;

    public readonly int $sourceColumn;

    public function __construct(
        public readonly string $rzxCode,
        public readonly string $reason,
        public readonly Source $source,
        public readonly int $offset,
    ) {
        [$this->sourceLine, $this->sourceColumn] = $source->position($offset);
        parent::__construct(\sprintf(
            "%s %s\n  at %s:%d:%d\n%s",
            $rzxCode,
            $reason,
            $source->file,
            $this->sourceLine,
            $this->sourceColumn,
            $source->frame($offset),
        ));
    }

    public function location(): string
    {
        return $this->source->file . ':' . $this->sourceLine . ':' . $this->sourceColumn;
    }
}
