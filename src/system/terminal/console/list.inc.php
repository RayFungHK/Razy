<?php

namespace Razy;

return function (string $distCode) {
    // TODO: In development
    $this->writeLineLogging('{@s:ub}List modules', true);
    $app = new Application();
    if ($app->hasDistributor($distCode)) {

    }
    $info = Application::GetDistributorModules($distCode);

    foreach ($info as &$module) {
        if ('Enabled' == $module[1]) {
            $module[1] = '{@c:green}' . $module[1];
        } else {
            $module[1] = '{@c:red}' . $module[1];
        }
    }

    $table = $this->table();
    $table->setColumns(5, ['{@s:b}Module Code', '{@s:b}Status', '{@s:b}Version', '{@s:b}Author', '{@s:b}API Code'])->bindData($info);
    $table->draw();
};
