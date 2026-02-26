<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * Interface for type-specific SQL syntax builders (Strategy Pattern).
 *
 *
 * @license MIT
 */

namespace Razy\Database\Statement;

use Razy\Database\Statement;

/**
 * Contract for SQL syntax builder strategies.
 *
 * Each implementation handles the SQL generation for a specific statement type
 * (SELECT, INSERT, UPDATE, DELETE).
 */
interface SyntaxBuilderInterface
{
    /**
     * Build the SQL syntax string for this statement type.
     *
     * @param Statement $statement The statement instance providing data and context
     *
     * @return string The generated SQL string
     */
    public function buildSyntax(Statement $statement): string;
}
