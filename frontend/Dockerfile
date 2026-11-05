FROM php:8.3-apache
RUN docker-php-ext-install curl 2>/dev/null || true
RUN apt-get update && apt-get install -y --no-install-recommends libcurl4-openssl-dev && docker-php-ext-install curl && rm -rf /var/lib/apt/lists/*
COPY index.php /var/www/html/index.php
