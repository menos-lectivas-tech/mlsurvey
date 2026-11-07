# 1.6
- Registration asks for a single document: the self-declaration as a teacher
  (`docs/modelo_declaracion.pdf`), a PDF form with the DNI/NIE and the EducaMadrid email
  address, signed with the participant's own personal certificate. The DNI of the form must
  be the one of the signer's certificate, and the participant is registered with the email
  address of the declaration: the forms no longer ask for it. The documents wizard (interim /
  career civil servant) and the checks of the appointment, service record, teaching
  credential, MUFACE certificate and training record are removed, with their settings
  (`pdf_*`, `muface_*`, `nombramiento_*`). New `declaracion_ca_file`,
  `declaracion_revocation` and `declaracion_email_domains` settings. Needs `qpdf`.
- New declaration model with a hidden, read-only `docid` field ("autodeclaración docente")
  that identifies it: the signed declaration must have it, besides the title, so PDFs filled
  in from the previous model are rejected.
- The declaration can be signed with the qualified certificate of any official provider
  (DNIe, FNMT, ACCV, Camerfirma, Izenpe...), but not with a made-up one: `declaracion_ca.pem`
  now has the CAs of the Spanish trusted list (TSL) for qualified signature certificates,
  generated with the new `updatecas.php`, and they are trust anchors even if intermediate
  (`-partial_chain`), so no other CA under the same root is accepted (before, any CA under the
  FNMT root was, if it came in the signature). The DNIe intermediates, not in the TSL, are in
  `certs/declaracion_intermediates.pem` (new `declaracion_intermediates_file` setting), only
  for building the chain. The certificate must also be qualified (eIDAS QcCompliance) and for
  signing (keyUsage digitalSignature or nonRepudiation). The OCSP check works with the DNIe
  responders.
- The declaration can be signed in the form itself, without downloading, filling in and
  uploading the model: the participant writes the email address (already filled in when it
  comes from a survey), AutoFirma asks for the certificate, `declaracion_pdf` fills in the model
  with the DNI/NIE of that certificate and the address, AutoFirma signs it (PAdES, in the
  signature field of the model) with the same certificate and the form sends it, checked as an
  uploaded one. Once accepted, the signed declaration is downloaded in
  the result page (`js/declaracion-copia.js`, through `sessionStorage`), so the participant
  keeps a copy.
  Uploading a signed PDF is still available. Ships `autoscript.js` 1.9 of the
  Cliente @firma and its intermediate server (`afirma_storage`, `afirma_retrieve`) for mobile
  devices. Needs qpdf >= 11.
- The identification with a digital certificate (`cert_login`, `clientcert_*` settings and
  the host on `CLIENTCERT_PORT`) is removed: the participation link is always sent by email,
  so the address must exist.
- Unused code removed: the old `response_survey` view (email + code form), the partial
  results code of `participate`, `MLAsymmetricCrypt`, `hasCode`, unused constants and
  helpers, commented-out code, and the leftovers of the original web template (fancybox,
  quicksand/portfolio, animate, easing, prettify, local icon fonts, extra select2 builds and
  translations, and ~1000 lines of CSS rules for markup the site doesn't have).

# 1.5
- Participants can identify with a digital certificate (TLS client certificate) instead of
  the email address: the new `cert_login` page reads the DNI/NIE of the certificate and, if
  it is registered, shows the survey form right away. If it isn't, the documents wizard is
  shown (their DNI must be the one of the certificate) and, once registered, the survey form.
  New `clientcert_enabled`, `clientcert_url` and `clientcert_ca_file` settings; Apache must
  ask for the certificate (see `README.md` and `docker/apache-clientcert.conf.example`).
  Revocation is checked by PHP (`clientcert_revocation`, `CLIENTCERT_REVOCATION`): with OCSP
  and, if the responder doesn't answer, with the CRL of the certificate, so an outage of the
  FNMT OCSP service doesn't block the identification.
- Docker Compose serves the application over HTTPS (`HTTPS_PORT`, 8443), with a self-signed
  certificate when none is mounted; plain HTTP redirects to `SITE_URL`. With
  `CLIENTCERT_ENABLED=true` the host that asks for the digital certificate is served on
  `CLIENTCERT_PORT` (8444).

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
