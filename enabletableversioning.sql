/**
This file enables table versioning on Surveys table.
Must be run after modelo_datos.sql.
As a table with partitions throws errors with foreign keys, all
Surveys' foreign keys must be dropped.
https://mariadb.com/docs/server/server-usage/partitioning-tables/partitioning-limitations.

**/

/*Drop FK from Surveys*/
ALTER TABLE Surveys DROP FOREIGN KEY Surveys_Users_C_FK;
ALTER TABLE Surveys DROP FOREIGN KEY Surveys_Users_M_FK;

/*Drop FK that references Surveys*/
ALTER TABLE Questions DROP FOREIGN KEY Questions_Surveys_FK;
ALTER TABLE Options DROP FOREIGN KEY Options_Surveys_FK;
ALTER TABLE Participation DROP FOREIGN KEY Participation_Surveys_FK;
ALTER TABLE Responses DROP FOREIGN KEY Responses_Surveys_FK;
ALTER TABLE Results DROP FOREIGN KEY Results_Surveys_FK;

ALTER TABLE Surveys ADD SYSTEM VERSIONING PARTITION BY SYSTEM_TIME;
