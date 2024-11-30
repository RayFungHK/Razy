RewriteEngine on

# Rewrite the shared module location
RewriteRule ^\w+/shared/(.*)$ shared/$1 [END]

# Rewrite the distributor asset location
<!-- START BLOCK: domain -->
<If "%{HTTP_HOST} =~ /{$domain}/">
    RewriteCond %{REQUEST_FILENAME} -f [OR]
    RewriteCond %{REQUEST_FILENAME} -d [OR]
    RewriteCond %{REQUEST_FILENAME} -l
    RewriteRule ^ - [L]

    # Webassets directory location
    <!-- START BLOCK: webassets -->
    RewriteRule ^{$system_root}/{$route_path}webassets/{$mapping}/(.+?)/(.+)$ {$system_root}/{$dist_path} [END]
    <!-- END BLOCK: webassets -->

    # Data directory location
    <!-- START BLOCK: data_mapping -->
    RewriteRule ^{$system_root}/{$route_path}data/(.+)$ {$system_root}/{$data_path} [END]
    <!-- END BLOCK: data_mapping -->

    # Site routing
    <!-- START BLOCK: route -->
    RewriteRule ^{$route_path}(/.+)? {$system_root}/index.php [END]
    <!-- END BLOCK: route -->
</If>

<!-- END BLOCK: domain -->
RewriteCond $0#%{REQUEST_URI} ^([^#]*)#(.*)\1$
RewriteRule ^.*$ - [E=BASE:%2]

RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteCond %{REQUEST_FILENAME} !-l
RewriteCond $1 !^(index\.php|robots\.txt|sites|system|shared|plugins|library|asset|repository\.inc\.php|config\.inc\.php|sites\.inc\.php)

RewriteRule ^ - [L,R=404]