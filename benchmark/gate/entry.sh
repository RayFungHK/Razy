#!/bin/sh
# benchgate entry — the deploy that the gate scenarios measure AGAINST:
#   1. wait for MySQL
#   2. apply declared migrations (php Razy.phar migrate benchgate)
#   3. stage the refused module's migration IN — post-migrate, so it is
#      declared-but-unapplied: a module mid-deploy, the honest 503 shape
#   4. hand the container to frankenphp
set -e

echo '[gate-entry] waiting for mysql...'
i=0
until php -r 'new PDO("mysql:host=bench-mysql;port=3306;dbname=benchmark", "benchmark", "benchmark");' 2>/dev/null; do
  i=$((i+1))
  [ $i -gt 60 ] && { echo '[gate-entry] mysql never came'; exit 1; }
  sleep 1
done

cd /app/site

# clear FIRST, before migrate: the build context may carry a dev-machine
# data/compiled artifact (host paths); migrate boots the site too, and with
# the fingerprint auto-shortened (opcache vt=0 here) a FOREIGN artifact is
# no longer caught by staleness — drop it at the door, then recompile below.
echo '[gate-entry] clearing any foreign artifact...'
php /app/Razy.phar compile benchgate --clear >/dev/null 2>&1

echo '[gate-entry] migrating benchgate (gate-ready declared in-module; refused has nothing declared yet)...'
php /app/Razy.phar migrate benchgate bench/gate-ready || {
  echo '[gate-entry] migrate FAILED (see above)'; exit 1;
}

echo '[gate-entry] staging gate-refused migration (declared, never applied)...'
mkdir -p /app/site/sites/benchgate/bench/gate-refused/default/migration
mv /app/site/sites/benchgate/bench/gate-refused/default/_pending/*.php /app/site/sites/benchgate/bench/gate-refused/default/migration/

# COMPILE-ON-DEPLOY: the deploy step the flag asks for. dist.php carries
# 'compiled_boot' => true, so after this line every boot replays the
# snapshot; delete the artifact (or leave compile to fail) and the site
# falls back to the full boot — the two paths serve identical traffic by
# the replay self-proof in `compile` itself.
echo '[gate-entry] compiling benchgate (compiled_boot dist)...'
php /app/Razy.phar compile benchgate || echo '[gate-entry] compile FAILED — serving full boot'

echo '[gate-entry] handing over to frankenphp'
exec frankenphp run --config /etc/frankenphp/Caddyfile
