<?php

/**
 * razymod/apps — public API command 'assign-ids'.
 *
 * Fills missing element ids; an element matching one of $previous (same
 * kind, parent and code) keeps that id. Existing ids are never changed.
 * → the definition with ids
 */

return function (array $definition, ?array $previous = null): array {
    /** @var Razy\Module\apps\AppsController $this bound by ClosureLoader at execution */
    return $this->assignIds($definition, $previous);
};
