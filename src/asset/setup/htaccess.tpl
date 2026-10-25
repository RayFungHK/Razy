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
# Shared module files. [END] (Apache 2.4+): [L] re-enters this file, and the
# fallback would then send the rewritten path to index.php.
RewriteRule ^\w+/shared/(.*)$ shared/$1 [END]

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
    # RZ-25: nothing under this data mapping is PHP, including a URL that
    # continues past the script (/data/note.php/extra). The prefix is the
    # whole mapping, anchored at the mount, so /report/data/export.php is
    # not included. [H=default-handler] forces the static handler (a
    # <FilesMatch> in this file would also hit index.php). The <If>
    # repeats SetHandler none / RemoveHandler when the env var is visible;
    # [H] does not depend on that. The script rule stops at the extension
    # (the first one), so the target is the file, not file.php/PATH_INFO.
    # [T] sends the bytes. [END] stops the rewrite re-entry.
    RewriteCond %{ENV:RAZY_DOMAIN} ={$domain}
    RewriteRule ^{$route_path}data/ - [H=default-handler,E=RAZY_DATA_DIR:1,E=REDIRECT_RAZY_DATA_DIR:1]
    <If "env('RAZY_DATA_DIR') == '1' || env('REDIRECT_RAZY_DATA_DIR') == '1'">
        SetHandler none
        RemoveHandler .php .phtml .phar
    </If>
    RewriteCond %{ENV:RAZY_DOMAIN} ={$domain}
    RewriteRule ^{$route_path}data/(.+?\.(php|phtml|phar))(/|$) {$data_path} [NC,H=default-handler,T=application/octet-stream,END]
    # RZ-25: html, htm, svg, xml, xhtml, js and mjs download and stay
    # sandboxed. The next rule publishes the file. An internal redirect
    # renames the env var, so both names are set and both are checked.
    RewriteCond %{ENV:RAZY_DOMAIN} ={$domain}
    RewriteRule ^{$route_path}data/.+\.(html|htm|svg|xml|xhtml|js|mjs)$ - [NC,E=RAZY_DATA_ACTIVE:1,E=REDIRECT_RAZY_DATA_ACTIVE:1]
    # [END] (Apache 2.4+): same re-entry as the shared rule above.
    RewriteCond %{ENV:RAZY_DOMAIN} ={$domain}
    RewriteRule ^{$route_path}data/(.+)$ {$data_path} [END]
    Header set Content-Disposition "attachment" env=RAZY_DATA_ACTIVE
    Header set Content-Disposition "attachment" env=REDIRECT_RAZY_DATA_ACTIVE
    Header set Content-Security-Policy "sandbox" env=RAZY_DATA_ACTIVE
    Header set Content-Security-Policy "sandbox" env=REDIRECT_RAZY_DATA_ACTIVE
    Header set X-Content-Type-Options "nosniff" env=RAZY_DATA_ACTIVE
    Header set X-Content-Type-Options "nosniff" env=REDIRECT_RAZY_DATA_ACTIVE
    <!-- END BLOCK: data_mapping -->
    <!-- START BLOCK: fallback -->
    # RZ-24: an existing file is not a reason to skip the front controller.
    # The old exists-checks and filename exemptions made Apache serve or
    # execute the checkout. index.php stays out (rewrite loop). A docroot
    # crawler file is the front controller, same as php -S and Caddy.
    RewriteCond %{ENV:RAZY_DOMAIN} ={$domain}
    RewriteCond $1 !^(index\.php)$
    RewriteRule ^{$route_path}(.*)$ %{ENV:BASE}index.php [L]
    <!-- END BLOCK: fallback -->
<!-- END BLOCK: rewrite -->