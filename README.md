# What's this project?
With this project we are trying to develop an online voting system based on the following:
* Discriminate partitipats by email address domain.
* Identify participants by digitally signed PDFs, so each person can vote only once. Interim
  teachers upload the appointment (_nombramiento_) of the current school year on its own.
  Career civil servants upload the training record (_extracto de formación_) together with
  the MUFACE membership certificate: the DNI is read from the training record and the one in
  the certificate must be the same. Both can also use the teaching credential (_credencial
  docente_) or the service record (_hoja de servicios_) on their own.
  The signatures are checked.
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
* The `openssl` and `pdftotext` (poppler-utils) commands, for checking the signed PDF.

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
* `pdf_ca_file` and `pdf_signer_ids` set who is trusted for signing the training record
  PDF: the certification authorities in `certs/extracto_ca.pem` and the Comunidad de Madrid
  electronic seal (`S7800001E`) by default.
* `muface_ca_file` and `muface_signer_ids` set who is trusted for signing the MUFACE
  membership certificate: the certification authorities in `certs/muface_ca.pem` and the MUFACE
  electronic seal (`Q2861001B`) by default.
* `pdf_max_age_days` is how old the MUFACE certificate can be (30 days by default).
* `nombramiento_ca_file` and `nombramiento_signer_orgs` set who is trusted for signing the
  appointment: the certification authorities in `certs/nombramiento_ca.pem` and the
  `organizationIdentifier` of the signer's certificate, Comunidad de Madrid (`VATES-S7800001E`)
  by default. It is signed with the personal certificate of a public employee. Its body is a
  scanned image, so the DNI and the date are read from the PDF title, which the signature
  covers too.
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

3. Open `http://localhost:8080` (or whichever port you set in `WEB_PORT`) and log in with
   the administrator account defined by `ADMIN_USER` / `ADMIN_PASSWORD`.

The first start takes a while: MariaDB loads the schema and the `web` container waits for
the database to be ready before serving requests.

### Environment variables

| Variable | Default | Description |
| --- | --- | --- |
| `WEB_PORT` | `8080` | Host port the application is published on. |
| `DB_NAME` | `mlsurvey` | Database name. |
| `DB_USER` | `mlsurvey` | Database user. |
| `DB_PASSWORD` | `mlsurvey` | Password for that user. |
| `DB_ROOT_PASSWORD` | `mlsurvey-root` | MariaDB `root` password. |
| `DB_PREFIX` | *(empty)* | Optional prefix for table names. |
| `SITE_URL` | `http://localhost:${WEB_PORT}/` | Public URL of the site (scheme, host, port and path). The participation links sent by email are built from it, never from the request's `Host` header. |
| `LOG_LEVEL` | `0` | Log level: `0` error, `1` warning, `2` info, `3` debug. Use `0` in production. |
| `ALTCHA_ENABLED` | `true` | ALTCHA captcha on the participation request form. Set it to `false` when the site is served over plain HTTP: the proof of work needs Web Crypto, only available on secure contexts (HTTPS or `localhost`). |
| `ALTCHA_HMAC_KEY` | *(empty)* | Key used to sign the captcha challenges. When empty, a key is generated for each session. |
| `PARTICIPANT_HMAC_KEY` | `cambiame` | Key of the HMAC that hashes the email addresses and DNIs of the participants. The default is the development value the hashes of `test-data.sql` are computed with: in production set your own (`openssl rand -hex 32`) once and never change it. |
| `PDF_CA_FILE` | `certs/extracto_ca.pem` | PEM file with the certification authorities trusted for the signature of the training record PDF. |
| `PDF_SIGNER_IDS` | `S7800001E` | Comma separated `serialNumber` (NIF) of the certificates allowed to sign the PDF. |
| `MUFACE_CA_FILE` | `certs/muface_ca.pem` | PEM file with the certification authorities trusted for the signature of the MUFACE membership certificate. |
| `MUFACE_SIGNER_IDS` | `Q2861001B` | Comma separated `serialNumber` (NIF) of the certificates allowed to sign it. |
| `PDF_MAX_AGE_DAYS` | `30` | How old the MUFACE certificate can be, counting from its issue date. |
| `NOMBRAMIENTO_CA_FILE` | `certs/nombramiento_ca.pem` | PEM file with the certification authorities trusted for the signature of the appointment. |
| `NOMBRAMIENTO_SIGNER_ORGS` | `VATES-S7800001E` | Comma separated `organizationIdentifier` of the organizations whose employees can sign the appointment. |
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