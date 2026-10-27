<?php

/**
 * razymod/apps — main controller.
 *
 * The class is named (support/controller.php) so the api/ handler closures,
 * bound to this controller at execution, can annotate their $this and the
 * module can be analysed (the razymod/permissions shape).
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

return new AppsController();
