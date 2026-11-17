/*
 * test-data.sql - Datos de prueba para mlsurvey.
 *
 * Se carga automaticamente despues de modelo_datos.sql cuando docker compose
 * crea el volumen de la base de datos por primera vez.
 * Para recargarlo desde cero: docker compose down -v && docker compose up --build
 *
 * ATENCION: contiene credenciales triviales. Solo para desarrollo y pruebas.
 *
 * Usuarios de acceso al panel de administracion:
 *   admin        / admin123       (administrador)
 *   profesora    / profesora123   (sin privilegios)
 *
 */

/* ---------- Usuarios ---------- */
/* admin / admin123 */
INSERT INTO Users (username, passwd, `role`) VALUES ('admin', '$2y$10$b1Z0iC4TCYJvxa/KUVWWv.OzO/DAhil7f8rvZICS7BFTBtMeRvyBS', 'A');
/* profesora / profesora123 */
INSERT INTO Users (username, passwd, `role`) VALUES ('profesora', '$2y$10$loC.cYvR0R12ApxvPmvhNOeg4VYDmDLv5/tWsAFnH26Q8Aj.EXMpi', '');

/* ---------- Configuracion del sistema ---------- */
INSERT INTO SystemConfig (configid, timezone, alloweddomains, modifiedby)
    VALUES (1, 'Europe/Madrid', 'educa.madrid.org', 1);
/* La configuracion de correo ya no vive aqui: esta en config/config.php, que el
   entrypoint genera a partir de las variables EMAIL_* de docker-compose. */

/* ---------- Consultas, preguntas y opciones ---------- */
/* 1: Consulta sobre el horario lectivo */
INSERT INTO Surveys (surveyid, surveyname, surveydesc, surveyfile, startdate, enddate, createdby, modifiedby) VALUES
    (1, 'Consulta sobre el horario lectivo', '<p>Consulta <strong>finalizada</strong> sobre la distribucion del horario lectivo del profesorado. Los resultados ya estan publicados.</p>', NULL, DATE_SUB(NOW(), INTERVAL 30 DAY), DATE_SUB(NOW(), INTERVAL 10 DAY), 1, 1);
INSERT INTO Questions (surveyid, questionid, questiondesc, optional, multiple, file) VALUES
    (1, 1, '<p>&iquest;Cuantas horas lectivas semanales consideras adecuadas?</p>', 0, 0, NULL);
INSERT INTO Options (surveyid, questionid, optionid, optiondesc) VALUES
    (1, 1, 1, '18 horas'),
    (1, 1, 2, '20 horas'),
    (1, 1, 3, '23 horas'),
    (1, 1, 4, 'Mas de 23 horas');
INSERT INTO Questions (surveyid, questionid, questiondesc, optional, multiple, file) VALUES
    (1, 2, '<p>&iquest;Que medidas priorizarias? <em>(puedes elegir varias)</em></p>', 0, 1, NULL);
INSERT INTO Options (surveyid, questionid, optionid, optiondesc) VALUES
    (1, 2, 1, 'Reduccion de ratios'),
    (1, 2, 2, 'Menos burocracia'),
    (1, 2, 3, 'Mas desdobles'),
    (1, 2, 4, 'Mas tiempo de coordinacion');
INSERT INTO Questions (surveyid, questionid, questiondesc, optional, multiple, file) VALUES
    (1, 3, '<p>&iquest;Participarias en una comision de seguimiento?</p>', 1, 0, NULL);
INSERT INTO Options (surveyid, questionid, optionid, optiondesc) VALUES
    (1, 3, 1, 'Si'),
    (1, 3, 2, 'No');

/* 2: Actividades extraescolares del proximo curso */
INSERT INTO Surveys (surveyid, surveyname, surveydesc, surveyfile, startdate, enddate, createdby, modifiedby, showpartial) VALUES
    (2, 'Actividades extraescolares del proximo curso', '<p>Consulta <strong>abierta</strong>: se puede participar mientras este en plazo.</p>', NULL, DATE_SUB(NOW(), INTERVAL 3 DAY), DATE_ADD(NOW(), INTERVAL 27 DAY), 1, 1, true);
INSERT INTO Questions (surveyid, questionid, questiondesc, optional, multiple, file) VALUES
    (2, 1, '<p>&iquest;Que actividad te interesa mas?</p>', 0, 0, NULL);
