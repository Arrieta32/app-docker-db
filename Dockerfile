FROM php:8.4-apache

# 1. Habilitar mod_rewrite de Apache para MVC / rutas amigables
RUN a2enmod rewrite

# 2. Instalar dependencias del sistema y drivers PDO de PostgreSQL
RUN apt-get update && apt-get install -y libpq-dev \
    && docker-php-ext-install pdo pdo_pgsql pgsql \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# 3. Establecer directorio de trabajo
WORKDIR /var/www/html

# 4. Crear carpeta de persistencia y otorgar permisos al usuario www-data
RUN mkdir -p /var/www/html/data \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 775 /var/www/html/data

# 5. Copiar el código del proyecto
COPY . /var/www/html/

EXPOSE 80