<?php

/**
 * razymod/apps — a Scope from a flat map of dotted paths.
 *
 *   new MapScope(['amount' => ['type' => 'decimal', 'scale' => 2], 'stage' => ['type' => 'status', 'options' => ['open', 'won']]])
 */

declare(strict_types=1);

namespace razymod\apps\Expression;

final class MapScope implements Scope
{
    /** @var array<string, array{type: string, options: ?list<string>, sql: bool, scale?: int}> */
    private array $fields = [];

    /**
     * @param array<string, string|array{type: string, options?: ?list<string>, sql?: bool, scale?: int}> $fields
     */
    public function __construct(array $fields)
    {
        foreach ($fields as $path => $spec) {
            $spec = \is_string($spec) ? ['type' => $spec] : $spec;
            $this->fields[$path] = [
                'type' => $spec['type'],
                'options' => $spec['options'] ?? null,
                'sql' => $spec['sql'] ?? true,
            ];
            if (isset($spec['scale'])) {
                $this->fields[$path]['scale'] = (int) $spec['scale'];
            }
        }
    }

    public function resolve(array $path, int $at): array
    {
        $key = \implode('.', $path);

        return $this->fields[$key]
            ?? throw new ExpressionError(ExpressionError::UNKNOWN_FIELD, "Unknown field '{$key}'", $at);
    }
}
