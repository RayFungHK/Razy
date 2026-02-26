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
 * Exception thrown when a model cannot be found by its primary key.
 *
 * Typically raised by Model::findOrFail() when no matching record exists.
 */
class ModelNotFoundException extends RuntimeException
{
    /** @var string The model class that was not found */
    private string $model = '';

    /** @var array The ID(s) that were searched */
    private array $ids = [];

    public function __construct(string $message = 'Model not found.', int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Set the affected model class and IDs.
     *
     * @return static
     */
    public function setModel(string $model, array $ids = []): static
    {
        $this->model = $model;
        $this->ids = $ids;
        $this->message = "No query results for model [{$model}]" . ($ids ? ' ' . \implode(', ', $ids) : '') . '.';

        return $this;
    }

    /**
     * Get the model class name.
     */
    public function getModel(): string
    {
        return $this->model;
    }

    /**
     * Get the searched IDs.
     */
    public function getIds(): array
    {
        return $this->ids;
    }
}
