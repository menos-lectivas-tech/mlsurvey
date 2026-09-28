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
 * Fichero generado; para regenerar las claves y firmas hay que volver a crearlo.
 */

/* ---------- Usuarios ---------- */
/* admin / admin123 */
INSERT INTO Users (username, passwd, `role`) VALUES ('admin', '$2y$10$b1Z0iC4TCYJvxa/KUVWWv.OzO/DAhil7f8rvZICS7BFTBtMeRvyBS', 'A');
/* profesora / profesora123 */
INSERT INTO Users (username, passwd, `role`) VALUES ('profesora', '$2y$10$loC.cYvR0R12ApxvPmvhNOeg4VYDmDLv5/tWsAFnH26Q8Aj.EXMpi', '');

/* ---------- Configuracion del sistema ---------- */
INSERT INTO SystemConfig (configid, timezone, alloweddomains)
    VALUES (1, 'Europe/Madrid', 'educa.madrid.org');
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
    (2, 'Actividades extraescolares del proximo curso', '<p>Consulta <strong>abierta</strong>: se puede solicitar codigo y participar mientras este en plazo.</p>', NULL, DATE_SUB(NOW(), INTERVAL 3 DAY), DATE_ADD(NOW(), INTERVAL 27 DAY), 1, 1, true);
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

