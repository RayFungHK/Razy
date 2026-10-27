<?php

/**
 * razymod/apps — public API command 'validate'.
 *
 * api('razymod/apps')->validate($definition, ['dependencies' => [...], 'previous' => [...], 'system' => true])
 * → ['ok' => bool, 'issues' => [['severity', 'path', 'code', 'message'], …]]
 */

return function (array $definition, array $options = []): array {
    /** @var Razy\Module\apps\AppsController $this bound by ClosureLoader at execution */
    return $this->validateDefinition($definition, $options);
};
