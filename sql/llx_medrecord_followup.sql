-- modMedRecord: follow-up plan (随访计划). Created by staff after (or during)
-- a visit; due today/overdue items surface on the workbench (patientindex).
-- Reminder is IN-APP only (no cron): the dashboard queries due rows on load.
-- status 0 pending, 1 done, 9 cancelled. ftype fixed set: revisit / medication
-- / chronic (no dictionary table in V1).

CREATE TABLE llx_medrecord_followup(
	rowid				integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity				integer DEFAULT 1 NOT NULL,
	fk_patient			integer NOT NULL,
	fk_medrecord		integer DEFAULT NULL,
	due_date			date NOT NULL,
	ftype				varchar(16) DEFAULT 'revisit' NOT NULL,
	note				text DEFAULT NULL,
	status				smallint DEFAULT 0 NOT NULL,
	done_date			datetime DEFAULT NULL,
	done_note			varchar(255) DEFAULT NULL,
	fk_user_creat		integer DEFAULT NULL,
	date_creation		datetime NOT NULL,
	tms					timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
