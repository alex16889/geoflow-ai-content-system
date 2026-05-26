FROM nginx:1.27-alpine

WORKDIR /var/www/html

COPY docker/nginx/default.conf.template /etc/nginx/templates/default.conf.template
COPY admin /var/www/html/admin
COPY api /var/www/html/api
COPY assets /var/www/html/assets
COPY includes /var/www/html/includes
COPY lang /var/www/html/lang
COPY themes /var/www/html/themes
COPY archive.php /var/www/html/archive.php
COPY article.php /var/www/html/article.php
COPY category.php /var/www/html/category.php
COPY index.php /var/www/html/index.php
COPY llms.php /var/www/html/llms.php
COPY llms.txt /var/www/html/llms.txt
COPY llms-full.txt /var/www/html/llms-full.txt
COPY robots.php /var/www/html/robots.php
COPY router.php /var/www/html/router.php
COPY sitemap.php /var/www/html/sitemap.php
COPY theme-preview.php /var/www/html/theme-preview.php

EXPOSE 8080
