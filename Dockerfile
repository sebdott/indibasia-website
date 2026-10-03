FROM php:8.4-apache-bookworm

WORKDIR /var/www/indiba

RUN a2enmod rewrite \
    && docker-php-ext-install pdo_mysql \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && php -r 'foreach (["curl", "dom", "openssl"] as $extension) { if (!extension_loaded($extension)) { fwrite(STDERR, "Missing PHP extension: $extension\n"); exit(1); } }'

COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/indiba.ini
COPY config.php ./
COPY lib/ ./lib/
COPY locales/ ./locales/
COPY public/ ./public/
COPY tools/ ./tools/

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD curl --fail --silent --show-error --output /dev/null http://127.0.0.1/ || exit 1

CMD ["apache2-foreground"]