INSERT INTO Options (surveyid, questionid, optionid, optiondesc) VALUES
    (2, 1, 1, 'Robotica'),
    (2, 1, 2, 'Teatro'),
    (2, 1, 3, 'Ajedrez'),
    (2, 1, 4, 'Deporte escolar');
INSERT INTO Questions (surveyid, questionid, questiondesc, optional, multiple, file) VALUES
    (2, 2, '<p>&iquest;En que franjas horarias la harias? <em>(puedes elegir varias)</em></p>', 1, 1, NULL);
INSERT INTO Options (surveyid, questionid, optionid, optiondesc) VALUES
    (2, 2, 1, 'Mediodia'),
    (2, 2, 2, 'Tarde temprana'),
    (2, 2, 3, 'Tarde'),
    (2, 2, 4, 'Sabado por la mañana');

/* 3: Calendario escolar 2027-2028 */
INSERT INTO Surveys (surveyid, surveyname, surveydesc, surveyfile, startdate, enddate, createdby, modifiedby) VALUES
    (3, 'Calendario escolar 2027-2028', '<p>Consulta <strong>pendiente</strong>: aun no ha comenzado el plazo de participacion.</p>', NULL, DATE_ADD(NOW(), INTERVAL 15 DAY), DATE_ADD(NOW(), INTERVAL 45 DAY), 1, 1);
INSERT INTO Questions (surveyid, questionid, questiondesc, optional, multiple, file) VALUES
    (3, 1, '<p>&iquest;Prefieres jornada continua o partida?</p>', 0, 0, NULL);
INSERT INTO Options (surveyid, questionid, optionid, optiondesc) VALUES
    (3, 1, 1, 'Jornada continua'),
    (3, 1, 2, 'Jornada partida');
INSERT INTO Questions (surveyid, questionid, questiondesc, optional, multiple, file) VALUES
    (3, 2, '<p>&iquest;Mantendrias la semana blanca de febrero?</p>', 1, 0, NULL);
INSERT INTO Options (surveyid, questionid, optionid, optiondesc) VALUES
    (3, 2, 1, 'Si'),
    (3, 2, 2, 'No'),
    (3, 2, 3, 'Me es indiferente');

/* 4: Formacion permanente del profesorado */
INSERT INTO Surveys (surveyid, surveyname, surveydesc, surveyfile, startdate, enddate, createdby, modifiedby) VALUES
    (4, 'Formacion permanente del profesorado', '<p>Consulta <strong>abierta</strong> y aun sin respuestas: se puede participar mientras este en plazo.</p>', NULL, DATE_SUB(NOW(), INTERVAL 1 DAY), DATE_ADD(NOW(), INTERVAL 20 DAY), 1, 1);
INSERT INTO Questions (surveyid, questionid, questiondesc, optional, multiple, file) VALUES
    (4, 1, '<p>&iquest;Que modalidad de formacion prefieres?</p>', 0, 0, NULL);
INSERT INTO Options (surveyid, questionid, optionid, optiondesc) VALUES
    (4, 1, 1, 'Presencial'),
    (4, 1, 2, 'En linea'),
    (4, 1, 3, 'Mixta');
INSERT INTO Questions (surveyid, questionid, questiondesc, optional, multiple, file) VALUES
    (4, 2, '<p>&iquest;Sobre que temas te gustaria formarte? <em>(puedes elegir varias)</em></p>', 1, 1, NULL);
INSERT INTO Options (surveyid, questionid, optionid, optiondesc) VALUES
    (4, 2, 1, 'Competencia digital'),
    (4, 2, 2, 'Atencion a la diversidad'),
    (4, 2, 3, 'Convivencia'),
    (4, 2, 4, 'Idiomas');

/* ---------- Participantes ---------- */
/* participant = HMAC-SHA256(sha256(correo)) con PARTICIPANT_HMAC_KEY=cambiame
   (.env.dev): con otra clave estas participantes no se reconocen. */
/* 1: ana.responde@example.com */
INSERT INTO Participants (participantid, participant) VALUES
    (1, '249fc229b9083641750a676f067b9e127e5d80e150378610acf0bb10972b413e');
/* 2: bruno.responde@example.com */
INSERT INTO Participants (participantid, participant) VALUES
    (2, '99dd08e1f032b098c83c52fd43714986ad0d2c12f5ef625f479121a91ccd6962');
/* 3: carla.responde@example.com */
INSERT INTO Participants (participantid, participant) VALUES
    (3, '25390aef55fd59e1f6abd2e506320bfa88b2c3dbb760475578565e043599c78b');
