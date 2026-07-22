FROM dunglas/frankenphp:1-php8.4-alpine

RUN apk add --no-cache git unzip bash curl ca-certificates nodejs npm \
    && npm install -g pnpm@9.14.2

RUN install-php-extensions intl zip
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN git config --global --add safe.directory /app && \
    git config --global --add safe.directory /var/beacon-bundle && \
    git config --global --add safe.directory /var/nowo-bundles/PasswordToggleBundle

WORKDIR /app

ENV COMPOSER_ALLOW_SUPERUSER=1
ENV PATH="/app/vendor/bin:${PATH}"
ENV SERVER_NAME=:80

COPY docker/frankenphp/Caddyfile /etc/frankenphp/Caddyfile
COPY docker/frankenphp/Caddyfile.dev /etc/frankenphp/Caddyfile.dev

COPY docker/entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

ENTRYPOINT ["docker-entrypoint.sh"]
