ALTER TABLE llx_c_medrecord_icd10 ADD UNIQUE INDEX uk_c_medrecord_icd10_code (code);
ALTER TABLE llx_c_medrecord_icd10 ADD INDEX idx_c_medrecord_icd10_label (label);
ALTER TABLE llx_c_medrecord_tcm_disease ADD UNIQUE INDEX uk_c_medrecord_tcm_disease_code (code);
ALTER TABLE llx_c_medrecord_tcm_syndrome ADD UNIQUE INDEX uk_c_medrecord_tcm_syndrome_code (code);
