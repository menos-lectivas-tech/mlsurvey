# What's this project?
With this project we are trying to develop an online voting system based on the following:
* Discriminate partitipats by email address domain.
* Identify participants by official PDFs, so each person can vote only once. Interim
  teachers, and those appointed to any post (director, head of studies, secretary...),
  can upload the appointment (_nombramiento_) of the current school year. Both
  interim teachers and career civil servants can use the teaching credential (_credencial
  docente_), the service record (_hoja de servicios_) or a payslip (_nómina_) of a teaching
  body (maestros, profesores or catedráticos) of the current or previous month. The
  appointment, the credential and the service record may be signed by hand, and the payslip
  is not signed, so only their content and dates are checked.
* When registering, participants identify themselves with their client certificate
  (_certificado electrónico_): the DNI electrónico, the FNMT one, the public employee one or
  any other qualified certificate of a natural person of the Spanish Trusted List (ACCV,
  Izenpe, Camerfirma, notaries...). The DNI of the uploaded document
  must be the one of the certificate, so nobody can register with someone else's documents.
* The email address is only used to verify the account: when registering, a link with a
  verification code is sent to it (valid for 30 minutes, only its SHA-256 is stored in
  `Verifications`). Once the account is verified, participants vote identifying themselves
  with the same client certificate: no email is sent for voting. Until the link is opened
  they can ask for another one (or fix a mistyped address) in «Regístrate», identifying
  themselves with the certificate; if it expires, the DNI and the address can be registered
  again.
* The system needs to be as anonymous as possible.

To archieve this the DNI and the email address are stored with a deterministic Hash function
(SHA-256), each one in its own unique column (`participant` for the email address and
`dnihashed` for the DNI), so the same documents can't be used from several
email addresses; the PDF is not stored.
Clearly, someone with access to the database could compromise anonymity by hashing every possible DNI. This is something we will aim to address in future releases.

Votes are not linked to participants: `Responses` stores each vote with no reference to who
cast it, and `Voted` only records which participants have already voted in each survey (with
no date nor sequence number), so each person can vote only once but a vote can't be traced
back to its author from the database.

# Installation

## Manual installation
### Requirements
This application needs a LAMP server:
* Apache + PHP >=8.3 with PDO and PDO_mysql
* MariaDB >=11.4
* Composer
* HTTPS with client certificate authentication (`SSLVerifyClient`), see below.
* The poppler-utils (`pdftotext`, `pdfinfo`, `pdftoppm`) commands, for reading the PDF, and `tesseract` with the Spanish data (`tesseract-ocr-spa`), for reading with OCR the documents that are a photo or a scan.

MariaDB must be initialized and configured with an user and a database for the application.

### Download
   Once you have installed and configured all the requirements download this repository to your site's `DocumentRoot` and install the required

### Install Composer modules
```sh
composer require hugerte/hugerte
composer require phpmailer/phpmailer
composer require zoon/php-bounce-handler
```
Or just run
```sh
composer update
```

