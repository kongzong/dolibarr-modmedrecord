ALTER TABLE llx_medrecord_diagnosis ADD INDEX idx_medrecord_diagnosis_rec (fk_medrecord);
ALTER TABLE llx_medrecord_diagnosis ADD INDEX idx_medrecord_diagnosis_code (code);
