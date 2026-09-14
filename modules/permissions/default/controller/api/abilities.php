<?php

/**
 * razymod/permissions — public API command 'abilities' (catalog read for UIs).
 */

return function (): array {
    /** @var Razy\Module\permissions\PermissionController $this bound by ClosureLoader at execution */
    return ['ok' => true, 'abilities' => $this->service()->abilities()];
};
