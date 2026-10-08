/**
Usando tablas versionadas para saber quién realiza cambios.
El problema es que las tablas versionadas no permiten claves foraneas.
**/

CREATE TABLE Users (
	userid INT UNSIGNED auto_increment NOT NULL,
	username TEXT NOT NULL,
	passwd TEXT NOT NULL,
	`role` TEXT NULL,
	CONSTRAINT Users_PK PRIMARY KEY (userid)
);

CREATE TABLE Surveys (
	surveyid INT UNSIGNED auto_increment NOT NULL,
	surveyname TEXT NOT NULL,
	surveydesc TEXT NOT NULL,
	surveyfile TEXT NULL,
	showpartial BOOL NULL,
	startdate DATETIME NOT NULL,
	enddate DATETIME NOT NULL,
	created DATETIME DEFAULT current_timestamp NOT NULL,
	createdby INT UNSIGNED NOT NULL,
	modifiedby INT UNSIGNED NOT NULL,
	CONSTRAINT Surveys_PK PRIMARY KEY (surveyid)/*,
	CONSTRAINT Surveys_Users_C_FK FOREIGN KEY (createdby) REFERENCES Users(userid) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT Surveys_Users_M_FK FOREIGN KEY (modifiedby) REFERENCES Users(userid) ON DELETE RESTRICT ON UPDATE RESTRICT*/

)
WITH SYSTEM VERSIONING
PARTITION BY SYSTEM_TIME;

CREATE TABLE Questions (
	surveyid INT UNSIGNED NOT NULL,
	questionid INT UNSIGNED NOT NULL,
	questiondesc TEXT NOT NULL,
	optional BOOL NULL,
	multiple BOOL NULL,
        file VARCHAR(256) NULL,
	CONSTRAINT Questions_PK PRIMARY KEY (surveyid,questionid)/*,
	CONSTRAINT Questions_Surveys_FK FOREIGN KEY (surveyid) REFERENCES Surveys(surveyid) ON DELETE CASCADE ON UPDATE CASCADE*/
);

CREATE INDEX Questions_questionid_IDX USING BTREE ON Questions (surveyid,questionid);

CREATE TABLE Options (
	surveyid INT UNSIGNED NOT NULL,
	questionid INT UNSIGNED NOT NULL,
	optionid INT UNSIGNED NOT NULL,
	optiondesc TEXT NOT NULL,
	CONSTRAINT Options_pk PRIMARY KEY (surveyid,questionid,optionid),
	/*CONSTRAINT Options_Surveys_FK FOREIGN KEY (surveyid) REFERENCES Surveys(surveyid) ON DELETE CASCADE ON UPDATE CASCADE,*/
	CONSTRAINT Options_Questions_FK FOREIGN KEY (surveyid,questionid) REFERENCES Questions(surveyid,questionid) ON DELETE CASCADE ON UPDATE CASCADE
);
CREATE INDEX Options_optionid_IDX USING BTREE ON Options (surveyid,questionid,optionid);

CREATE TABLE SystemConfig (
	configid INT UNSIGNED auto_increment NOT NULL,
	timezone TEXT NULL,
	alloweddomains TEXT NULL,
	mainheader VARCHAR(256) NULL,
	maincontent TEXT NULL,
	icon VARCHAR(256) NULL,
	sitename VARCHAR(256) NULL,
	facebook VARCHAR(256) NULL,
	twitter VARCHAR(256) NULL,
	linkedin VARCHAR(256) NULL,
	pinterest VARCHAR(256) NULL,
	googleplus VARCHAR(256) NULL,
	mastodon VARCHAR(256) NULL,
	bluesky VARCHAR(256) NULL,
	telegram VARCHAR(256) NULL,
	contact VARCHAR(256) NULL,
	CONSTRAINT SystemConfig_PK PRIMARY KEY (configid)
);

