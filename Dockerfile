# Use PHP 8.3 with Apache.
# This provides PHP runtime and Apache web server.
FROM php:8.3-apache


# Drupal Composer project public directory is "web".
# Apache must serve web/, not the complete project directory.
ENV APACHE_DOCUMENT_ROOT=/var/www/html/web


# Install Linux libraries and tools required by Drupal,
# Composer, Drush and common Drupal modules.
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    curl \
    default-mysql-client \
    libfreetype6-dev \
    libjpeg62-turbo-dev \
    libpng-dev \
    libicu-dev \
    libzip-dev \
    libonig-dev \
    libxml2-dev \
    && docker-php-ext-configure gd \
        --with-freetype \
        --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        gd \
        intl \
        mbstring \
        opcache \
        pdo \
        pdo_mysql \
        zip \
    && a2enmod rewrite headers expires \
    && rm -rf /var/lib/apt/lists/*


# Copy Composer executable from the official Composer image.
# We don't need to install Composer manually.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer


# Set Drupal project directory inside the container.
WORKDIR /var/www/html


# Copy Composer dependency definitions first.
# This helps Docker reuse dependency cache when application
# code changes but composer.lock remains unchanged.
COPY composer.json composer.lock ./


# Install exact production dependencies from composer.lock.
#
# --no-dev:
# Excludes development-only packages.
#
# --no-interaction:
# Composer will not ask interactive questions in CI/CD.
#
# --prefer-dist:
# Prefer package archives instead of source repositories.
#
# --no-progress:
# Produces cleaner CI/CD logs.
#
# --no-scripts:
# Composer scripts are delayed until complete project is copied.
RUN composer install \
    --no-dev \
    --no-interaction \
    --prefer-dist \
    --no-progress \
    --no-scripts


# Copy complete Drupal project into the image.
# Files listed in .dockerignore will not be copied.
COPY . .


# Run Composer again after complete application is available.
#
# Cached packages from the previous layer will be reused.
# Composer scripts and optimized autoloading can now run.
RUN composer install \
    --no-dev \
    --no-interaction \
    --prefer-dist \
    --no-progress \
    --optimize-autoloader


# Change Apache's default document root from /var/www/html
# to the Composer Drupal public directory /var/www/html/web.
RUN sed -ri \
    -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' \
    /etc/apache2/sites-available/*.conf \
    /etc/apache2/apache2.conf \
    /etc/apache2/conf-available/*.conf \
    && printf '<Directory /var/www/html/web>\n    AllowOverride All\n    Require all granted\n</Directory>\n' \
        > /etc/apache2/conf-available/drupal.conf \
    && a2enconf drupal


# Ensure Drupal public-files directory exists.
#
# Uploaded images/documents are written here.
# Later, Docker Compose will mount a persistent volume here.
RUN mkdir -p /var/www/html/web/sites/default/files \
    && chown -R www-data:www-data \
        /var/www/html/web/sites/default/files \
    && chmod -R 775 \
        /var/www/html/web/sites/default/files


# Apache serves Drupal using port 80 inside the container.
EXPOSE 80


# Apache must stay in the foreground as the main container process.
CMD ["apache2-foreground"]