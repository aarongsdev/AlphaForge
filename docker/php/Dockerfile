FROM php:8.4-fpm-alpine

LABEL maintainer="AlphaForge Team"
LABEL description="PHP 8.4-FPM for AlphaForge platform"

# Build arguments
ARG COMPOSER_VERSION=2.7.6

# Install system dependencies
RUN apk add --no-cache \
    bash \
    git \
    curl \
    wget \
    unzip \
    zip \
    libpq-dev \
    libpng-dev \
    libwebp-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    libzip-dev \
    icu-dev \
    oniguruma-dev \
    openssl-dev \
    linux-headers \
    libgomp \
    shadow \
    && apk add --no-cache --virtual .build-deps \
    $PHPIZE_DEPS \
    autoconf \
    g++ \
    make

# Configure and install GD extension
RUN docker-php-ext-configure gd \
    --with-freetype \
    --with-jpeg \
    --with-webp

# Install core PHP extensions
RUN docker-php-ext-install -j$(nproc) \
    pdo \
    pdo_pgsql \
    pgsql \
    intl \
    mbstring \
    gd \
    zip \
    bcmath \
    pcntl \
    posix \
    opcache \
    sockets

# Install Redis extension via PECL
RUN pecl install redis \
    && docker-php-ext-enable redis

# Install APCu for in-memory caching
RUN pecl install apcu \
    && docker-php-ext-enable apcu

# Clean up build dependencies and PECL cache
RUN apk del .build-deps \
    && rm -rf /tmp/pear \
    && docker-php-source delete

# Install Composer
RUN curl -sS https://getcomposer.org/installer | php -- \
    --install-dir=/usr/local/bin \
    --filename=composer \
    --version=${COMPOSER_VERSION} \
    && composer --version

# Configure PHP-FPM
RUN sed -i \
    -e 's/^;*\s*listen\s*=.*/listen = 0.0.0.0:9000/' \
    -e 's/^;*\s*pm\s*=.*/pm = dynamic/' \
    -e 's/^;*\s*pm.max_children\s*=.*/pm.max_children = 50/' \
    -e 's/^;*\s*pm.start_servers\s*=.*/pm.start_servers = 5/' \
    -e 's/^;*\s*pm.min_spare_servers\s*=.*/pm.min_spare_servers = 5/' \
    -e 's/^;*\s*pm.max_spare_servers\s*=.*/pm.max_spare_servers = 20/' \
    -e 's/^;*\s*pm.max_requests\s*=.*/pm.max_requests = 1000/' \
    /usr/local/etc/php-fpm.d/www.conf

# PHP production configuration
RUN cp /usr/local/etc/php/php.ini-production /usr/local/etc/php/php.ini

# Apply PHP runtime configuration
RUN { \
    echo 'memory_limit = 512M'; \
    echo 'max_execution_time = 120'; \
    echo 'max_input_time = 120'; \
    echo 'upload_max_filesize = 32M'; \
    echo 'post_max_size = 32M'; \
    echo 'error_reporting = E_ALL & ~E_DEPRECATED & ~E_STRICT'; \
    echo 'display_errors = Off'; \
    echo 'log_errors = On'; \
    echo 'error_log = /var/log/php/error.log'; \
    echo 'date.timezone = UTC'; \
    echo 'expose_php = Off'; \
    echo 'session.cookie_httponly = 1'; \
    echo 'session.cookie_secure = 1'; \
    echo 'session.use_strict_mode = 1'; \
    echo 'session.cookie_samesite = Strict'; \
    echo 'default_socket_timeout = 60'; \
    echo 'realpath_cache_size = 4096K'; \
    echo 'realpath_cache_ttl = 600'; \
    } > /usr/local/etc/php/conf.d/alphaforge.ini

# Create log directory
RUN mkdir -p /var/log/php && chmod 755 /var/log/php

# Create OPcache file cache directory
RUN mkdir -p /tmp/opcache && chmod 777 /tmp/opcache

# Set working directory
WORKDIR /var/www/html

# Create non-root user for the application
RUN addgroup -g 1000 -S appgroup \
    && adduser -u 1000 -S appuser -G appgroup

# Copy application files (if building for production)
# For development, source is mounted as a volume
# COPY --chown=appuser:appgroup . /var/www/html

# Install dependencies (for production builds)
# RUN composer install --no-dev --optimize-autoloader --no-interaction

# Set correct permissions
RUN chown -R appuser:appgroup /var/www/html \
    && chown -R appuser:appgroup /var/log/php \
    && chown -R appuser:appgroup /tmp/opcache

USER appuser

EXPOSE 9000

CMD ["php-fpm", "--nodaemonize"]
