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

echo '[gate-entry] migrating benchgate (gate-ready declared in-module; refused has nothing declared yet)...'
php /app/Razy.phar migrate benchgate bench/gate-ready || {
  echo '[gate-entry] migrate FAILED (see above)'; exit 1;
}

echo '[gate-entry] staging gate-refused migration (declared, never applied)...'
mkdir -p /app/site/sites/benchgate/bench/gate-refused/default/migration
mv /app/site/sites/benchgate/bench/gate-refused/default/_pending/*.php /app/site/sites/benchgate/bench/gate-refused/default/migration/

echo '[gate-entry] handing over to frankenphp'
exec frankenphp run --config /etc/frankenphp/Caddyfile
