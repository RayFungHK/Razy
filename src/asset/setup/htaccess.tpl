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
    # RZ-25: a file under this data mapping is not PHP, including a URL
    # that continues past the script (/data/note.php/extra). The pattern
    # requires a path after data/, so the directory URL /data/ does not
    # set the env and fall through to index.php with PHP disabled.
    # Anchored at the mount, so /report/data/export.php is not included.
    # [H=default-handler] forces the static handler (a <FilesMatch> in
    # this file would also hit index.php). The <If> repeats SetHandler
    # none / RemoveHandler when the env var is visible; [H] does not
    # depend on that. The script rule stops at the extension (the first
    # one), so the target is the file, not file.php/PATH_INFO. [T] sends
    # the bytes. [END] stops the rewrite re-entry. [NC] matches the
    # publish rule below: a case variant of the prefix is still this file.
    RewriteCond %{ENV:RAZY_DOMAIN} ={$domain}
    RewriteRule ^{$route_path}data/.+ - [NC,H=default-handler,E=RAZY_DATA_DIR:1,E=REDIRECT_RAZY_DATA_DIR:1]
    <If "env('RAZY_DATA_DIR') == '1' || env('REDIRECT_RAZY_DATA_DIR') == '1'">
        SetHandler none
        RemoveHandler .php .phtml .phar
    </If>
    RewriteCond %{ENV:RAZY_DOMAIN} ={$domain}
    RewriteRule ^{$route_path}data/(.+?\.(php|phtml|phar))(/|$) {$data_path} [NC,H=default-handler,T=application/octet-stream,END]
    # RZ-25: active extensions (HTML, XHTML, SVG, XML, and JavaScript, from
    # Apache mime.types and Go's MIME table) download and stay sandboxed.
    # The next rule publishes the file, with the same [NC],
    # so the headers cannot land on a request this rule matched and the
    # publish rule missed. An internal redirect renames the env var, so
    # both names are set and both are checked.
    RewriteCond %{ENV:RAZY_DOMAIN} ={$domain}
    RewriteRule ^{$route_path}data/.+\.(1km|apxml|atom|atomcat|atomdeleted|atomsrv|atomsvc|bmml|ccmp|ccxml|cdfx|cdxml|cellml|cjs|cl|clue|cml|cpl|csl|dae|davmount|dbk|dd2|ddf|dist|distz|dtb|dwd|ecma|ehtml|emma|emotionml|es|es3|et3|fdt|fo|gml|gpx|grxml|gsheet|hal|held|htm|html|ink|inkml|irp|its|js|kml|lasxml|lbe|lgr|link66|lostsyncxml|lostxml|mads|maei|mathml|meta4|metalink|mets|mjs|mml|mods|moml|mpd|mpkg|mrcx|mscml|musd|musicxml|mxml|ncx|obgx|odd|omdoc|opf|osfpvg|osm|pkg|pls|provx|pskcxml|rapd|rdf|relo|res|rfcxml|rif|rl|rld|rs|rsat|rsd|rsheet|rss|rusd|sbml|scl|sdkd|sdkm|senmlx|sensmlx|shf|shtml|sls|smi|smil|sml|sru|srx|ssdl|ssml|stpx|svg|svgz|swidtag|td|tei|teicorpus|tfi|ttml|uis|uo|uoml|uvt|uvvt|vxml|wadl|wbs|wif|wsdl|wspolicy|x3d|x3dz|xaml|xav|xbl|xca|xcs|xdf|xdm|xdp|xdssc|xel|xenc|xer|xht|xhtm|xhtml|xhvml|xlf|xml|xmls|xns|xop|xpl|xsf|xsl|xslt|xsm|xspf|xul|xvm|xvml|yin|zaz|zmm)$ - [NC,E=RAZY_DATA_ACTIVE:1,E=REDIRECT_RAZY_DATA_ACTIVE:1]
    # [END] (Apache 2.4+): same re-entry as the shared rule above.
    # [NC] matches the active-type rule above.
    RewriteCond %{ENV:RAZY_DOMAIN} ={$domain}
    RewriteRule ^{$route_path}data/(.+)$ {$data_path} [NC,END]
    # RZ-25: DirectoryIndex would send an uploaded index.html for
    # /data/ or /data/uploads/ as text/html. That URL does not end in
    # the extension, so the headers above never attach. The <If> is
    # this mount only: ^/data or ^/shop/data, and the same path under
    # CONTEXT_PREFIX when the install is in a subdirectory.
    # /database and /report/data/export.php stay outside it. These two
    # directives do not disable PHP; /data/ still reaches index.php.
    # DirectoryIndex needs AllowOverride Indexes. Options needs
    # AllowOverride Options.
    <If "%{REQUEST_URI} =~ m#(?i)^/{$route_path}data(/|$)# || %{REQUEST_URI} -strcmatch '%{CONTEXT_PREFIX}/{$route_path}data' || %{REQUEST_URI} -strcmatch '%{CONTEXT_PREFIX}/{$route_path}data/*'">
        DirectoryIndex disabled
        Options -Indexes
    </If>
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