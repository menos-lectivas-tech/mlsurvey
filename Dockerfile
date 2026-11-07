FROM php:8.3-apache

# Dependencias de sistema: composer necesita git/unzip para descargar los paquetes;
# openssl, pdftotext (poppler-utils) y qpdf comprueban la declaracion firmada.
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip openssl poppler-utils qpdf \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql opcache \
    && a2enmod rewrite headers ssl socache_shmcb

COPY docker/apache-mlsurvey.conf /etc/apache2/conf-available/mlsurvey.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/mlsurvey.ini
COPY docker/apache-site.conf /etc/apache2/sites-available/mlsurvey.conf
RUN a2enconf mlsurvey \
    && a2dissite 000-default \
    && a2ensite mlsurvey \
    && mkdir -p /etc/mlsurvey/ssl

# HTTPS: certificado del servidor. Si no existe al arrancar, el entrypoint
# genera uno autofirmado (ver docker-compose.yml para usar uno propio).
ENV SSL_CERT_FILE=/etc/mlsurvey/ssl/server.crt \
    SSL_KEY_FILE=/etc/mlsurvey/ssl/server.key
EXPOSE 80 443

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# PHPMailer (envio de correo) y HugeRTE (editor de descripciones), ver README.
RUN composer require --no-interaction --no-progress --optimize-autoloader \
        phpmailer/phpmailer:^6.9 \
        hugerte/hugerte:^1.0 \
        zoon/php-bounce-handler \
    && chown -R www-data:www-data /var/www/html

COPY --chown=www-data:www-data . /var/www/html/

# files/ guarda los adjuntos de las encuestas, log/ los errores de PHP (ver .htaccess).
RUN mkdir -p /var/www/html/files /var/www/html/log \
    && chown -R www-data:www-data /var/www/html/files /var/www/html/log

COPY docker/entrypoint.sh /usr/local/bin/mlsurvey-entrypoint
RUN chmod +x /usr/local/bin/mlsurvey-entrypoint

ENTRYPOINT ["mlsurvey-entrypoint"]
CMD ["apache2-foreground"]
