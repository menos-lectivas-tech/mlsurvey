FROM php:8.3-apache

# Dependencias de sistema: composer necesita git/unzip para descargar los paquetes;
# poppler-utils lee el texto de los documentos PDF;
# tesseract lee con OCR los que son una foto o un escaneo.
# openssl genera el certificado autofirmado del servidor HTTPS.
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip poppler-utils \
        tesseract-ocr tesseract-ocr-spa openssl ca-certificates \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql opcache \
    && a2enmod rewrite headers ssl

COPY docker/apache-mlsurvey.conf /etc/apache2/conf-available/mlsurvey.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/mlsurvey.ini
# HTTP (que redirige a HTTPS) y HTTPS con certificado de cliente para el
# registro; sustituyen al sitio por defecto.
COPY docker/apache-sites.conf /etc/apache2/sites-available/mlsurvey.conf
RUN a2enconf mlsurvey && a2dissite 000-default && a2ensite mlsurvey

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
