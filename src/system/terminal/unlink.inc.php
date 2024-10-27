<?php

namespace Razy;

return function (string $alias = '') {
    $this->writeLineLogging('{@s:bu}Remove an alias', true);
    $this->writeLineLogging('Removing an alias {@s:u}' . $alias . '{@reset}...', true);

    // Check the parameters is valid
    $alias = trim($alias);
    if (!$alias || !is_fqdn($alias)) {
        $this->writeLineLogging('{@c:r}[ERROR] The alias is required or the format is invalid.', true);

        exit;
    }

    // Load default config setting
    $config = Application::LoadSiteConfig();
    unset($config['alias'][$alias]);

    $message = 'Writing file sites.inc.php... ';
    if (Application::WriteSiteConfig($config)) {
        $message .= $this->format('{@c:green}Done.');
    } else {
        $message .= $this->format('{@c:red}Failed.');
    }
    $this->writeLineLogging($message, true);

    Application::UpdateSites();
    $message = 'Updating rewrite rules... ';
    if (Application::UpdateRewriteRules()) {
        $message .= $this->format('{@c:green}Done.');
    } else {
        $message .= $this->format('{@c:red}Failed.');
    }
    $this->writeLineLogging($message, true);
};
