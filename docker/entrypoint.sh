#!/bin/sh
set -e

APP_DIR=/var/www/html
CONFIG_FILE="${APP_DIR}/config/config.php"
MARKER="generado por el entrypoint de docker"

DB_HOST="${DB_HOST:-db}"
DB_PORT="${DB_PORT:-3306}"
DB_NAME="${DB_NAME:-mlsurvey}"
DB_USER="${DB_USER:-mlsurvey}"

# Tamaño del pool de workers de Apache (= conexiones persistentes a la base de datos).
export APACHE_MAX_WORKERS="${APACHE_MAX_WORKERS:-150}"
export APACHE_START_WORKERS="${APACHE_START_WORKERS:-25}"
export APACHE_MAX_SPARE_WORKERS="${APACHE_MAX_SPARE_WORKERS:-75}"
export APACHE_LISTEN_BACKLOG="${APACHE_LISTEN_BACKLOG:-1024}"

# Directorios de subida: tienen que existir y ser escribibles por www-data.
# El de iconos no lo crea la imagen, y en desarrollo (codigo montado) manda la
# propiedad del host, asi que sin esto subir un icono falla con «permiso denegado».
for d in files img/appicon log; do
    mkdir -p "${APP_DIR}/${d}" 2>/dev/null || true
    chown www-data:www-data "${APP_DIR}/${d}" 2>/dev/null || true
done

# HTTPS: certificado del servidor y CA admitidas para el certificado de cliente.
# Se guardan en /etc/ssl/mlsurvey (volumen): monta ahi tu server.crt/server.key
# y no se genera el autofirmado.
SSL_DIR=/etc/ssl/mlsurvey
mkdir -p "$SSL_DIR"
if [ ! -f "$SSL_DIR/server.crt" ] || [ ! -f "$SSL_DIR/server.key" ]; then
    echo "[mlsurvey] generando certificado autofirmado del servidor."
    openssl req -x509 -newkey rsa:2048 -nodes -days 825 -subj "/CN=localhost" \
        -addext "subjectAltName=DNS:localhost,IP:127.0.0.1" -addext "basicConstraints=critical,CA:FALSE" \
        -keyout "$SSL_DIR/server.key" -out "$SSL_DIR/server.crt" 2>/dev/null
    chmod 600 "$SSL_DIR/server.key"
fi
# La lista de CA cambia de vez en cuando: para regenerarla, borra el fichero y
# reinicia, o ejecuta buildclientca.php y "apache2ctl graceful".
if [ ! -s "$SSL_DIR/client_ca.pem" ]; then
    echo "[mlsurvey] generando las CA admitidas desde la TSL (tarda unos segundos)."
    if ! ( cd "$APP_DIR" && php buildclientca.php "$SSL_DIR/client_ca.pem" "" ${CLIENT_CA_EXTRA:+"$CLIENT_CA_EXTRA"} ); then
        # Sin fichero Apache no arranca: provisionalmente, las CA del sistema.
        echo "[mlsurvey] AVISO: no se ha podido descargar la TSL; el registro no aceptara" \
            "certificados espanoles hasta regenerar $SSL_DIR/client_ca.pem."
        cp /etc/ssl/certs/ca-certificates.crt "$SSL_DIR/client_ca.pem"
    fi
fi

# config/config.php se genera leyendo el entorno: si montas el tuyo propio, se respeta.
if [ -f "$CONFIG_FILE" ] && ! grep -q "$MARKER" "$CONFIG_FILE" 2>/dev/null; then
    echo "[mlsurvey] config/config.php propio detectado, no se sobreescribe."
else
    echo "[mlsurvey] generando config/config.php desde las variables de entorno."
    cat > "$CONFIG_FILE" <<'PHPEOF'
