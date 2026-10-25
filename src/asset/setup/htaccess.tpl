RewriteEngine on

# Detect base directory for subdirectory installs
RewriteCond $0#%{REQUEST_URI} ^([^#]*)#(.*)\1$
RewriteRule ^.*$ - [E=BASE:%2]

<!-- START BLOCK: exclusion -->
# Declared sibling prefix {$prefix}: never claimed by Razy (sites.inc.php 'exclude_paths' — see manual/08-coexistence.md).
# REQUEST_URI carries the full path; per-directory patterns below see the prefix-stripped path.
RewriteCond %{REQUEST_URI} ^{$prefix_pattern}(/|$)
RewriteRule ^ - [L]
<!-- END BLOCK: exclusion -->
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
    # RZ-24: an existing file is not a reason to skip the front controller.
    # The old exists-checks and filename exemptions made Apache serve or
    # execute the checkout. index.php stays out (rewrite loop). robots.txt
    # stays out (public file; a missing one is Apache's own 404).
    RewriteCond %{ENV:RAZY_DOMAIN} ={$domain}
    RewriteCond $1 !^(index\.php|robots\.txt)$
    RewriteRule ^{$route_path}(.*)$ %{ENV:BASE}index.php [L]
    <!-- END BLOCK: fallback -->
<!-- END BLOCK: rewrite -->