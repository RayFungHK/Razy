<?php

namespace Razy;

return function (string $alias = '') {
    $this->writeLine('{@s:bu}Remove an alias', true);
    $this->writeLine('Removing an alias {@s:u}' . $alias . '{@reset}...', true);

    // Check the parameters is valid
    $alias = trim($alias);
    if (!$alias || !is_fqdn($alias)) {
        $this->writeLine('{@c:r}[ERROR] The alias is required or the format is invalid.', true);

        exit;
    }

    // Load default config setting
    $config = Application::LoadSiteConfig();
    unset($config['alias'][$alias]);

    $message = 'Writing file sites.inc.php... ';
    if (Application::WriteSiteConfig()) {
        $message .= $this->format('{@c:green}Done.');
    } else {
        $message .= $this->format('{@c:red}Failed.');
    }
    $this->writeLine($message, true);

    Application::UpdateSites();
    $message = 'Updating rewrite rules... ';
    if (Application::UpdateRewriteRules()) {
        $message .= $this->format('{@c:green}Done.');
    } else {
        $message .= $this->format('{@c:red}Failed.');
    }
    $this->writeLine($message, true);
};
