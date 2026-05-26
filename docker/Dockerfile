FROM php:8.3-fpm-bookworm AS base

WORKDIR /var/www/html

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libonig-dev \
        libpq-dev \
        libzip-dev \
        libsqlite3-dev \
        postgresql-client \
    && docker-php-ext-install \
        mbstring \
        pdo_pgsql \
        pdo_sqlite \
        zip \
    && rm -rf /var/lib/apt/lists/*

COPY docker/php.ini /usr/local/etc/php/conf.d/geo.ini
COPY docker/php-fpm-www.conf /usr/local/etc/php-fpm.d/zz-geoflow.conf
COPY docker/entrypoint.sh /usr/local/bin/geo-entrypoint
COPY docker/scheduler.sh /usr/local/bin/geo-scheduler

RUN chmod +x /usr/local/bin/geo-entrypoint /usr/local/bin/geo-scheduler

COPY admin /var/www/html/admin
COPY api /var/www/html/api
COPY assets /var/www/html/assets
COPY bin /var/www/html/bin
COPY includes /var/www/html/includes
COPY lang /var/www/html/lang
COPY themes /var/www/html/themes
COPY archive.php /var/www/html/archive.php
COPY article.php /var/www/html/article.php
COPY category.php /var/www/html/category.php
COPY index.php /var/www/html/index.php
COPY llms.txt /var/www/html/llms.txt
COPY llms-full.txt /var/www/html/llms-full.txt
COPY robots.php /var/www/html/robots.php
COPY router.php /var/www/html/router.php
COPY sitemap.php /var/www/html/sitemap.php
COPY theme-preview.php /var/www/html/theme-preview.php

EXPOSE 9000

ENTRYPOINT ["/usr/local/bin/geo-entrypoint"]

FROM base AS php

CMD ["php-fpm", "-F"]

FROM base AS web

EXPOSE 8080

CMD ["php", "-d", "implicit_flush=0", "-d", "output_buffering=4096", "-S", "0.0.0.0:8080", "router.php"]

FROM base AS scheduler

CMD ["/usr/local/bin/geo-scheduler"]
