# 1.8
- Se vota identificándose con el certificado electrónico, igual que al registrarse: el botón «Participar en la consulta» lleva a `participate?sid=N` en `registration_url`, que lee el DNI del certificado y busca la cuenta registrada con él. Ya no se envían correos para votar: desaparecen el formulario de correo de cada consulta, los enlaces de participación y la página `resend`.
- El correo solo sirve para verificar la cuenta: al registrarse se envía un enlace (`verify?code=...`) que caduca en 30 minutos y hasta que no se abre no se puede votar. Nueva tabla `Verifications`, que guarda solo el SHA-256 del código. Si el enlace no llega o caduca, en «Regístrate» (identificándose con el certificado) se puede pedir otro, con 5 minutos entre envíos, o corregir la dirección. Un registro que no se verifica a tiempo deja libres su DNI y su dirección.
- `updatedb.php` crea `Verifications` y da por verificadas las cuentas que ya estaban registradas con DNI.
- Con `client_cert_enabled` a `false` (solo desarrollo), al votar se pide el DNI en un formulario.
- El asistente de registro pasa a tener dos pasos: desaparece la elección entre interino/a y funcionario/a de carrera y se ofrecen siempre todos los documentos (nómina, nombramiento, hoja de servicios y credencial).

# 1.7
- Al registrarse hay que identificarse con un certificado electrónico cualificado de persona física (DNI electrónico, FNMT, empleado público, representante, ACCV, Izenpe, Camerfirma, notarios...): se lee el DNI/NIE del `serialNumber` del sujeto, del `subjectAltName` (perfiles FNMT y de empleado público) o del CN, y tiene que coincidir con el del documento adjuntado. `buildclientca.php` genera el fichero de CA admitidas a partir de la lista de confianza (TSL) española. El certificado solo se pide al enviar el documento y el correo en el último paso del registro, no al votar ni al rellenar el asistente: ese envío va a su propia URL (`registration_url`), la única que lo pide. Tiene que ser el host del sitio en otro puerto o un subdominio suyo (la cookie de sesión se extiende a él). En Docker, HTTPS en `WEB_SSL_PORT` (sitio) y `WEB_REG_PORT` (registro), y HTTP redirige a HTTPS. La cadena la verifica el servidor web o el proxy TLS; nuevos parámetros `client_cert_enabled`, `client_cert_verify_var` y `client_cert_var` (en Docker, `CLIENT_CERT_*`). No se guarda ni el certificado ni su DNI.
- Se quita la opción de acreditarse con el extracto de formación y el certificado de afiliación a MUFACE. Se eliminan los parámetros `pdf_ca_file`, `pdf_signer_ids`, `muface_ca_file` y `muface_signer_ids`, `certs/extracto_ca.pem` y `certs/muface_ca.pem`, y la dependencia de `openssl`.
- Se admite la nómina de la Consejería de Educación, para interinos y funcionarios de carrera. Se lee el DNI (quitando la cifra que a veces lleva pegada detrás de la letra), se comprueba que el cuerpo docente sea de maestros, profesores o catedráticos o que la vinculación sea de función docente (`FUNC. DOCENTE`), y que el mes liquidado sea el mes en curso o el anterior (en noviembre valen la de octubre y la de noviembre). La nómina no lleva firma: solo se comprueba su contenido.

# 1.6
- Se deja de comprobar la firma del nombramiento, la credencial y la hoja de servicios, que pueden venir firmados a mano: de ellos se comprueba solo el contenido y las fechas que aparecen en el texto. Se eliminan los parámetros `nombramiento_ca_file` y `nombramiento_signer_orgs` y `certs/nombramiento_ca.pem`.
- Se admite el formato de hoja de servicios de la DAT, con el DNI sin letra.

# 1.5.1
- Se cambia el orden en la PK de Voted para que no sea posible relacionar la participación con el orden de inserción.

# 1.5
- Se añade el poder hacer OCR ya que algunas veces los documentos son escaneos.

# 1.4.1
- Added privacypolicy.html manage.
# 1.4
- Minor change in survey_manage UX.
- Add unsubscription functionality.
- Minor fixes.

# 1.3
- For security reasons DB storage of personal data changes from hash to hmac. The key is defined in config.php.
- Changes for allowing "hojas de servicio" and "credenciales" for validating participants.
- The work history report (informe de vida laboral) is replaced by the digitally signed
  appointment (nombramiento) of the current school year. `pdf_public_employers` is no longer
  used; new `nombramiento_ca_file` and `nombramiento_signer_orgs` settings. The appointment
  is enough on its own: it no longer needs the training record (extracto de formación).
- The appointment must be of the current school year and already in force. The service
  record and the teaching credential must be signed in the current school year. The three
  of them are checked against the CAs of `nombramiento_ca_file` (`certs/nombramiento_ca.pem`
  trusts both the Camerfirma and the FNMT certificates), and the `organizationIdentifier` of
  the signer must match the `nombramiento_signer_orgs` regular expression: the Comunidad de
  Madrid and the public bodies of Madrid, as the schools, by default. Before, any certificate
  issued by the FNMT CAs of `muface_ca.pem` was accepted.
- The documents form is now a three step wizard: situation (interim or career/trainee civil
  servant), the document to use among those valid for it, and the upload of its files.


# 1.2
- Votes are no longer linked to participants: the new `Voted` table records who has voted
  in each survey and `Responses` drops `participantid` and the per participant signature
  (`responsesign`), which identified the voter as well. The key pair columns of
  `Participants` and `verify_responses.php` are removed (run `php updatedb.php`).
- The script `checkbounce.php` can be connected to an email receiver for delete from `Participants`
  table when email address is not reachable. Configuration instructions in `README.md`.
  As this script needs to delete rows in `Participants` table the trigger that prevents this is
  dropped.

# 1.1
- Added a Register page for registering before vote.
- Added a Resend page (not in the menu) for sending the participation URL again to
  someone who has already requested it.

# 1.0.1
- Added versioning to SystemConfig table

# 1.0
- Participation now requires the digitally signed training record PDF (extracto de
  formación): the participant is identified by the hash of the DNI read from it.
- `Participants.participant` keeps the hash of the email address and the new column
  `dnihashed` stores the hash of the DNI. Both are unique: the same documents can't be
  used from several addresses (run `php updatedb.php`).
- Participation also requires a PDF that proves the participant is a civil servant: the
  digitally signed MUFACE membership certificate (Clases Pasivas) or the work history report,
  informe de vida laboral (the rest: career civil servants who joined from 2011 on and
  interim ones). Its DNI must be the one in the training record.
- Added an ALTCHA captcha to the participation request form.
- Dockerized
- Changes in index.php for including acknowledgments in subfooter.
- Change themes colors to neutral greys.
- Added password change script.
- Preparing for internationalization.
- Added es.yml and en.yml to internationalization.

# 0.2-alpha
- Added upload file suppor for survey documentation
- Change participation to an emailed clickable link instead to validate email and code.
- Changed public surveys frontend.
