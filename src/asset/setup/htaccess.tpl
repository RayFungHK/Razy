RewriteEngine on

# Rewrite the shared module location
RewriteRule ^\w+/shared/(.*)$ shared/$1 [L]

# Rewrite the distributor asset location
<!-- START BLOCK: rewrite -->
RewriteCond %{HTTP_HOST} ^{$domain}$
RewriteRule ^{$route_path}/view/{$mapping}(.*)$ {$dist_path}$1 [L]
<!-- END BLOCK: rewrite -->

RewriteCond $0#%{REQUEST_URI} ^([^#]*)#(.*)\1$
RewriteRule ^.*$ - [E=BASE:%2]

RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteCond %{REQUEST_FILENAME} !-l
RewriteCond $1 !^(index\.php|robots\.txt|sites|system|shared|plugins|library|asset|repository\.inc\.php|config\.inc\.php|sites\.inc\.php)

RewriteRule ^(.*)$ %{ENV:BASE}index.php [L]
