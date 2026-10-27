<?php

/**
 * razymod/apps — public API command 'assign-ids'.
 *
 * Fills missing element ids; an element matching one of $previous (same
 * kind, parent and code) gets that id back. An id that moved to another
 * kind, parent or element is dropped and re-assigned.
 * → ['ok' => true, 'definition' => …, 'issues' => []] or ['ok' => false, 'issues' => …] (input over its cap)
 */

return function (array $definition, ?array $previous = null): array {
    /** @var Razy\Module\apps\AppsController $this bound by ClosureLoader at execution */
    return $this->assignIds($definition, $previous);
};
