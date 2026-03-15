FROM php:8.2-apache

# Instalar extensiones
RUN apt-get update && apt-get install -y \
    libpq-dev \
    && docker-php-ext-install pdo pdo_pgsql

# Habilitar módulos necesarios
RUN a2enmod rewrite

# Configurar Apache para PHP
RUN { \
        echo '<FilesMatch \.php$>'; \
        echo '    SetHandler application/x-httpd-php'; \
        echo '</FilesMatch>'; \
    } > /etc/apache2/conf-available/php.conf && \
    a2enconf php
# Configurar sesiones
RUN sed -i 's/;session.save_path = "\/tmp"/session.save_path = "\/tmp"/' /usr/local/etc/php/php.ini-development && \
    mkdir -p /tmp/sessions && chmod -R 777 /tmp/sessions
    
# Directorio web
COPY . /var/www/html/

# =====================================================
# 🚀 NUEVO: Copiar el Service Worker de OneSignal
# =====================================================
# Asumiendo que el archivo descargado está en la raíz de tu proyecto
COPY OneSignalSDKWorker.js /var/www/html/OneSignalSDKWorker.js 

# Dar permisos específicos (opcional pero seguro)
RUN chmod 644 /var/www/html/OneSignalSDKWorker.js
# =====================================================

WORKDIR /var/www/html/

EXPOSE 80