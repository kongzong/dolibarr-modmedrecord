-- modMedRecord dictionaries (Home > Setup > Dictionaries). Plain integer PK
-- without auto increment: admin/dict.php computes MAX(rowid)+1.
-- ICD-10 follows the national clinical edition structure: code = main code
-- (A01.001), extra_code = optional asterisk code (K77.0*), chapter = 章.

CREATE TABLE llx_c_medrecord_icd10(
	rowid		integer PRIMARY KEY,
	code		varchar(16) NOT NULL,
	label		varchar(255) NOT NULL,
	extra_code	varchar(16) DEFAULT NULL,
	chapter		varchar(8) DEFAULT NULL,
	active		tinyint DEFAULT 1 NOT NULL
) ENGINE=innodb;

CREATE TABLE llx_c_medrecord_tcm_disease(
	rowid	integer PRIMARY KEY,
	pos		smallint DEFAULT 0 NOT NULL,
	code	varchar(16) NOT NULL,
	label	varchar(128) NOT NULL,
	active	tinyint DEFAULT 1 NOT NULL
) ENGINE=innodb;

CREATE TABLE llx_c_medrecord_tcm_syndrome(
	rowid	integer PRIMARY KEY,
	pos		smallint DEFAULT 0 NOT NULL,
	code	varchar(16) NOT NULL,
	label	varchar(128) NOT NULL,
	active	tinyint DEFAULT 1 NOT NULL
) ENGINE=innodb;
