<?php

namespace Razy;

return function (string $moduleCode = '', string $version = '', string $commitAsPhar = '') use (&$parameters) {
    $this->writeLineLogging('{@s:bu}Commit module', true);

    $path         = SYSTEM_ROOT;
    $distCode     = '';
    $commitAsPhar = !!$commitAsPhar;
    if (strpos($moduleCode, '@') !== false) {
        [$distCode, $moduleCode] = explode('@', $moduleCode, 2);
    }

    if (!preg_match('/^[a-z0-9]([_.-]?[a-z0-9]+)*\/[a-z0-9](([_.]?|-{0,2})[a-z0-9]+)*$/i', $moduleCode)) {
        $this->writeLineLogging('{@c:red}The module code ' . $moduleCode . ' is not a correct format, it should be `vendor/package`.', true);

        return;
    }

    if (!$distCode) {
        $path = append($path, 'shared', 'module');
    } else {
        $path = append($path, 'sites', $distCode);
    }
    $path = append($path, $moduleCode);

    if (!preg_match('/^(\d+)(?:\.(?:\d+|\*)){0,3}$/', $version)) {
        $this->writeLineLogging('{@c:red}' . $version . ' is not a valid format.', true);
        return;
    }

    /* @var ModuleInfo $module */
    if ($module = new ModuleInfo($path, 'default')) {
        if ($path = $module->commit($version, $commitAsPhar)) {
            $this->writeLineLogging('Module `' . $moduleCode . '` (' . ((!$distCode) ? 'Shared Module' : $distCode) . ') has committed as version ' . $version, true);
            $this->writeLineLogging('Location: ' . $path, true);
        } else {
            $this->writeLineLogging('{@c:red}Failed to commit, the selected version may exist.', true);
        }
    } else {
        $this->writeLineLogging('Module `' . $moduleCode . '` has not found', true);

        return;
    }
};