If you need to install languages packs for HugeRTE follow [these instructions](https://github.com/hugerte/hugerte-docs#localization)

### Create the database
```sh
mariadb -u user -p databasename < modelo_datos.sql
```

### Configure the application
<sub>(_The `php ...` commands may need to be launched with `sudo` or `sudo -u www-data` 
or `sudo -u http`, depending on your system_)</sub>

Enter into `config` directory, copy or rename the file `config.php.sample` to `config.php`
and restric permissions as it contains sensible information.

Every configuration item has a description, you only need to notice:
* `site_url` is mandatory: it is the public URL of the site and the participation
  account verification links sent by email are built from it.
* `participant_hmac_key` is mandatory: the key of the HMAC that hashes the email
  addresses and DNIs of the participants. Generate it once (`openssl rand -hex 32`)
  and keep it: with another key no registered participant is recognized.
* If you have a working Sendmail in your system you only need to set:
```php
"email_method" => "Sendmail",
"email_server" => "",
"email_port" => 587,
"email_user" => "",
"email_password" => "", //Server password
"email_from" => "no-reply@domain.com", //Sender address
"email_encryption" => "", //ssl or tls for starttls.
```
* `pdf_max_age_days` is how old the service record (hoja de servicios) can be (30 days by
  default), counting from the issue date in its text. The payslip (nómina) must be of the
  current month or the previous one.
* `client_cert_enabled` (`true` by default) asks for the client certificate when
  registering and when voting. The browser only asks for it during the
  TLS handshake, so registering and voting are served by their own virtual host, `registration_url`
  (e.g. `https://registro.example.org/`, with the same `DocumentRoot`). The rest of the
  site (the surveys, the results, the account verification link) doesn't ask for it: the
  «Participar en la consulta» button of each survey goes to `participate?sid=N` there.
  Without it (only for development) voting asks for the DNI in a form, so anybody can vote
  as anybody. It must be the host of
  `site_url` in another port or a subdomain of it: the CAPTCHA and the form token are kept
  in the session, and its cookie must reach both. Only that virtual host asks for the
  certificate; with Apache mod_ssl:
  ```apache
  <VirtualHost *:443>
      ServerName registro.example.org
      DocumentRoot /var/www/mlsurvey
      # ... SSLEngine, server certificate ...
      # The CAs accepted, made by buildclientca.php (see below).
      SSLCACertificateFile /etc/ssl/mlsurvey/client_ca.pem
      SSLVerifyClient optional_no_ca
      SSLVerifyDepth 4
      SSLOptions +StdEnvVars +ExportCertData
  </VirtualHost>
  ```
  `optional_no_ca` lets an expired certificate or one of another CA through as `FAILED`, so
  the application explains the reason instead of the browser showing a TLS error; only
  `SUCCESS` is accepted. Asking for it only in some paths of the main site (`<Location>`)
  doesn't work: it needs TLS renegotiation, which TLS 1.3 and HTTP/2 don't support. Behind a TLS proxy, set `client_cert_verify_var` and `client_cert_var` to
  the headers where it passes the result and the certificate (e.g.
  `HTTP_X_SSL_CLIENT_VERIFY` and `HTTP_X_SSL_CLIENT_CERT`), and make sure the proxy always
  overwrites them. The certificate and its DNI are not stored. Revocation is not checked
  by the application: configure it in the server (`SSLOCSPEnable`) if needed.

  The DNI/NIE is read from the `serialNumber` of the subject (`12345678Z`,
  `IDCES-12345678Z`...), from the `subjectAltName` of the FNMT and public employee
  profiles, or from the CN (`... - 12345678Z`, `... - NIF 12345678Z`,
  `12345678Z NAME (R: B12345678)`); certificates without the DNI/NIE of a natural person
  (seals, components, foreign ones) are rejected.

  `buildclientca.php` makes the CA file from the Spanish Trusted List (TSL,
  `https://tsl.digital.gob.es/TSL.xml`): the qualified CAs for electronic signatures in
  force, with the subordinate CAs of the DNIe root and the issuers needed up to the roots
  (taken from the TSL, the CAs of the system or their AIA). Run it regularly, as the TSL
  changes, and reload the web server:
  ```sh
  php buildclientca.php /etc/ssl/mlsurvey/client_ca.pem && systemctl reload apache2
  ```
  It warns about the CAs whose root it could not find: download those roots from the
  provider's website and pass them in a PEM file as third argument
  (`php buildclientca.php out.pem "" extra_roots.pem`).

  Cl@ve PIN and Cl@ve permanente are not certificates: they can't be used this way (they
  need integrating with Cl@ve, only available to public administrations).
* If you plan to use a fake participant for testing the server you must set
```php
"ml_stresstest" => true,
```
and create the test participant with
```sh
mariadb -u user -p databasename < addtestparticipant.sql
```
as this participant must have an id of 0.

Finally add an administrator user with
```sh
php addusercmd.php username password
```
And then
1. Launch a browser.
2. Go to the site.
3. Admin menu
4. Login
5. Configure the application.

