# Unreleased
- Removed the verification of participants with digitally signed documents (and the
  Register page). Participating only needs an `educa.madrid.org` email address: the
  participant is registered on its first request and the participation link is sent to
  that address. The `pdf_*`, `muface_*` and `nombramiento_*` settings and the `certs/`
  folder are no longer used, nor are openssl and poppler-utils.
- Database version 9 drops the `dnihashed` column of `Participants`.
- The unsubscription form is on the participation page and checks the captcha.

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