<?php
// Fichero generado por el entrypoint de docker: no editar, se regenera al arrancar.
// define() en lugar de const: una expresion const no admite llamadas a getenv().
define('CONFIG', [
"db_host" => getenv('DB_HOST')   ?: 'db',
"db_name" => getenv('DB_NAME')   ?: 'mlsurvey',
"db_user"=> getenv('DB_USER')   ?: 'mlsurvey',
"db_pass"=> getenv('DB_PASSWORD') ?: '',
"db_port"=>getenv('DB_PORT')   ?: 3306,

"db_prefix"=>getenv('DB_PREFIX') ?: '',

// URL publica del sitio: con ella se construyen los enlaces de los correos.
"site_url"=>getenv('SITE_URL') ?: '',
// URL del registro y la votacion, la unica que pide el certificado electronico.
"registration_url"=>getenv('REGISTRATION_URL') ?: '',

"log_level"=>(int) (getenv('LOG_LEVEL') !== false ? getenv('LOG_LEVEL') : 0),


"email_method" => getenv('EMAIL_METHOD') ?: 'SMTP', 
"email_server" => getenv('EMAIL_SERVER') ?: '',
"email_port" => (int) (getenv('EMAIL_PORT') !== false ? getenv('EMAIL_PORT') : 587),
"email_user" => getenv('EMAIL_USER') ?: '',
"email_password" => getenv('EMAIL_PASSWORD') ?: '',
"email_from" => getenv('EMAIL_FROM') ?: '',
// PHPMailer entiende '' como «sin cifrar», pero una variable vacia no se
// distingue de una sin definir: por eso se escribe 'none' a proposito.
"email_encryption" => getenv('EMAIL_ENCRYPTION') === 'none' ? '' : (getenv('EMAIL_ENCRYPTION') ?: 'tls'),

// El captcha necesita Web Crypto: sin HTTPS (o localhost) hay que apagarlo.
"altcha_enabled" => getenv('ALTCHA_ENABLED') === 'false' ? false : true,
"altcha_hmac_key" => getenv('ALTCHA_HMAC_KEY') ?: '',

// Clave del HMAC del correo y el DNI de las participantes: obligatoria y fija.
"participant_hmac_key" => getenv('PARTICIPANT_HMAC_KEY') ?: '',

// Antiguedad maxima de la hoja de servicios.
"pdf_max_age_days" => (int) (getenv('PDF_MAX_AGE_DAYS') ?: 30),

// Certificado electronico al registrarse y al votar: lo pide y verifica el HTTPS del
// contenedor (docker/apache-sites.conf), que lo pasa en SSL_CLIENT_*. Con un
// proxy TLS delante, las cabeceras donde lo pase (ver config.php.sample).
"client_cert_enabled" => getenv('CLIENT_CERT_ENABLED') === 'false' ? false : true,
"client_cert_verify_var" => getenv('CLIENT_CERT_VERIFY_VAR') ?: 'SSL_CLIENT_VERIFY',
"client_cert_var" => getenv('CLIENT_CERT_VAR') ?: 'SSL_CLIENT_CERT',

"ml_stresstest" => false,
// Por defecto sin restringir, como en config.php.sample.
"survey_edit_restric" => getenv('SURVEY_EDIT_RESTRIC') === 'true',
/**
 * For using the default icon when no icon is defined
 */
"use_default_icon" => true,
]);
PHPEOF
    chown www-data:www-data "$CONFIG_FILE"
    chmod 640 "$CONFIG_FILE"
fi

# Esperar a la base de datos (el init de MariaDB tarda en cargar modelo_datos.sql).
echo "[mlsurvey] esperando a MariaDB en ${DB_HOST}:${DB_PORT} ..."
i=0
until php -r '
    $dsn = "mysql:host=" . getenv("DB_HOST") . ";port=" . getenv("DB_PORT") . ";dbname=" . getenv("DB_NAME");
    try { new PDO($dsn, getenv("DB_USER"), getenv("DB_PASSWORD")); exit(0); }
    catch (Throwable $e) { exit(1); }
' 2>/dev/null; do
    i=$((i + 1))
    if [ "$i" -ge 60 ]; then
        echo "[mlsurvey] AVISO: la base de datos no responde, se arranca igualmente."
        break
    fi
    sleep 2
done

# Usuario administrador inicial (opcional). Si ya existe, addusercmd.php lo indica y seguimos.
if [ -n "$ADMIN_USER" ] && [ -n "$ADMIN_PASSWORD" ]; then
    echo "[mlsurvey] comprobando usuario administrador '${ADMIN_USER}'."
    ( cd "$APP_DIR" && php addusercmd.php "$ADMIN_USER" "$ADMIN_PASSWORD" ) || \
        echo "[mlsurvey] el usuario administrador ya existia o no se pudo crear."
fi

# HTTP -> HTTPS (docker/apache-sites.conf): a la URL publica, que lleva el
# puerto de fuera del contenedor. Sin SITE_URL https:// no se redirige.
SITE_URL_VALUE="${SITE_URL:-}"
if [ "${HTTPS_REDIRECT:-true}" != "false" ] && [ "$1" = "apache2-foreground" ]; then
    case "$SITE_URL_VALUE" in
        https://*)
            export HTTPS_REDIRECT_URL="${SITE_URL_VALUE%/}/"
            set -- "$@" -DHTTPS_REDIRECT
            echo "[mlsurvey] HTTP redirige a ${HTTPS_REDIRECT_URL}" ;;
        *)
            echo "[mlsurvey] AVISO: SITE_URL no es https://, HTTP no redirige a HTTPS." ;;
    esac
fi

exec "$@"
