-- modMedRecord: diagnoses of a record. label is a snapshot so later
-- dictionary edits never rewrite history. diag_type WM = ICD-10, TCM = 中医病名.

CREATE TABLE llx_medrecord_diagnosis(
	rowid			integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	fk_medrecord	integer NOT NULL,
	diag_type		varchar(3) DEFAULT 'WM' NOT NULL,
	code			varchar(32) NOT NULL,
	label			varchar(255) NOT NULL,
	is_primary		smallint DEFAULT 0 NOT NULL,
	position		smallint DEFAULT 0 NOT NULL
) ENGINE=innodb;
