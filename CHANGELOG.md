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
