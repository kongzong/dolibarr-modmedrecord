ALTER TABLE llx_medrecord ADD UNIQUE INDEX uk_medrecord_ref (ref);
ALTER TABLE llx_medrecord ADD INDEX idx_medrecord_patient (fk_patient);
ALTER TABLE llx_medrecord ADD INDEX idx_medrecord_doctor (fk_doctor);
ALTER TABLE llx_medrecord ADD INDEX idx_medrecord_visit (visit_date);
ALTER TABLE llx_medrecord ADD INDEX idx_medrecord_status (status);
ALTER TABLE llx_medrecord ADD INDEX idx_medrecord_entity (entity);
