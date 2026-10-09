FROM php:8.3-apache AS runtime-base
RUN apt-get update && apt-get install -y --no-install-recommends git unzip libzip-dev libcurl4-openssl-dev libonig-dev libsqlite3-dev libldap2-dev && rm -rf /var/lib/apt/lists/*
RUN docker-php-ext-configure ldap --with-libdir=lib/$(dpkg-architecture --query DEB_HOST_MULTIARCH)/ && docker-php-ext-install zip curl mbstring pdo_sqlite ldap mysqli
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

FROM --platform=$BUILDPLATFORM node:22-bookworm-slim AS frontend-builder
WORKDIR /build
COPY package.json package-lock.json ./
RUN CYPRESS_INSTALL_BINARY=0 npm ci --legacy-peer-deps --ignore-scripts
COPY frontend ./frontend
COPY babel.config.js vue.config.js postcss.config.js .eslintrc.js ./
RUN NODE_OPTIONS=--openssl-legacy-provider npm run build

FROM runtime-base AS php-builder
WORKDIR /var/www/filegator
COPY . .
RUN composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
RUN rm -rf node_modules frontend tests .git .github vendor/bin/phpunit

FROM runtime-base
WORKDIR /var/www/filegator
COPY --from=php-builder --chown=www-data:www-data /var/www/filegator ./
COPY --from=frontend-builder --chown=www-data:www-data /build/dist ./dist
ENV FILEGATOR_AUTH_MODE=external
ENV APACHE_DOCUMENT_ROOT=/var/www/filegator/dist
RUN sed -ri 's!/var/www/html!/var/www/filegator/dist!g' /etc/apache2/sites-available/000-default.conf && sed -ri 's!Listen 80!Listen 8080!' /etc/apache2/ports.conf && sed -ri 's!:80>!:8080>!' /etc/apache2/sites-available/000-default.conf && a2enmod rewrite
RUN mkdir -p private/logs private/sessions private/tmp private/cf-access-cache && chown -R www-data:www-data private repository
EXPOSE 8080
VOLUME ["/var/www/filegator/repository", "/var/www/filegator/private"]
USER www-data
CMD ["apache2-foreground"]
