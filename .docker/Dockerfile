# Razy Framework — Official Docker Image
# Multi-stage build: install deps → build phar → slim runtime
#
# Usage:
#   docker build -t razy -f .docker/Dockerfile .
#   docker run -p 8080:8080 razy

# ===== Stage 1: Composer install =====
FROM php:8.3-cli-alpine AS builder

RUN apk add --no-cache zip libzip-dev curl-dev icu-dev oniguruma-dev \
    && docker-php-ext-install zip curl mbstring phar

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY composer.json composer.lock* ./
RUN composer install --prefer-dist --no-dev --no-interaction --no-scripts --optimize-autoloader

COPY . .
RUN php build.php

# ===== Stage 2: Runtime =====
FROM php:8.3-cli-alpine AS runtime

RUN apk add --no-cache libzip curl icu-libs \
    && docker-php-ext-install zip curl mbstring phar pcntl

WORKDIR /app

# Copy built phar + vendor + source
COPY --from=builder /app/Razy.phar ./Razy.phar
COPY --from=builder /app/vendor ./vendor
COPY --from=builder /app/composer.json ./composer.json
COPY --from=builder /app/src ./src

# Copy playground as the default demo site
COPY --from=builder /app/playground ./playground
COPY --from=builder /app/demo_modules ./demo_modules

# Default: run the PHP built-in server with the playground router
EXPOSE 8080
ENV RAZY_HOST=0.0.0.0
ENV RAZY_PORT=8080

CMD ["sh", "-c", "php -S ${RAZY_HOST}:${RAZY_PORT} -t /app/playground /app/playground/router.php"]
