FROM php:8.0-apache

# Extensiones PHP necesarias para conectarse a MySQL/MariaDB
RUN docker-php-ext-install mysqli pdo pdo_mysql

# Habilitar mod_rewrite de Apache
RUN a2enmod rewrite

WORKDIR /var/www/html

# Copiar ESOL2 al DocumentRoot de Apache
COPY . /var/www/html/

EXPOSE 80