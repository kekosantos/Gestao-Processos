FROM php:8.3-apache-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates libonig-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql mbstring \
    && php -r 'foreach (["pdo_mysql", "mbstring", "fileinfo"] as $ext) { if (!extension_loaded($ext)) { fwrite(STDERR, "Required PHP extension missing: {$ext}" . PHP_EOL); exit(1); } }' \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
WORKDIR /var/www/html
COPY . /var/www/html/
COPY docker/apache-vhost.conf /etc/apache2/sites-available/000-default.conf
COPY docker/lexcloud.ini /usr/local/etc/php/conf.d/99-lexcloud.ini
COPY docker/entrypoint.sh /usr/local/bin/lexcloud-entrypoint
RUN chmod 0755 /usr/local/bin/lexcloud-entrypoint \
    && chown -R root:root /var/www/html \
    && find /var/www/html -type d -exec chmod 0755 {} \; \
    && find /var/www/html -type f -exec chmod 0644 {} \;

EXPOSE 8080
ENTRYPOINT ["/usr/local/bin/lexcloud-entrypoint"]
