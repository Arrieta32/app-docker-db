FROM php:8.4-apache
RUN a2enmod rewrite

# Instalar dependencias del sistema y drivers PDO de PostgreSQL
RUN apt-get update && apt-get install -y libpq-dev \
    && docker-php-ext-install pdo pdo_pgsql pgsql \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

RUN mkdir -p /var/www/html/data \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 775 /var/www/html/data

# Establecer directorio de trabajo
WORKDIR /var/www/html

# Copiar el código fuente
COPY . /var/www/html/

EXPOSE 80