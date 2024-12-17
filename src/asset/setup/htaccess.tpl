RewriteEngine on

# Rewrite the shared module location
RewriteRule ^\w+/shared/(.*)$ shared/$1 [L]

<!-- START BLOCK: domain -->
    # Rewrite WebAssets location
    <!-- START BLOCK: webassets -->
    RewriteRule ^{$route_path}webassets/{$mapping}/(.+?)/(.+)$ {$dist_path} [END]
    <!-- END BLOCK: webassets -->

    # Rewrite the distributor data location
    <!-- START BLOCK: data_mapping -->
    RewriteRule ^{$route_path}data/(.+)$ {$data_path} [L]
    <!-- END BLOCK: data_mapping -->

<!-- END BLOCK: domain -->

RewriteCond $0#%{REQUEST_URI} ^([^#]*)#(.*)\1$
RewriteRule ^.*$ - [E=BASE:%2]

RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteCond %{REQUEST_FILENAME} !-l
RewriteCond $1 !^(index\.php|robots\.txt|sites|system|shared|plugins|library|asset|repository\.inc\.php|config\.inc\.php|sites\.inc\.php)

RewriteRule ^(.*)$ %{ENV:BASE}index.php [L]