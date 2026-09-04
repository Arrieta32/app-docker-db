FROM php:8.4-apache

# Instalar dependencias del sistema y drivers PDO de PostgreSQL
RUN apt-get update && apt-get install -y libpq-dev \
    && docker-php-ext-install pdo pdo_pgsql pgsql \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Establecer directorio de trabajo
WORKDIR /var/www/html

# Copiar el código fuente
COPY ./src /var/www/html/

EXPOSE 80