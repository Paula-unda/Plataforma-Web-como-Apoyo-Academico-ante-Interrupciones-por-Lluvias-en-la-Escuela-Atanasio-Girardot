# docker/Dockerfile
FROM php:8.2-apache

# Instalar extensiones requeridas
RUN apt-get update && apt-get install -y \
    libpq-dev \
    && docker-php-ext-install pdo pdo_pgsql \
    && a2enmod rewrite

# Copiar proyecto
COPY . /var/www/html/
WORKDIR /var/www/html/

# Exponer puerto
EXPOSE 80