The participation request form is protected with [ALTCHA](https://altcha.org/), a
proof of work captcha that needs no external service: the widget is served from
`js/altcha/` and the challenges are generated and checked by `utils/altcha.php`.

### Configure the mail bounce checker
For avoiding conflicts with a wrong typed email address the application comes with a script 
(`checkbounce.php`) that cleans the `Participats`: when the account verification message
bounces, the registration is deleted and the DNI can be registered again.
You need to feed the script with a bouced email and the script deletes the row.

These are the steps for doing it with postfix (maybe you need to change users, account names
 and directories):
1. Add to `/etc/postfix/virtual_map` (or your virtual map file) the line:
   ```
   no-reply  www-data@domain
   ```
2. Add a pipe to `/etc/postfix/master.cf`:
   ```
   mlsurveybounce unix - n  n - - pipe flags=FR user=www-data directory=/var/www/mlsurvey argv=/usr/bin/php checkbounce.php
   ```
3. Add to `/etc/postfix/transport` (or your transport file) the line:
   ```
   www-data@dominio mlsurveybounce:
   ```
4. Update postfix databases:
  ```sh
  sudo postmap /etc/postfix/virtual_map
  sudo postmap /etc/postfix/transport
  ```
5. If you didn't have virtual maps or transports in postfix, add them to `/etc/postfix/main.cf`
  ```
  virtual_maps = hash:/etc/postfix/virtual_map
  transport_maps = hash:/etc/postfix/transport
  allow_mail_to_commands = alias,forward,include
  ```
6. Reload Postfix configuration.

## Running the application with Docker Compose

The repository ships a `Dockerfile` (PHP 8.3 + Apache) and a `docker-compose.yml` that
brings up two services: `web` (the application) and `db` (MariaDB 11.4). The image
installs the dependencies mentioned above (PHPMailer and HugeRTE) on its own, so there is
no need to run `composer` by hand.

### Requirements

* Docker Engine with the Compose v2 plugin (`docker compose`, no hyphen).

### Getting started

1. Copy the sample environment file and adjust it:

   ```sh
   cp .env.dev .env
   ```

   Change at least `DB_PASSWORD`, `DB_ROOT_PASSWORD` and `ADMIN_PASSWORD`.
   Docker Compose reads `.env` automatically; if you skip this step, the defaults declared
   in `docker-compose.yml` are used.

2. Build the image and start the containers:

   ```sh
   docker compose up -d --build
   ```

3. Open `https://localhost:8443` (or whichever port you set in `WEB_SSL_PORT`) and log in
   with the administrator account defined by `ADMIN_USER` / `ADMIN_PASSWORD`. The server
   certificate is self-signed, so the browser warns about it the first time; mount your own
   `server.crt` and `server.key` in the `ssl` volume (`/etc/ssl/mlsurvey`) for production.
   Plain HTTP (`WEB_PORT`, 8080) redirects to `SITE_URL` (see `HTTPS_REDIRECT`).
   The registration page («Regístrate») and the voting page are served at `https://localhost:8444`
   (`WEB_REG_PORT`, `REGISTRATION_URL`), the only port that asks for the client
   certificate: the browser asks for it when the page is opened, before the wizard or the
   survey is shown.
   «Regístrate» on the site first warns in a dialog that a personal certificate will be asked
   for, whose DNI must match the one of the document, and goes there if accepted.
   Without a valid certificate the page shows an error and goes back to the site. It only offers the
   certificates it has installed (Firefox: Settings → Certificates; Chrome/Edge: the ones of
   the system) and, for the DNIe, its reader and driver must be installed.

The first start takes a while: MariaDB loads the schema and the `web` container waits for
the database to be ready before serving requests.

### Environment variables

| Variable | Default | Description |
| --- | --- | --- |
| `WEB_PORT` | `8080` | Host port of plain HTTP, which redirects to `SITE_URL`. |
| `WEB_SSL_PORT` | `8443` | Host port of HTTPS: the site, without client certificate. |
| `WEB_REG_PORT` | `8444` | Host port of the registration and voting HTTPS, which asks for the client certificate. |
| `REGISTRATION_URL` | `https://localhost:${WEB_REG_PORT}/` | Public URL of the registration and voting (port 444 of the container). |
| `DB_NAME` | `mlsurvey` | Database name. |
| `DB_USER` | `mlsurvey` | Database user. |
| `DB_PASSWORD` | `mlsurvey` | Password for that user. |
| `DB_ROOT_PASSWORD` | `mlsurvey-root` | MariaDB `root` password. |
| `DB_PREFIX` | *(empty)* | Optional prefix for table names. |
| `SITE_URL` | `https://localhost:${WEB_SSL_PORT}/` | Public URL of the site (scheme, host, port and path). The account verification links sent by email are built from it, never from the request's `Host` header. |
| `LOG_LEVEL` | `0` | Log level: `0` error, `1` warning, `2` info, `3` debug. Use `0` in production. |
| `ALTCHA_ENABLED` | `true` | ALTCHA captcha on the participation request form. Set it to `false` when the site is served over plain HTTP: the proof of work needs Web Crypto, only available on secure contexts (HTTPS or `localhost`). |
| `ALTCHA_HMAC_KEY` | *(empty)* | Key used to sign the captcha challenges. When empty, a key is generated for each session. |
| `PARTICIPANT_HMAC_KEY` | `cambiame` | Key of the HMAC that hashes the email addresses and DNIs of the participants. The default is the development value the hashes of `test-data.sql` are computed with: in production set your own (`openssl rand -hex 32`) once and never change it. |
| `PDF_MAX_AGE_DAYS` | `30` | How old the service record can be, counting from its issue date. |
| `CLIENT_CERT_ENABLED` | `true` | Ask for the client certificate when registering and voting (only in port 444, see `docker/apache-sites.conf`). The accepted CAs are made from the TSL by `buildclientca.php` on the first start and kept in the `ssl` volume; to refresh them run `docker compose exec web sh -c 'php buildclientca.php /etc/ssl/mlsurvey/client_ca.pem && apache2ctl graceful'`. Set it to `false` only for development: voting then asks for the DNI (`test-data.sql` has a verified participant with DNI `00000000T`). |
| `CLIENT_CERT_VERIFY_VAR`, `CLIENT_CERT_VAR` | `SSL_CLIENT_VERIFY`, `SSL_CLIENT_CERT` | `$_SERVER` variables with the verification result (`SUCCESS`) and the certificate. With a TLS proxy in front that verifies it, the headers where it passes them (e.g. `HTTP_X_SSL_CLIENT_VERIFY`, PEM or URL-encoded PEM); the proxy must always overwrite them. |
| `HTTPS_REDIRECT` | `true` | Redirect plain HTTP to `SITE_URL` (only if it is `https://`). Set it to `false` when a TLS proxy reaches the container over HTTP. |
| `CLIENT_CA_EXTRA` | *(empty)* | PEM file (path inside the container) with root CAs the TSL doesn't bring, see `buildclientca.php`. |
| `ADMIN_USER`, `ADMIN_PASSWORD` | `admin` / `admin` | Initial administrator; created on startup if it does not exist yet. |

The entrypoint generates `config/config.php` from these variables on every start. If you
would rather manage that file yourself, mount it into the container: when the entrypoint
finds a `config/config.php` it did not generate, it leaves it untouched.

### Initial data

`modelo_datos.sql` (schema) and `test-data.sql` (sample data) are loaded **only the first
time**, while the `db_data` volume is still empty. `test-data.sql` is example material:
for a real deployment, comment out that volume line in `docker-compose.yml` before the
first start.

To start from scratch and reload the `.sql` files, the volume has to be removed:

```sh
docker compose down -v
docker compose up -d --build
```

Careful: `-v` also removes the `uploads` volume, that is, the files attached to surveys.

### Everyday commands

```sh
docker compose logs -f web     # PHP errors and the Apache log
docker compose exec web bash   # shell inside the container
docker compose restart web     # restart just the application
docker compose down            # stop the containers, keeping the data
```

# Update
When updating the application don't forget run:
```sh
php updatedb.php
```
for updating the database tables if needed.