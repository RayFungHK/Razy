<?php
/**
 * Console Sub-command: list
 *
 * Lists all modules loaded in the current distributor, showing their
 * status (Enabled/Disabled), version, author, and API code in a
 * formatted table.
 *
 * Usage (inside console shell):
 *   list <distributor_code>
 *
 * @package Razy
 * @license MIT
 */

namespace Razy;

return function (string $distCode) {
    // Display header for module listing
    $this->writeLineLogging('{@s:ub}List modules', true);
    $app = new Application();
    if ($app->hasDistributor($distCode)) {

    }
    // Retrieve module metadata for the given distributor
    $info = Application::GetDistributorModules($distCode);

    // Colour-code the status column: green for Enabled, red otherwise
    foreach ($info as &$module) {
        if ('Enabled' == $module[1]) {
            $module[1] = '{@c:green}' . $module[1];
        } else {
            $module[1] = '{@c:red}' . $module[1];
        }
    }

    // Build and render the modules table
    $table = $this->table();
    $table->setColumns(5, ['{@s:b}Module Code', '{@s:b}Status', '{@s:b}Version', '{@s:b}Author', '{@s:b}API Code'])->bindData($info);
    $table->draw();
};
