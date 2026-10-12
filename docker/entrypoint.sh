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

// Firma del extracto de formacion: autoridades de confianza y NIF de los
// sellos admitidos (separados por comas).
"pdf_ca_file" => getenv('PDF_CA_FILE') ?: 'certs/extracto_ca.pem',
"pdf_signer_ids" => array_map('trim', explode(',', getenv('PDF_SIGNER_IDS') ?: 'S7800001E')),

// Documento que acredita el regimen (Clases Pasivas o resto): sello de MUFACE admitido para el
// certificado de afiliacion, antiguedad maxima del PDF y empresas publicas
// validas en la vida laboral (listas separadas por comas).
"muface_ca_file" => getenv('MUFACE_CA_FILE') ?: 'certs/muface_ca.pem',
"muface_signer_ids" => array_map('trim', explode(',', getenv('MUFACE_SIGNER_IDS') ?: 'Q2861001B')),
"pdf_max_age_days" => (int) (getenv('PDF_MAX_AGE_DAYS') ?: 30),
"pdf_public_employers" => array_map('trim', explode(',', getenv('PDF_PUBLIC_EMPLOYERS') ?:
    'COMUNIDAD DE MADRID CONSEJERIA DE EDUCACI,COMUNIDAD MADRID A.TERRITORIALES')),

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

exec "$@"
