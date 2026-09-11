# Razy Framework — Official Docker Image
# Multi-stage build: install deps → build phar → slim runtime
#
# ⚠️ This image runs PHP's BUILT-IN server: dev / smoke / demo use only.
#    Production = worker mode: deploy/Dockerfile.worker (FrankenPHP, prebuilt
#    object graph, /_razy/health probes) with deploy/k8s/ for orchestration.
#
# Usage:
#   docker build -t razy -f .docker/Dockerfile .
#   docker run -p 8080:8080 razy

# ===== Stage 1: Composer install =====
FROM php:8.3-cli-alpine AS builder

RUN apk add --no-cache zip libzip-dev curl-dev icu-dev oniguruma-dev \
    && docker-php-ext-install zip mbstring

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY composer.json composer.lock* ./
RUN composer install --prefer-dist --no-dev --no-interaction --no-scripts --optimize-autoloader

COPY . .
RUN php -d phar.readonly=0 build.php && \
    test -f /app/Razy.phar && echo "Razy.phar built OK" || (echo "ERROR: Razy.phar not found after build" && exit 1)

# Generate a minimal site directory for the built-in web server
RUN mkdir -p /app/site && \
    cp /app/Razy.phar /app/site/Razy.phar && \
    printf '<?php\nreturn [\n    "debug" => (bool) getenv("RAZY_DEBUG"),\n    "timezone" => getenv("RAZY_TIMEZONE") ?: "UTC",\n];\n' > /app/site/config.inc.php && \
    printf '<?php\n$uri = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);\n$path = __DIR__ . $uri;\nif ($uri !== "/" && is_file($path)) { return false; }\nrequire __DIR__ . "/index.php";\n' > /app/site/router.php && \
    printf '<?php\nnamespace Razy;\nuse Phar;\nPhar::loadPhar(__DIR__ . "/Razy.phar", "Razy.phar");\ninclude "phar://Razy.phar/main.php";\n' > /app/site/index.php

# ===== Stage 2: Runtime =====
FROM php:8.3-cli-alpine AS runtime

RUN apk add --no-cache libzip-dev curl-dev icu-dev oniguruma-dev \
    && docker-php-ext-install zip mbstring pcntl opcache \
    && apk del --no-cache libzip-dev curl-dev icu-dev oniguruma-dev \
    && apk add --no-cache libzip curl icu-libs oniguruma

# Runtime hardening: non-root user (audit §checklist — no root containers).
RUN addgroup -g 1000 razy && adduser -u 1000 -G razy -D razy

# OPcache on, validation off (immutable phar); sane JIT defaults.
RUN printf 'opcache.enable=1\nopcache.enable_cli=0\nopcache.validate_timestamps=0\nopcache.memory_consumption=192\nopcache.max_accelerated_files=20000\nopcache.interned_strings_buffer=16\nexpose_php=0\n' \
    > /usr/local/etc/php/conf.d/razy-production.ini

WORKDIR /app

# Copy built phar + vendor + source (owned by the runtime user)
COPY --from=builder --chown=razy:razy /app/Razy.phar ./Razy.phar
COPY --from=builder --chown=razy:razy /app/vendor ./vendor
COPY --from=builder --chown=razy:razy /app/composer.json ./composer.json
COPY --from=builder --chown=razy:razy /app/src ./src

# Copy generated site and demo modules
COPY --from=builder --chown=razy:razy /app/site ./site
COPY --from=builder --chown=razy:razy /app/demo_modules ./demo_modules

# Default: run the PHP built-in server with the generated site
EXPOSE 8080
ENV RAZY_HOST=0.0.0.0
ENV RAZY_PORT=8080
ENV RAZY_DEBUG=false
ENV RAZY_TIMEZONE=UTC

USER razy

# Liveness probe (framework endpoint exists since this build — see Razy\Health)
HEALTHCHECK --interval=30s --timeout=3s --start-period=10s --retries=3 \
    CMD wget -qO- "http://127.0.0.1:${RAZY_PORT}/_razy/health" | grep -q '"status":"ok"' || exit 1

CMD ["sh", "-c", "php -S ${RAZY_HOST}:${RAZY_PORT} -t /app/site /app/site/router.php"]