/* 4: diego.responde@example.com */
INSERT INTO Participants (participantid, participant) VALUES
    (4, 'a1dae50f5c3f3258dc7ed6155aff5ee931e3f79a741051e652e9c28985f1dd6e');
/* 5: elena.responde@example.com */
INSERT INTO Participants (participantid, participant) VALUES
    (5, '0d63fc50c60e6e932cbf4704a231cb07017452e8a488dc235177d370d8dfdb4b');
/* 6: fatima.pendiente@example.com */
INSERT INTO Participants (participantid, participant) VALUES
    (6, '06fcf008f8460d3dd591566c2dab8fbbd2a9e488b6dba612a3ac1c48986a9f71');
/* 7: gorka.pendiente@example.com */
INSERT INTO Participants (participantid, participant) VALUES
    (7, 'd6e04c9db9ed1abfd7cb7cc5c07a51759fe357af715550d97af07c2168b41faf');

/* Con DNI, para votar (las de arriba son anteriores a los documentos y no
   pueden). Sin certificado (CLIENT_CERT_ENABLED=false) participate pide el DNI.
   8: hugo.verificado@example.com, DNI 00000000T, cuenta verificada. */
INSERT INTO Participants (participantid, participant, dnihashed) VALUES
    (8, 'eafa6a7545f3268c94603af76ed48b1317a019494a34270c3c52f8a0d55dd1eb',
     '84a5930e9bd68cfab9bd595cffc74ac1630f2e2ff56c14cb1d9cfaf2e265604b');
INSERT INTO Verifications (participantid, verifykey, verified) VALUES (8, NULL, 1);
/* 9: irene.pendiente@example.com, DNI 00000001R, sin verificar. Enlace de
   verificacion, valido 30 minutos desde que se carga este fichero:
     /verify?code=e2-URDw4rYPsC9pnVuTBsCMQdsJvNvviWBCebxKrp00_ */
INSERT INTO Participants (participantid, participant, dnihashed) VALUES
    (9, 'a1e0915f817c7f38f234be9ec298347dd021721b52fa3c973dfceac91b1b53b0',
     '067e216e89fe8f307d5ce641c8ab9acae2905eb9323a826962625130a37b668b');
INSERT INTO Verifications (participantid, verifykey, verified) VALUES
    (9, 'aa0035141c5d321a934392597a46e3ef0bc1d4bd170010978e0eca8d5315d805', 0);

/* ---------- Respuestas de la consulta 1 ---------- */
INSERT INTO Responses (surveyid, response, responsedate) VALUES
    (1, '{"1":2,"2":{"1":1,"2":1},"3":1}', DATE_SUB(NOW(), INTERVAL 23 DAY));
INSERT INTO Responses (surveyid, response, responsedate) VALUES
    (1, '{"1":1,"2":{"2":1},"3":2}', DATE_SUB(NOW(), INTERVAL 22 DAY));
INSERT INTO Responses (surveyid, response, responsedate) VALUES
    (1, '{"1":2,"2":{"1":1,"3":1,"4":1},"3":-1}', DATE_SUB(NOW(), INTERVAL 21 DAY));
INSERT INTO Responses (surveyid, response, responsedate) VALUES
    (1, '{"1":3,"2":{"4":1},"3":1}', DATE_SUB(NOW(), INTERVAL 20 DAY));
INSERT INTO Responses (surveyid, response, responsedate) VALUES
    (1, '{"1":2,"2":{"2":1,"4":1},"3":2}', DATE_SUB(NOW(), INTERVAL 19 DAY));

/* ---------- Respuestas de la consulta 2 (abierta, con resultados parciales) ---------- */
INSERT INTO Responses (surveyid, response, responsedate) VALUES
    (2, '{"1":1,"2":{"2":1,"3":1}}', DATE_SUB(NOW(), INTERVAL 2 DAY));
INSERT INTO Responses (surveyid, response, responsedate) VALUES
    (2, '{"1":3,"2":[]}', DATE_SUB(NOW(), INTERVAL 1 DAY));
INSERT INTO Responses (surveyid, response, responsedate) VALUES
    (2, '{"1":1,"2":{"4":1}}', DATE_SUB(NOW(), INTERVAL 1 DAY));

/* ---------- Quien ha votado ---------- */
/* Sin relacion con las filas de Responses: solo impide votar dos veces. */
INSERT INTO Voted (surveyid, participantid) VALUES
    (1, 1), (1, 2), (1, 3), (1, 4), (1, 5),
    (2, 1), (2, 2), (2, 3);
