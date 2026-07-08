FROM php:8.2-apache

# --- Estensioni PHP + tool di sistema ---
RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql gd \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# --- Moduli Apache: URL puliti + header ---
RUN a2enmod rewrite headers

# --- Composer (dall'immagine ufficiale) ---
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# --- Config PHP + VirtualHost ---
COPY docker/php/php.ini /usr/local/etc/php/conf.d/cat4u.ini
COPY docker/apache/vhost.conf /etc/apache2/sites-available/000-default.conf

# --- Entrypoint: composer install al primo avvio, poi Apache ---
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

WORKDIR /var/www/html/cat4u
ENTRYPOINT ["entrypoint.sh"]