FROM php:7.4-cli

RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libicu-dev \
        libzip-dev \
    && docker-php-ext-install pdo_mysql intl zip opcache \
    && rm -rf /var/lib/apt/lists/*

# The card importer builds the full catalog in one Doctrine unit of work,
# which needs more than the default 128M
RUN echo "memory_limit = 1G" > /usr/local/etc/php/conf.d/memory-limit.ini

# The project requires Composer 1 (see README)
COPY --from=composer:1 /usr/bin/composer /usr/local/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1

WORKDIR /app

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 8000

ENTRYPOINT ["entrypoint.sh"]
CMD ["php", "bin/console", "server:run", "0.0.0.0:8000"]
