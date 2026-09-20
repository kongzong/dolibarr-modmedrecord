-- modMedRecord: daily record-number sequence (JZ-YYYYMMDD-NNN), same
-- pattern as modPatient's card sequence. Kept on module disable.
CREATE TABLE IF NOT EXISTS llx_medrecord_sequence (
	ref_prefix	varchar(16) NOT NULL,
	last_value	bigint NOT NULL DEFAULT 0,
	PRIMARY KEY (ref_prefix)
) ENGINE=innodb;
