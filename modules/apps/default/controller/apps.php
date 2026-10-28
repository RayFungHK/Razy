<?php

/**
 * razymod/apps — main controller.
 *
 * The behaviour lives in a named class (support/controller.php) so the api/
 * handler closures, bound to this controller at execution, can annotate their
 * $this and the module can be analysed. The file itself returns an ANONYMOUS
 * subclass: Module::buildController() accepts only that (docs/STYLE.md §4,
 * the one controller-file form), and a named instance stopped the module from
 * booting on a real distributor (RZ-48).
 *
 * Config keys (per distributor, config/<dist>/apps.php):
 *   - 'caps'   : narrowing overrides for razymod\apps\Caps (a value above the
 *                engine default is ignored)
 *   - 'weight' : the rate card's weight coefficients (entity, field,
 *                relation, view, saved_view, row_rule_role); absent = the
 *                published reference values (Weight::REFERENCE), reported as such
 */

namespace Razy\Module\apps;

require_once __DIR__ . '/support/controller.php';

return new class() extends AppsController {
};
