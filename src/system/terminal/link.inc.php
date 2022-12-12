<?php

namespace Razy;

return function (string $alias = '', string $domain = '') {
    $this->writeLineLogging('{@s:bu}Adding or update an alias', true);
    $this->writeLineLogging('Creating an alias {@s:u}' . $alias . '{@reset} linking to {@s:u}' . $domain . '{@reset}...', true);

    // Check the parameters is valid
    $alias = trim($alias);
    if (!$alias || !is_fqdn($alias)) {
        $this->writeLineLogging('{@c:r}[ERROR] The alias is required or the format is invalid.', true);

        exit;
    }

    $domain = trim($domain);
    if (!$domain || !is_fqdn($domain)) {
        $this->writeLineLogging('{@c:r}[ERROR] The domain is required or the format is invalid.', true);

        exit;
    }

    // Load default config setting
    $config                  = Application::LoadSiteConfig();
    $config['alias'][$alias] = $domain;

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
