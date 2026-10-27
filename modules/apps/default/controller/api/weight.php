<?php

/**
 * razymod/apps — public API command 'weight'.
 *
 * Coefficients come from the module config 'weight' (the rate card), or the
 * reference values (Weight::REFERENCE) when none are configured.
 * → ['weight', 'wf_nodes', 'counts', 'coefficients', 'coefficients_source' => 'config'|'reference']
 */

return function (array $definition): array {
    /** @var Razy\Module\apps\AppsController $this bound by ClosureLoader at execution */
    return $this->weightOf($definition);
};
