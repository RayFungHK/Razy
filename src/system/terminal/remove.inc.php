<?php

namespace Razy;

return function (string $fqdn = '') {
    // Check the parameters is valid
    $fqdn = trim($fqdn);
    if (!$fqdn) {
        $this->writeLine('{@c:red}[ERROR] The FQDN is required.', true);

        exit;
    }

    // Load default config setting
    $config         = Application::LoadSiteConfig();

    // Extract the domain and the path from the FQDN string
    $fqdn            = trim(preg_replace('/[\\\\\/]+/', '/', $fqdn), '/');
    [$domain, $path] = explode('/', $fqdn, 2);
    $path            = '/' . $path;

    // Remove the specified domain and path setting
    unset($config['domains'][$domain][$path]);

    // Remove empty domain sites
    if (empty($config['domains'][$domain] ?? [])) {
        unset($config['domains'][$domain]);
    }

    if (Application::WriteSiteConfig()) {
        $this->writeLine('{@c:green}Done.', true);
    } else {
        $this->writeLine('{@c:red}Failed.', true);
    }

    Application::UpdateSites();
    $message = 'Updating rewrite rules... ';
    if (Application::UpdateRewriteRules()) {
        $message .= $this->format('{@c:green}Done.');
    } else {
        $message .= $this->format('{@c:red}Failed.');
    }
    $this->writeLine($message, true);
};
