# What's this project?
With this project we are trying to develop an online voting system based on the following:
* Discriminate partitipats by email address domain.
* Identify participants by a digitally signed PDF, so each person can vote only once. The
  first time, participants download the self-declaration model (`docs/modelo_declaracion.pdf`),
  fill in their DNI/NIE and their EducaMadrid email address and sign it with their own
  personal certificate (FNMT, DNIe...). The signature is checked, the DNI written in the form
  must be the one of the signer's certificate, the hidden `docid` field of the model must be
  there and the participant is registered with the email
  address of the declaration. The participation link is always sent to that address, so it
  must exist.
  The declaration can also be signed right in the registration form: the participant writes the
  email address, AutoFirma (the signing app of the Spanish Government, that must be installed)
  asks for the certificate, the server fills in the model with the DNI/NIE of that certificate
  and the address, AutoFirma signs it and the form sends it. It is checked like an uploaded one.
* The system needs to be as anonymous as possible.

To archieve this the DNI and the email address are stored with a deterministic Hash function
(SHA-256), each one in its own unique column (`participant` for the email address and
`dnihashed` for the DNI), so the same DNI can't be used from several
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
* The `openssl`, `pdftotext` (poppler-utils) and `qpdf` (>= 11) commands, for checking the signed
  PDF and filling in the declaration that is signed in the browser.

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
  links sent by email are built from it.
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
* `declaracion_ca_file` sets the certification authorities trusted for the certificate that
  signs the declaration, `certs/declaracion_ca.pem` by default. The bundled file has the CAs
  of the Spanish trusted list (TSL, `https://tsl.digital.gob.es/TSL.xml`) for qualified
  certificates for electronic signatures (services CA/QC, granted, ForeSignatures): DNIe, FNMT,
  ACCV, Camerfirma, Izenpe, etc. They are trust anchors even when they are intermediate CAs, so
  any other CA under the same root (components, seals, web servers) isn't accepted. Regenerate
  it with `php updatecas.php` when the TSL changes (its next update date is in the header of
  the file) and review the diff before committing it. The certificate must also be qualified
  (qcStatements with eIDAS QcCompliance) and for signing (keyUsage digitalSignature or
  nonRepudiation). The DNI/NIE of the signer is read from the `serialNumber` of the
  certificate subject (`IDCES-12345678Z` or `12345678Z`); certificates without a personal DNI
  (electronic seals, components) are rejected.
* `declaracion_intermediates_file` (`certs/declaracion_intermediates.pem`, also written by
  `updatecas.php`) has intermediate CAs that may be needed for building the chain and that
  aren't in the TSL: the `AC DNIE 00x` of the DNIe, whose TSL entry is the root
  `AC RAIZ DNIE 2`. They aren't trusted by themselves: the chain must end in
  `declaracion_ca_file`.
* `declaracion_revocation` checks that the signer's certificate isn't revoked
  (`utils/certrevocation.php`): first with the OCSP responder of the certificate and, if it
  doesn't answer, with its CRL (the FNMT splits it in partitions, so only the one of the
  certificate is downloaded and cached for 3 hours). If neither can be used, the declaration
  is rejected. Turn it off only for tests.
* `declaracion_email_domains` lists the domains allowed for the email address of the
  declaration (`educa.madrid.org` by default).
* Signing the declaration in the browser needs nothing else on the server: in desktop browsers
  `js/afirma/autoscript.js` (from the [Cliente @firma](https://github.com/ctt-gob-es/clienteafirma))
  talks to AutoFirma through a local WebSocket. In Android and iOS it uses the @firma
  intermediate server, which the application provides itself (`afirma_storage` and
  `afirma_retrieve`, `utils/afirmaproxy.php`); the messages are kept encrypted for a few minutes
  in the system temporary directory.
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
(`checkbounce.php`) that cleans the `Participats`.
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

3. Open `https://localhost:8443` (or whichever port you set in `HTTPS_PORT`) and log in with
   the administrator account defined by `ADMIN_USER` / `ADMIN_PASSWORD`. Plain HTTP
   (`WEB_PORT`, 8080) redirects to `SITE_URL`.

### HTTPS

Apache serves the application over HTTPS on port 443 of the container (published on
`HTTPS_PORT`). If there is no certificate in `SSL_CERT_FILE` / `SSL_KEY_FILE`, the entrypoint
generates a self-signed one for `SSL_HOSTNAME` (plus `localhost` and `127.0.0.1`) and keeps
it in the `ssl` volume; the browser warns about it the first time. To use your own
certificate, mount it into the container, e.g. replacing the `ssl` volume in
`docker-compose.yml` with `./docker/ssl:/etc/mlsurvey/ssl:ro` holding `server.crt` (with
the intermediates) and `server.key`. If a reverse proxy terminates TLS in front of the
container and talks HTTP to it, set `HTTPS_REDIRECT=false`.

The first start takes a while: MariaDB loads the schema and the `web` container waits for
the database to be ready before serving requests.

### Environment variables

| Variable | Default | Description |
| --- | --- | --- |
| `WEB_PORT` | `8080` | Host port plain HTTP is published on (it redirects to `SITE_URL`). |
| `HTTPS_PORT` | `8443` | Host port HTTPS is published on. |
| `SSL_HOSTNAME` | `localhost` | Name of the self-signed certificate generated when there is none. |
| `SSL_CERT_FILE`, `SSL_KEY_FILE` | `/etc/mlsurvey/ssl/server.crt` / `server.key` | Server certificate and key, paths inside the container. |
| `HTTPS_REDIRECT` | `true` | Redirect plain HTTP to `SITE_URL` when it is `https://`. |
| `DB_NAME` | `mlsurvey` | Database name. |
| `DB_USER` | `mlsurvey` | Database user. |
| `DB_PASSWORD` | `mlsurvey` | Password for that user. |
| `DB_ROOT_PASSWORD` | `mlsurvey-root` | MariaDB `root` password. |
| `DB_PREFIX` | *(empty)* | Optional prefix for table names. |
| `SITE_URL` | `https://localhost:${HTTPS_PORT}/` | Public URL of the site (scheme, host, port and path). The participation links sent by email are built from it, never from the request's `Host` header. |
| `LOG_LEVEL` | `0` | Log level: `0` error, `1` warning, `2` info, `3` debug. Use `0` in production. |
| `ALTCHA_ENABLED` | `true` | ALTCHA captcha on the participation request form. Set it to `false` when the site is served over plain HTTP (the Compose setup uses HTTPS): the proof of work needs Web Crypto, only available on secure contexts (HTTPS or `localhost`). |
| `ALTCHA_HMAC_KEY` | *(empty)* | Key used to sign the captcha challenges. When empty, a key is generated for each session. |
| `PARTICIPANT_HMAC_KEY` | `cambiame` | Key of the HMAC that hashes the email addresses and DNIs of the participants. The default is the development value the hashes of `test-data.sql` are computed with: in production set your own (`openssl rand -hex 32`) once and never change it. |
| `DECLARACION_CA_FILE` | `certs/declaracion_ca.pem` | PEM file with the certification authorities trusted for the certificate that signs the declaration. |
| `DECLARACION_INTERMEDIATES_FILE` | `certs/declaracion_intermediates.pem` | PEM file with intermediate CAs (not trusted) for building the chain of that certificate. |
| `DECLARACION_REVOCATION` | `true` | Check the revocation of that certificate (OCSP, or its CRL if the responder doesn't answer); `false` only for tests. |
| `DECLARACION_EMAIL_DOMAINS` | `educa.madrid.org` | Space separated domains allowed for the email address of the declaration. |
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