/* ---------- Participantes (claves ECDSA secp256k1 reales) ---------- */
/* participant = sha256(correo). La clave privada esta cifrada con el correo. */
/* 1: ana.responde@example.com */
INSERT INTO Participants (participantid, participant, privatekey, publickey) VALUES
    (1, '3af36912d14dbd2ac556c341276bcda1442ce384943f343f56971b1adc140b36', '-----BEGIN ENCRYPTED PRIVATE KEY-----
MIHbME4GCSqGSIb3DQEFDTBBMCkGCSqGSIb3DQEFDDAcBAi0Xq4rwMSdVgICCAAw
DAYIKoZIhvcNAgkFADAUBggqhkiG9w0DBwQITtcRsrQJ9lsEgYgkk2HgnMCF5vib
lmIVHBif7puq/YiQ4HFoRR4xIWB0ddFovZN8lT2BL4jcBTkrGpMI2RpvkOAIjpQ8
soyVLgP9zZd6WPlItp8Ak21WqLVcVBcdA/ElTWGmK7KAn7dhEh353U4p/THB8yGv
W8P/GdKn9HNEydAQ/AsuZQEnrGiSeiYpmACOQnB4
-----END ENCRYPTED PRIVATE KEY-----
', '-----BEGIN PUBLIC KEY-----
MFYwEAYHKoZIzj0CAQYFK4EEAAoDQgAEwD0QBo7ZZcIAf0rakwV7vnkFmnFGZz7r
2LB4PXpdzWFRtk4z69B/41RgOZdWsfDX//SHxI6z7I1t3/Q6KgfzrQ==
-----END PUBLIC KEY-----
');
/* 2: bruno.responde@example.com */
INSERT INTO Participants (participantid, participant, privatekey, publickey) VALUES
    (2, 'a114bd224c57e160838633384eab70fdf7c8732533074ee31298353fb4a523b5', '-----BEGIN ENCRYPTED PRIVATE KEY-----
MIHbME4GCSqGSIb3DQEFDTBBMCkGCSqGSIb3DQEFDDAcBAgPsxiyvu/gBgICCAAw
DAYIKoZIhvcNAgkFADAUBggqhkiG9w0DBwQIeRBrRumCthEEgYiD1gI2hTpjxBri
rZfE+5VRfcGoxL/smfLp4SzYy//MIDnOIYSeP2thtN965TZuY5xU2VssYQ9C6i6f
FFGaaSDR9r8BmqHBRwJIN4rOPHc2Cf24uecIqxThzBYTHI9aINiADpGWJ3apmLTP
Xl3oKz7CVK3440P4oYetuiDAa3fF06hDJIFNAGu8
-----END ENCRYPTED PRIVATE KEY-----
', '-----BEGIN PUBLIC KEY-----
MFYwEAYHKoZIzj0CAQYFK4EEAAoDQgAEB0T86Li/tg3aCUSfy1HiWv9FkBuFWkhJ
30TN46U6aK3puy62/K8yIIuAfiS6ufCyiK0FuQhiIgk60f9iYvo4Sg==
-----END PUBLIC KEY-----
');
/* 3: carla.responde@example.com */
INSERT INTO Participants (participantid, participant, privatekey, publickey) VALUES
    (3, '1fc024ea26950c1ccb656df258ae853be07e2dee35227aa619ed9746fe2e5951', '-----BEGIN ENCRYPTED PRIVATE KEY-----
MIHbME4GCSqGSIb3DQEFDTBBMCkGCSqGSIb3DQEFDDAcBAgbyKITiOM3nQICCAAw
DAYIKoZIhvcNAgkFADAUBggqhkiG9w0DBwQIJbJq+Nc52TQEgYjD6+7No51j/V2y
9xQn0glf5dH7FTrAdfLXkHS7JPMV6YNJkEeZ+eszv3vZLBJEn4kCiMLe8rveNiV9
ZsXLJJ/DHSYx7ry79vI2PmBCke22QLvI1WvWihKrtO65qV1Touj9e0DuE3MUplP6
lE6UXd7AKTtA94ZM4J9GfVfHNDC5UfX3AxB/l0rg
-----END ENCRYPTED PRIVATE KEY-----
', '-----BEGIN PUBLIC KEY-----
MFYwEAYHKoZIzj0CAQYFK4EEAAoDQgAEjlzcUZ0Z6YOcQDIVk4Iu5Oe/k9EX89RX
0HGAfm0QtAOc0X5X8lJUlJAWrzPHzPKhTRiHmrl2WBeTSWvxTWMXkQ==
-----END PUBLIC KEY-----
');
/* 4: diego.responde@example.com */
INSERT INTO Participants (participantid, participant, privatekey, publickey) VALUES
    (4, '797ec5b2f22f6b96e8ab4f35afb6b9ff8381f6f2fba4cbcdefcafcedbd3953d7', '-----BEGIN ENCRYPTED PRIVATE KEY-----
MIHbME4GCSqGSIb3DQEFDTBBMCkGCSqGSIb3DQEFDDAcBAiNQ7RjxbMr/wICCAAw
DAYIKoZIhvcNAgkFADAUBggqhkiG9w0DBwQI7R7oh2krwUsEgYgx5b5Dh3QBamtK
3LzP+lRqbimoFnBesFSuqQ4DjbxTuDRuVmi1SlSxz6G67tUTzvvfZsJiz27cdnnl
yC3GHv8uPPlzKlbn4/tp6DE0wWaqs+CHyshTMozwaWFDdnU0Orj5yJxzmevz1kUy
pSulizqeYw/kJb9iW+F2D9LqVX1KQGItn9rz3MQo
-----END ENCRYPTED PRIVATE KEY-----
', '-----BEGIN PUBLIC KEY-----
MFYwEAYHKoZIzj0CAQYFK4EEAAoDQgAE2OiaKvl+xggFxfEXiU7/QwsvVWJND1oH
KdwMqNH1KKfOIVHUd/n8BuOptg8B+Lm60A2bDggo2v2INobOSujMaw==
-----END PUBLIC KEY-----
');
/* 5: elena.responde@example.com */
INSERT INTO Participants (participantid, participant, privatekey, publickey) VALUES
    (5, 'd00871aab65868e557c9af7f80deb016d924e8c9a15e61cc9c68da80c6c22f00', '-----BEGIN ENCRYPTED PRIVATE KEY-----
MIHbME4GCSqGSIb3DQEFDTBBMCkGCSqGSIb3DQEFDDAcBAg0OO03A6D8WwICCAAw
DAYIKoZIhvcNAgkFADAUBggqhkiG9w0DBwQI6UwMj+owNm4EgYgBrheUimxvNa7E
OfYcqFc4QCtDai5sM5QYQy9O5F7CcxRjkGZyTunNqcdxZeH+HeDWVfwsaKx2GrRT
C9baZPxhn1OuoXuD+uZwt+kBVyh9prcvuJ2gDXjodAf6iI7K3UDuQF6jtvL+lPum
nTIBjzA41hzZ9Kq5cMs8tUwjNAwxUsEg7pqBA5JJ
-----END ENCRYPTED PRIVATE KEY-----
', '-----BEGIN PUBLIC KEY-----
MFYwEAYHKoZIzj0CAQYFK4EEAAoDQgAEOS0Od5kMylRav0epcdxLFUhQFLrieRwL
o808bgKkaA49+Iem6V+td7+R0h6gtEJZi645WRS9UethnsA+AaFBmQ==
-----END PUBLIC KEY-----
');
/* 6: fatima.pendiente@example.com */
INSERT INTO Participants (participantid, participant, privatekey, publickey) VALUES
    (6, 'efc75544d7706a4eb8fd3fbf00c27a9b5b2ae0ed78749ccf91ebb4459f375217', '-----BEGIN ENCRYPTED PRIVATE KEY-----
MIHbME4GCSqGSIb3DQEFDTBBMCkGCSqGSIb3DQEFDDAcBAiH3R2S4BFhlQICCAAw
DAYIKoZIhvcNAgkFADAUBggqhkiG9w0DBwQIq5miFyHdUPwEgYhjUsLgKkjIXIaJ
c3vn3ORQ4T3O6O2aDR94wXqmgSACkmVdxd/lBZdkAveOdUds9QyMsAGcM4St2Eo9
O4ctsWlsipRtqk9Fz3BRVQoDTPPKlXuLK3Thg0mNoWBYMbn2tu8L6QBVAPOV0d97
QlJWXEA5nGdVUVJSyFlzhlm6zrvEMgJTO2n/J7fE
-----END ENCRYPTED PRIVATE KEY-----
', '-----BEGIN PUBLIC KEY-----
MFYwEAYHKoZIzj0CAQYFK4EEAAoDQgAEknPo63qzddn/xBQ+8NgzaB4yYEBMnySa
ZGq+u51r2ZarS86eEKCWu/xNArsUAf+TKHjb8uu+HKJdHEMM5j7Rrg==
-----END PUBLIC KEY-----
');
/* 7: gorka.pendiente@example.com */
INSERT INTO Participants (participantid, participant, privatekey, publickey) VALUES
    (7, 'c04daa0f58a6c51d50413411beaf3906af838843ff7fbd5ce27bd23df0f58083', '-----BEGIN ENCRYPTED PRIVATE KEY-----
MIHbME4GCSqGSIb3DQEFDTBBMCkGCSqGSIb3DQEFDDAcBAgBAgPiIWfItgICCAAw
DAYIKoZIhvcNAgkFADAUBggqhkiG9w0DBwQIFTugU6IbLgcEgYgR8CVwhL3iuof8
SNE1rBz1roQSxi1yivUiYN95wqgZiSrLDC6y+twGNpzDBxpYd5ClSNUSwXaUXm6j
PHv88DF0dzFlZjaF2CmuODRTXBcyw1SghzN5IOIwjFUFKGuZzLOzQsPBUXvRsKHT
UNRZPvyEqvPy1hXxiB47zPTgAkSGpFJOlcHE7vHp
-----END ENCRYPTED PRIVATE KEY-----
', '-----BEGIN PUBLIC KEY-----
MFYwEAYHKoZIzj0CAQYFK4EEAAoDQgAErZoHZvEVSAoejNAOOevjK39k3HT41JXM
aDJbAmbs+CjwYWFQ0wWo/lpy8NNmF6/aaNos10y8xC6TlMSeFskkeA==
-----END PUBLIC KEY-----
');

/* ---------- Codigos de participacion ---------- */
/* Los codigos de la consulta 2 siguen siendo validos. Enlaces de participacion
   (anteponer la URL del servidor, p.ej. http://localhost:8080):
     fatima.pendiente@example.com
       /participate?pid=6&auth=G9_lruqApCrLtmQjCgn_049t1fD8kIUPiddGMrKYo_E
     gorka.pendiente@example.com
       /participate?pid=7&auth=fig4g3FhTi4oLJQBIwKlpAhbrJvODPEOCFyO6jDjGIY
*/
INSERT INTO Participation (participationid, surveyid, participationkey, participant, participationdate) VALUES
    (1, 1, '6b8f8aac529cc53d9a7fad0d7616981ca98a269fbfdda9fb845fc3414c1816c8', 'M2YydlAvRTFRTUNpRlczUXViRVdKbVBzcTcwdE50dXNwKy83VVNBZ2Y5ST0=', DATE_SUB(NOW(), INTERVAL 25 DAY));
INSERT INTO Participation (participationid, surveyid, participationkey, participant, participationdate) VALUES
    (2, 1, '0149159c2994e832971dee53ab885c679567716f156756a2362a9db6433adebc', 'RHQzenpjdVZqaGo1MkszYlpNKzU3ZVRucUI4NlhCOTBWTUpCUWNjc0ZQbz0=', DATE_SUB(NOW(), INTERVAL 25 DAY));
INSERT INTO Participation (participationid, surveyid, participationkey, participant, participationdate) VALUES
    (3, 1, 'bed870572185ecadf4a1971e38cd2dcbd3b24bbd1a82e8ae8a64059f6198d31c', 'Q2tqV1VTV1YvU2lzbnVJQSs3R3BFR1djVzg2dHVLN1czd2laZ2U5dE0yUT0=', DATE_SUB(NOW(), INTERVAL 25 DAY));
INSERT INTO Participation (participationid, surveyid, participationkey, participant, participationdate) VALUES
    (4, 1, '85bdd7dcb8a8b7d88e1c86c4d6571b679ebed25cbb5f9d5c27dda08bf6f7d03a', 'Qm9HNlltSkVBVkN3K1lFRVh2K0dOcUUzUXV1U01tN1M0dWtIQUI2TkhBST0=', DATE_SUB(NOW(), INTERVAL 25 DAY));
INSERT INTO Participation (participationid, surveyid, participationkey, participant, participationdate) VALUES
    (5, 1, '061e77d917bf60f8af6e4128b5edc408e70baedb96f00b8b263e0d69254cb470', 'S2ZTM3FoTnIyZEF1UnJCK0RyYUZSaUNhZWg5Qm0wMW40LzduMWpkdnZxbz0=', DATE_SUB(NOW(), INTERVAL 25 DAY));
INSERT INTO Participation (participationid, surveyid, participationkey, participant, participationdate) VALUES
    (6, 2, 'abe8950e69a04e27acd086d94c43c42e833229e9705a6fd256cede0bb80e174a', 'dkpJZHV4ODcwUTl6a1hnT2NPZmhmSkQ4cWNEQWpNV3U3RTF6cWR6TVJKdz0=', DATE_SUB(NOW(), INTERVAL 2 DAY));
INSERT INTO Participation (participationid, surveyid, participationkey, participant, participationdate) VALUES
    (7, 2, '52a37853a33877db86653f55f24851e444207e8108dace15c7575b242d866d88', 'TkdDdWFtMTkvR2pXdmRUQTU5TEZGaC9PZVgwakV1bStUdG1Sc2NQSXBVcz0=', DATE_SUB(NOW(), INTERVAL 2 DAY));
/* Participaciones ya usadas en la consulta 2 (ana, bruno y carla ya han respondido). */
INSERT INTO Participation (participationid, surveyid, participationkey, participant, participationdate) VALUES
    (8, 2, '3109c9550b750695cf2a68c9b574ec50f08272ec52e7aeb23963ddcb456dd1c7', 'L0Fod0taWUhuVFdrZ0VHOHlpcGp4L2FjWU1oUzFkZ21tZGlPY2FkUWw2WT0=', DATE_SUB(NOW(), INTERVAL 2 DAY));
INSERT INTO Participation (participationid, surveyid, participationkey, participant, participationdate) VALUES
    (9, 2, '8bbcf252eb14078507b91a588f166a83c485d8fb89e6575bbb0ec4ae521f9ced', 'WlloOVZ0TTV0dGJ4NlRjdm43bVJFdzVzSTc5RzJMNTZ5a1dYbFd1d0d4RT0=', DATE_SUB(NOW(), INTERVAL 2 DAY));
INSERT INTO Participation (participationid, surveyid, participationkey, participant, participationdate) VALUES
    (10, 2, '5d1347fb5f918494f3f5730381c39be19f7d115e84dcc9f907b79b9fb6c833b2', 'OEU3bWk4a0Z6RHgvRHEwT3l3QWZ1Q0FucS8zby9pQkx5eDZQVUM1bWwvZz0=', DATE_SUB(NOW(), INTERVAL 1 DAY));

/* ---------- Respuestas firmadas de la consulta 1 ---------- */
/* Verificables con: docker compose exec web php verify_responses.php 1 */
INSERT INTO Responses (surveyid, participantid, response, responsesign, responsedate) VALUES
    (1, 1, '{"1":2,"2":{"1":1,"2":1},"3":1}', 'MEUCIQCsgdY9KcpDlv4dUh09TGjeNXGhpEMFz2O2I0vO/2IAZgIgBd1PKnUCzV7bRbcqrAj0tQGZg7X+gfnLKCzCnxpt+p8=', DATE_SUB(NOW(), INTERVAL 23 DAY));
INSERT INTO Responses (surveyid, participantid, response, responsesign, responsedate) VALUES
    (1, 2, '{"1":1,"2":{"2":1},"3":2}', 'MEYCIQC+CPm9p5YpanOwvEZIRoI/3fKDvd6kNlmFbkw9uVrg5gIhANlWhZOoR8b2npLPZzroRuq/MKT///Bz0eHS79aHc44t', DATE_SUB(NOW(), INTERVAL 22 DAY));
INSERT INTO Responses (surveyid, participantid, response, responsesign, responsedate) VALUES
    (1, 3, '{"1":2,"2":{"1":1,"3":1,"4":1},"3":-1}', 'MEUCIQCNeA+VNbFsXEwqGrK1771S4ceiJh6pOzAguRA75UDflQIgHt8KrQsBJuEc3yNPt7MF4VGuahzpQs8hOpZ4cTB+wvg=', DATE_SUB(NOW(), INTERVAL 21 DAY));
INSERT INTO Responses (surveyid, participantid, response, responsesign, responsedate) VALUES
    (1, 4, '{"1":3,"2":{"4":1},"3":1}', 'MEUCIBVRxOjmTFZOQTqM+LFAzujL97wMnukq162B8/g/DA8oAiEA5pQYtZbgduiqT56XIRkVl5YxuhK4d8gVAR11wbGeygQ=', DATE_SUB(NOW(), INTERVAL 20 DAY));
INSERT INTO Responses (surveyid, participantid, response, responsesign, responsedate) VALUES
    (1, 5, '{"1":2,"2":{"2":1,"4":1},"3":2}', 'MEUCICPbgx0U9avVeynUlpafLOt+sCC4qyL5XwC2IwGuHlQ4AiEAsn/uEL9NKrvRs/J4P0tyM8gGkXpnAGUwrNe3Zf2kxqk=', DATE_SUB(NOW(), INTERVAL 19 DAY));

/* ---------- Respuestas firmadas de la consulta 2 (abierta, con resultados parciales) ---------- */
/* Verificables con: docker compose exec web php verify_responses.php 2 */
INSERT INTO Responses (surveyid, participantid, response, responsesign, responsedate) VALUES
    (2, 1, '{"1":1,"2":{"2":1,"3":1}}', 'MEQCIClOwJ2vX/3m9QS3RG1n/+egkF1sNsavEUr6SFrqUVTsAiAu5OEY3CWmDmkM4MkKkU4m4vpZPy8dUqwyw8E1K2vAqQ==', DATE_SUB(NOW(), INTERVAL 2 DAY));
INSERT INTO Responses (surveyid, participantid, response, responsesign, responsedate) VALUES
    (2, 2, '{"1":3,"2":[]}', 'MEUCIDdGvAr0ALQErUe2uCT3XxAYXHwWGXIQ2oOgyiGCS9geAiEA/eZZheTV2bCxK+qcQD8pHmeEchQB6oeBJ2UoUx6qtSQ=', DATE_SUB(NOW(), INTERVAL 1 DAY));
INSERT INTO Responses (surveyid, participantid, response, responsesign, responsedate) VALUES
    (2, 3, '{"1":1,"2":{"4":1}}', 'MEUCIB4TKIdjw8H1OkUw3SqyRaWq5FdFcBv5ElbV3ROzo7I3AiEA+XaR8WaoIR9/hKVc/Kr/GHVMqVYoCkcwhJyj4aukrJk=', DATE_SUB(NOW(), INTERVAL 1 DAY));
