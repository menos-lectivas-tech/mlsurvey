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

/* 4: Formacion permanente del profesorado */
INSERT INTO Surveys (surveyid, surveyname, surveydesc, surveyfile, startdate, enddate, createdby, modifiedby) VALUES
    (4, 'Formacion permanente del profesorado', '<p>Consulta <strong>abierta</strong> y aun sin respuestas: se puede solicitar codigo y participar mientras este en plazo.</p>', NULL, DATE_SUB(NOW(), INTERVAL 1 DAY), DATE_ADD(NOW(), INTERVAL 20 DAY), 1, 1);
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
/* participant = sha256(correo). */
/* 1: ana.responde@example.com */
INSERT INTO Participants (participantid, participant) VALUES
    (1, '3af36912d14dbd2ac556c341276bcda1442ce384943f343f56971b1adc140b36');
/* 2: bruno.responde@example.com */
INSERT INTO Participants (participantid, participant) VALUES
    (2, 'a114bd224c57e160838633384eab70fdf7c8732533074ee31298353fb4a523b5');
/* 3: carla.responde@example.com */
INSERT INTO Participants (participantid, participant) VALUES
    (3, '1fc024ea26950c1ccb656df258ae853be07e2dee35227aa619ed9746fe2e5951');
/* 4: diego.responde@example.com */
INSERT INTO Participants (participantid, participant) VALUES
    (4, '797ec5b2f22f6b96e8ab4f35afb6b9ff8381f6f2fba4cbcdefcafcedbd3953d7');
/* 5: elena.responde@example.com */
INSERT INTO Participants (participantid, participant) VALUES
    (5, 'd00871aab65868e557c9af7f80deb016d924e8c9a15e61cc9c68da80c6c22f00');
/* 6: fatima.pendiente@example.com */
INSERT INTO Participants (participantid, participant) VALUES
    (6, 'efc75544d7706a4eb8fd3fbf00c27a9b5b2ae0ed78749ccf91ebb4459f375217');
/* 7: gorka.pendiente@example.com */
INSERT INTO Participants (participantid, participant) VALUES
    (7, 'c04daa0f58a6c51d50413411beaf3906af838843ff7fbd5ce27bd23df0f58083');

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
