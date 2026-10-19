<?php
return [
    'debug' => false,
    'multiple_site' => false,
	'phar_location' => '{$install_path}',
    'timezone' => '{$timezone}',
    // Reverse proxies (IPs / CIDR blocks, IPv4 or IPv6) whose X-Forwarded-Proto,
    // -Host, -Port and -For headers are believed. Empty = trust none.
    // The RAZY_TRUSTED_PROXIES environment variable overrides this list.
    'trusted_proxies' => [],
];