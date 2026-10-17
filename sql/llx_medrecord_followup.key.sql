-- Copyright (C) 2026 modMedRecord contributors
ALTER TABLE llx_medrecord_followup ADD INDEX idx_medrecord_followup_due (entity, status, due_date);
ALTER TABLE llx_medrecord_followup ADD INDEX idx_medrecord_followup_patient (fk_patient);
ALTER TABLE llx_medrecord_followup ADD INDEX idx_medrecord_followup_record (fk_medrecord);
