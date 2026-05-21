FROM php:8.2-apache

# 1. Instala dependências do sistema e do Composer (git, unzip)
RUN apt-get update && apt-get install -y \
    libcurl4-openssl-dev pkg-config libssl-dev git unzip \
    && docker-php-ext-install pdo pdo_mysql mysqli curl \
    && a2enmod rewrite

# 2. Altera a porta do Apache para a porta dinâmica do Render
RUN sed -i 's/80/${PORT}/g' /etc/apache2/sites-available/000-default.conf /etc/apache2/ports.conf

# 3. Instala o Composer copiando a imagem oficial
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# 4. Copia os seus arquivos PHP para o servidor
COPY . /var/www/html/

# 5. Roda o composer install para baixar a pasta "vendor" e a lib do Google
RUN cd /var/www/html && composer install --no-dev --optimize-autoloader

# 6. Permissões
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80