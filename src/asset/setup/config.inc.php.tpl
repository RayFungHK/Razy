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
    // RZ-14: framework log output, 'text' (PHP error_log) or 'json' (one JSON
    // object per line on stderr). RAZY_LOG_FORMAT / RAZY_LOG_LEVEL override these.
    'log_format' => 'text',
    'log_level' => 'info',
    // RZ-14: accept a client's X-Request-ID: 'any', 'trusted_proxy' or 'none'
    // (always generate). RAZY_REQUEST_ID_ACCEPT overrides this.
    'request_id_accept' => 'any',
];