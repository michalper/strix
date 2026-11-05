FROM php:8.5-fpm-alpine AS base

ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/install-php-extensions

RUN chmod +x /usr/local/bin/install-php-extensions \
    && install-php-extensions amqp pdo_pgsql opcache \
    && apk add --no-cache git unzip

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

FROM base AS dev
# Dev stage keeps require-dev packages (PHPUnit, PHPStan, php-cs-fixer, Infection, MakerBundle)
# for composer operations, tests and static analysis run against this image.

FROM base AS prod
# Must be set before `composer install --no-dev`: Flex's post-install cache:clear
# script boots the kernel in APP_ENV, and dev-only bundles (MakerBundle, etc.) are
# registered for the dev/test envs - booting as dev here would fail to find them
# once --no-dev has removed their packages from vendor/.
ENV APP_ENV=prod
COPY . /app
RUN composer install --no-dev --no-interaction --optimize-autoloader
