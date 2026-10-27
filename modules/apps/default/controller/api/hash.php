<?php

/**
 * razymod/apps — public API command 'hash'.
 *
 * Content hash over canonical JSON (razymod\apps\Definition\Canonical).
 * → ['ok' => bool, 'hash' => 'sha256:…'|null, 'bytes' => int, 'error'?: string]
 */

return function (array $definition): array {
    /** @var Razy\Module\apps\AppsController $this bound by ClosureLoader at execution */
    return $this->hashDefinition($definition);
};
