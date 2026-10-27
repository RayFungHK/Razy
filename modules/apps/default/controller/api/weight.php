<?php

/**
 * razymod/apps — public API command 'weight'.
 *
 * Validates first: an invalid definition gives ['ok' => false, 'issues' => …]
 * and is never weighed. Coefficients come from the module config 'weight'
 * (the rate card), or the reference values (Weight::REFERENCE) when none
 * are configured.
 * → ['ok' => true, 'issues', 'weight', 'wf_nodes', 'counts', 'coefficients', 'coefficients_source' => 'config'|'reference']
 */

return function (array $definition, array $options = []): array {
    /** @var Razy\Module\apps\AppsController $this bound by ClosureLoader at execution */
    return $this->weightOf($definition, $options);
};
