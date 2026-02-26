RewriteEngine on

# Detect base directory for subdirectory installs
RewriteCond $0#%{REQUEST_URI} ^([^#]*)#(.*)\1$
RewriteRule ^.*$ - [E=BASE:%2]

# Rewrite the shared module location
RewriteRule ^\w+/shared/(.*)$ shared/$1 [L]

# ── Domain Detection ──
<!-- START BLOCK: domain -->
RewriteCond %{HTTP_HOST} ^{$domain_pattern}$ [NC]
RewriteRule ^ - [E=RAZY_DOMAIN:{$domain}]
<!-- END BLOCK: domain -->
<!-- START BLOCK: alias -->
RewriteCond %{HTTP_HOST} ^{$alias_pattern}$ [NC]
RewriteRule ^ - [E=RAZY_DOMAIN:{$domain}]
<!-- END BLOCK: alias -->
<!-- START BLOCK: wildcard -->
RewriteCond %{ENV:RAZY_DOMAIN} ^$
RewriteRule ^ - [E=RAZY_DOMAIN:*]
<!-- END BLOCK: wildcard -->

# ── Distributor Rewrite Rules ──
<!-- START BLOCK: rewrite -->
# [{$domain}] {$dist_code} (/{$route_path})
    <!-- START BLOCK: webassets -->
    RewriteCond %{ENV:RAZY_DOMAIN} ={$domain}
    RewriteRule ^{$route_path}webassets/{$mapping}/(.+?)/(.+)$ {$dist_path} [END]
    <!-- END BLOCK: webassets -->
    <!-- START BLOCK: data_mapping -->
    RewriteCond %{ENV:RAZY_DOMAIN} ={$domain}
    RewriteRule ^{$route_path}data/(.+)$ {$data_path} [L]
    <!-- END BLOCK: data_mapping -->
    <!-- START BLOCK: fallback -->
    RewriteCond %{ENV:RAZY_DOMAIN} ={$domain}
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteCond %{REQUEST_FILENAME} !-l
    RewriteCond $1 !^(index\.php|robots\.txt|sites|system|shared|plugins|library|asset|repository\.inc\.php|config\.inc\.php|sites\.inc\.php)
    RewriteRule ^{$route_path}(.*)$ %{ENV:BASE}index.php [L]
    <!-- END BLOCK: fallback -->
<!-- END BLOCK: rewrite -->