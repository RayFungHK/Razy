<?php

namespace Razy;

return function (string $alias = '', string $domain = '') {
    $this->writeLine('{@s:bu}Adding or update an alias', true);
    $this->writeLine('Creating an alias {@s:u}' . $alias . '{@reset} linking to {@s:u}' . $domain . '{@reset}...', true);

    // Check the parameters is valid
    $alias = trim($alias);
    if (!$alias || !is_fqdn($alias)) {
        $this->writeLine('{@c:r}[ERROR] The alias is required or the format is invalid.', true);

        exit;
    }

    $domain = trim($domain);
    if (!$domain || !is_fqdn($domain)) {
        $this->writeLine('{@c:r}[ERROR] The domain is required or the format is invalid.', true);

        exit;
    }

    // Load default config setting
    $configFilePath = append(RAZY_PATH, 'sites.inc.php');
    $config         = loadSiteConfig($configFilePath);

    $config['alias'][$alias] = $domain;

    $message = 'Writing file sites.inc.php... ';
    if (writeSiteConfig($configFilePath, $config)) {
        $message .= $this->format('{@c:green}Done.');
    } else {
        $message .= $this->format('{@c:red}Failed.');
    }
    $this->writeLine($message, true);

    $rewriteFilePath = append(RAZY_PATH, '.htaccess');
    Application::UpdateSites();
    $message = 'Updating rewrite rules... ';
    if (updateRewriteRules($rewriteFilePath)) {
        $message .= $this->format('{@c:green}Done.');
    } else {
        $message .= $this->format('{@c:red}Failed.');
    }
    $this->writeLine($message, true);
};
