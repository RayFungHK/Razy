<?php

/**
 * razymod/apps — public API command 'compile'.
 *
 * Validates first; a definition with errors gives ['ok' => false, 'plan' => null].
 * → ['ok' => bool, 'plan' => ?array, 'issues' => [...]]
 */

return function (array $definition, array $options = []): array {
    /** @var Razy\Module\apps\AppsController $this bound by ClosureLoader at execution */
    return $this->compileDefinition($definition, $options);
};