CREATE TABLE Participants (
	participantid INT UNSIGNED auto_increment NOT NULL,
	participant CHAR(64) NOT NULL,
	dnihashed CHAR(64) NULL, /*sha256 of the DNI; NULL in rows older than the column*/
	privatekey TEXT NOT NULL,
	publickey TEXT NOT NULL,
	CONSTRAINT Participants_PK PRIMARY KEY (participantid)	
);
/*Una fila por dirección: la tabla es inmutable, un duplicado ya no se puede borrar.*/
CREATE UNIQUE INDEX Participants_participant_IDX USING BTREE ON Participants (participant);
/*Los mismos documentos no se pueden usar desde varias direcciones, ni una dirección con varios.*/
CREATE UNIQUE INDEX Participants_dnihashed_IDX USING BTREE ON Participants (dnihashed);

CREATE TABLE Participation (
	participationid INT UNSIGNED auto_increment NOT NULL,
	surveyid INT UNSIGNED,
	participationkey VARCHAR(256) NOT NULL,
	participant TEXT NOT NULL,
	participationdate DATETIME NOT NULL DEFAULT current_timestamp,
	requesttag CHAR(64) NULL, /*sha256 of participant hash and surveyid, for the hourly limit*/
	CONSTRAINT Participation_PK PRIMARY KEY (participationid)/*,
	CONSTRAINT Participation_surveys_FK FOREIGN KEY (surveyid) REFERENCES Surveys(surveyid) ON DELETE RESTRICT ON UPDATE RESTRICT*/
);
CREATE INDEX Participation_request_IDX USING BTREE ON Participation (surveyid, requesttag, participationdate);


CREATE TABLE StressTest (
	participationid INT UNSIGNED auto_increment NOT NULL PRIMARY KEY,
	surveyid INT UNSIGNED,
	participationkey VARCHAR(256) NOT NULL
);

CREATE TABLE Responses (
	responseid INT UNSIGNED auto_increment NOT NULL,
	surveyid INT UNSIGNED NOT NULL,
	participantid INT UNSIGNED NOT NULL,
	response TEXT NOT NULL, /*array to JSON*/
	responsesign TEXT NOT NULL, /*ECDSA response signature*/
	responsedate DATETIME NOT NULL DEFAULT current_timestamp ON UPDATE current_timestamp,
	CONSTRAINT Responses_PK PRIMARY KEY (responseid)/*,
	CONSTRAINT Responses_Surveys_FK FOREIGN KEY (surveyid) REFERENCES Surveys(surveyid) ON DELETE RESTRICT ON UPDATE RESTRICT*/
);
/*CREATE INDEX Responses_participant_IDX USING BTREE ON Responses (participant);
CREATE INDEX Responses_survey_date_IDX USING BTREE ON Responses (surveyid, responsedate);*/

CREATE TABLE Results (
	surveyid INT UNSIGNED NOT NULL,
	results TEXT NOT NULL, /*A JSON with the results*/
	ispartial BOOL NULL,
	resultsdate DATETIME NOT NULL DEFAULT current_timestamp ON UPDATE current_timestamp,
	CONSTRAINT Results_PK PRIMARY KEY (surveyid)/*,
	CONSTRAINT Results_Surveys_FK FOREIGN KEY (surveyid) REFERENCES Surveys(surveyid) ON DELETE RESTRICT ON UPDATE RESTRICT*/
);

CREATE TABLE Version (
  versionid INT UNSIGNED NOT NULL PRIMARY KEY,
  versioncode INT NOT NULL
);

INSERT INTO Version values (1, 3);

DELIMITER $$
CREATE TRIGGER Responses_no_update
BEFORE UPDATE ON Responses
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' 
  SET MESSAGE_TEXT = 'Error: This table is immutable. Modifications are not allowed. (UPDATES).';
END$$


CREATE TRIGGER Responses_no_delete
BEFORE DELETE ON Responses
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' 
  SET MESSAGE_TEXT = 'Error: This table is immutable. Deletions are not allowed. (DELETES).';
END$$

CREATE TRIGGER Participants_no_update
BEFORE UPDATE ON Participants
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' 
  SET MESSAGE_TEXT = 'Error: This table is immutable. Modifications are not allowed. (UPDATES).';
END$$


CREATE TRIGGER Participants_no_delete
BEFORE DELETE ON Participants
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' 
  SET MESSAGE_TEXT = 'Error: This table is immutable. Deletions are not allowed. (DELETES).';
END$$



DELIMITER ;
