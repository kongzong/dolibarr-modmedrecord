<?php
/* Copyright (C) 2026  modMedRecord contributors
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    htdocs/custom/medrecord/class/medrecord.class.php
 * \ingroup medrecord
 * \brief   One outpatient visit record with the draft -> signed -> voided
 *          state machine (spec §3.3). Never deleted. Every write is audited
 *          through modPatient's patient_audit() with MEDRECORD_* actions.
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';
dol_include_once('/medrecord/class/medrecordnumbering.class.php');
dol_include_once('/medrecord/lib/medrecord.lib.php');
dol_include_once('/patient/lib/patient.lib.php');

/**
 * Class MedRecord
 */
class MedRecord extends CommonObject
{
	public $element = 'medrecord';
	public $table_element = 'medrecord';
	public $picto = 'fa-notes-medical';

	public $id;
	public $entity;
	public $ref;
	public $fk_patient;
	public $fk_doctor;
	public $fk_department;
	/** @var int Unix timestamp */
	public $visit_date;
	public $visit_type = 1;
	public $chief_complaint;
	public $present_illness;
	public $past_history_snapshot;
	public $tongue;
	public $pulse;
	public $exam_note;
	public $tcm_disease_code;
	public $tcm_disease_label;
	public $tcm_syndrome_code;
	public $tcm_syndrome_label;
	public $treatment_principle;
	public $advice;
	public $fk_ref_medrecord;
	public $status = MEDRECORD_STATUS_DRAFT;
	public $date_signed;
	public $fk_user_sign;
	public $void_reason;
	public $date_void;
	public $fk_user_void;
	public $fk_user_creat;
	public $fk_user_modif;
	public $date_creation;
	public $tms;

	/** @var array<int,array{code:string,label:string,is_primary:int,diag_type:string}> Western (ICD-10) diagnoses */
	public $diagnoses = array();

	/** Text columns handled uniformly by insert/update */
	const TEXT_FIELDS = array('chief_complaint', 'present_illness', 'past_history_snapshot', 'tongue', 'pulse', 'exam_note',
		'tcm_disease_code', 'tcm_disease_label', 'tcm_syndrome_code', 'tcm_syndrome_label', 'treatment_principle', 'advice');

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	// ------------------------------------------------------------ permissions

	/**
	 * Can this user edit the record now? Drafts: admin, the doctor, or the
	 * creator. Signed: only within MEDRECORD_SIGN_LOCK_HOURS, by the signer.
	 *
	 * @param	User	$user	User
	 * @return	bool
	 */
	public function canEdit(User $user)
	{
		if (!$user->hasRight('medrecord', 'write')) {
			return false;
		}
		if ((int) $this->status === MEDRECORD_STATUS_VOIDED) {
			return false;
		}
		if ((int) $this->status === MEDRECORD_STATUS_SIGNED) {
			return $this->inSignLockWindow($user);
		}
		return $user->hasRight('medrecord', 'admin') || (int) $this->fk_doctor === (int) $user->id || (int) $this->fk_user_creat === (int) $user->id;
	}

	/**
	 * Signed record still editable by its signer within the configured window.
	 *
	 * @param	User	$user	User
	 * @return	bool
	 */
	public function inSignLockWindow(User $user)
	{
		$hours = getDolGlobalInt('MEDRECORD_SIGN_LOCK_HOURS', 0);
		if ($hours <= 0 || empty($this->date_signed) || (int) $this->fk_user_sign !== (int) $user->id) {
			return false;
		}
		return (dol_now() - (int) $this->date_signed) <= $hours * 3600;
	}

	/**
	 * @param	User	$user	User
	 * @return	bool
	 */
	public function canSign(User $user)
	{
		return (int) $this->status === MEDRECORD_STATUS_DRAFT && $user->hasRight('medrecord', 'sign')
			&& ($user->hasRight('medrecord', 'admin') || (int) $this->fk_doctor === (int) $user->id);
	}

	/**
	 * @param	User	$user	User
	 * @return	bool
	 */
	public function canVoid(User $user)
	{
		return (int) $this->status !== MEDRECORD_STATUS_VOIDED && $user->hasRight('medrecord', 'void');
	}

	// ------------------------------------------------------------ validation

	/**
	 * Common field checks. Fills $this->error.
	 *
	 * @param	bool	$forSign	Also enforce signature requirements
	 * @return	bool
	 */
	private function validate($forSign = false)
	{
		$this->fk_patient = (int) $this->fk_patient;
		$this->fk_doctor = (int) $this->fk_doctor;
		$this->visit_type = (int) $this->visit_type === 2 ? 2 : 1;
		if ($this->fk_patient <= 0) {
			$this->error = 'MedRecordErrPatientRequired';
			return false;
		}
		$doctors = patient_doctor_options($this->db);
		if ($this->fk_doctor <= 0 || !isset($doctors[$this->fk_doctor])) {
			$this->error = 'MedRecordErrDoctorRequired';
			return false;
		}
		if (empty($this->visit_date)) {
			$this->visit_date = dol_now();
		}
		// Dolibarr's empty <option> submits -1: normalize to null so diffs and storage agree
		$this->fk_department = (int) $this->fk_department > 0 ? (int) $this->fk_department : null;
		foreach (self::TEXT_FIELDS as $f) {
			if ($this->$f !== null) {
				// Browsers post CRLF from textareas; store LF only so a re-save without edits is not a "change"
				$this->$f = trim(str_replace(array("\r\n", "\r"), "\n", (string) $this->$f));
			}
		}
		if ($forSign) {
			if ($this->chief_complaint === null || $this->chief_complaint === '') {
				$this->error = 'MedRecordErrComplaintRequired';
				return false;
			}
			if (empty($this->diagnoses) && ($this->tcm_disease_code === null || $this->tcm_disease_code === '')) {
				$this->error = 'MedRecordErrDiagnosisRequired';
				return false;
			}
		}
		return true;
	}

	/**
	 * Resolve TCM dictionary labels (snapshot) from codes.
	 *
	 * @return	void
	 */
	private function snapshotTcmLabels()
	{
		if ($this->tcm_disease_code !== null && $this->tcm_disease_code !== '') {
			$l = medrecord_dict_label($this->db, 'c_medrecord_tcm_disease', $this->tcm_disease_code);
			$this->tcm_disease_label = $l !== '' ? $l : $this->tcm_disease_label;
		} else {
			$this->tcm_disease_label = null;
		}
		if ($this->tcm_syndrome_code !== null && $this->tcm_syndrome_code !== '') {
			$l = medrecord_dict_label($this->db, 'c_medrecord_tcm_syndrome', $this->tcm_syndrome_code);
			$this->tcm_syndrome_label = $l !== '' ? $l : $this->tcm_syndrome_label;
		} else {
			$this->tcm_syndrome_label = null;
		}
	}

	/**
	 * Normalize $this->diagnoses: unique codes, label snapshot from the ICD-10
	 * dictionary when missing, exactly one primary (first if none).
	 *
	 * @return	void
	 */
	private function normalizeDiagnoses()
	{
		$out = array();
		$seen = array();
		$hasPrimary = false;
		foreach ((array) $this->diagnoses as $d) {
			$code = isset($d['code']) ? trim((string) $d['code']) : '';
			if ($code === '' || isset($seen[$code])) {
				continue;
			}
			$label = isset($d['label']) ? trim((string) $d['label']) : '';
			if ($label === '') {
				$label = medrecord_dict_label($this->db, 'c_medrecord_icd10', $code);
			}
			if ($label === '') {
				$label = $code;
			}
			$primary = !empty($d['is_primary']) && !$hasPrimary ? 1 : 0;
			if ($primary) {
				$hasPrimary = true;
			}
			$seen[$code] = true;
			$out[] = array('code' => $code, 'label' => dol_substr($label, 0, 255), 'is_primary' => $primary, 'diag_type' => 'WM');
		}
		if (!$hasPrimary && !empty($out)) {
			$out[0]['is_primary'] = 1;
		}
		$this->diagnoses = $out;
	}

	// ------------------------------------------------------------ create

	/**
	 * Create a draft: number reserved and rows inserted in one transaction.
	 *
	 * @param	User	$user	Acting user
	 * @return	int				>0 rowid, <0 error (this->error set)
	 */
	public function create(User $user)
	{
		global $conf;

		$this->error = '';
		if (!$this->validate(false)) {
			return -1;
		}
		$this->snapshotTcmLabels();
		$this->normalizeDiagnoses();

		// Past history snapshot defaults to the patient's current note
		if ($this->past_history_snapshot === null || $this->past_history_snapshot === '') {
			$summary = patient_get_summary($this->db, $this->fk_patient);
			if ($summary && $summary['history_note'] !== null) {
				$this->past_history_snapshot = $summary['history_note'];
			}
		}

		$this->db->begin();
		try {
			$numbering = new MedRecordNumbering($this->db);
			$this->ref = $numbering->nextReference(MedRecordNumbering::prefixFor(dol_now()));

			$this->entity = !empty($conf->entity) ? (int) $conf->entity : 1;
			$this->status = MEDRECORD_STATUS_DRAFT;
			$this->fk_user_creat = (int) $user->id;
			$this->date_creation = dol_now();

			$sql = "INSERT INTO ".$this->db->prefix()."medrecord (entity, ref, fk_patient, fk_doctor, fk_department, visit_date, visit_type,";
			foreach (self::TEXT_FIELDS as $f) {
				$sql .= " ".$f.",";
			}
			$sql .= " fk_ref_medrecord, status, fk_user_creat, date_creation) VALUES (";
			$sql .= $this->entity.", '".$this->db->escape($this->ref)."', ".$this->fk_patient.", ".$this->fk_doctor.",";
			$sql .= " ".((int) $this->fk_department > 0 ? (int) $this->fk_department : 'NULL').",";
			$sql .= " '".$this->db->idate($this->visit_date)."', ".$this->visit_type.",";
			foreach (self::TEXT_FIELDS as $f) {
				$sql .= " ".$this->nullOrString($this->$f).",";
			}
			$sql .= " ".((int) $this->fk_ref_medrecord > 0 ? (int) $this->fk_ref_medrecord : 'NULL').", 0, ".$this->fk_user_creat.", '".$this->db->idate($this->date_creation)."')";

			if (!$this->db->query($sql)) {
				$this->error = $this->db->lasterror();
				throw new RuntimeException('record insert failed');
			}
			$this->id = (int) $this->db->last_insert_id($this->db->prefix().'medrecord');
			$this->saveDiagnoses();

			patient_audit($this->db, $this->fk_patient, 'MEDRECORD_CREATE', $user, array('ref' => $this->ref, 'record' => $this->id, 'doctor' => $this->fk_doctor));
			$this->db->commit();
			return $this->id;
		} catch (Throwable $e) {
			if (empty($this->error)) {
				$this->error = $e->getMessage();
			}
			dol_syslog(__METHOD__.' failed: '.$this->error, LOG_ERR);
			while ($this->db->transaction_opened > 0) {
				if (!$this->db->rollback()) {
					break;
				}
			}
			$this->id = 0;
			$this->ref = '';
			return -1;
		}
	}

	/**
	 * Replace the diagnosis rows of this record (inside the caller's transaction).
	 *
	 * @return	void
	 * @throws	RuntimeException
	 */
	private function saveDiagnoses()
	{
		// Rows are replaced as a whole; the record row itself is never deleted (spec §5.1)
		if (!$this->db->query("DELETE FROM ".$this->db->prefix()."medrecord_diagnosis WHERE fk_medrecord = ".((int) $this->id))) {
			$this->error = $this->db->lasterror();
			throw new RuntimeException('diagnosis reset failed');
		}
		$pos = 0;
		foreach ($this->diagnoses as $d) {
			$sql = "INSERT INTO ".$this->db->prefix()."medrecord_diagnosis (fk_medrecord, diag_type, code, label, is_primary, position) VALUES (";
			$sql .= ((int) $this->id).", '".$this->db->escape($d['diag_type'])."', '".$this->db->escape($d['code'])."', '".$this->db->escape($d['label'])."', ".((int) $d['is_primary']).", ".($pos++).")";
			if (!$this->db->query($sql)) {
				$this->error = $this->db->lasterror();
				throw new RuntimeException('diagnosis insert failed');
			}
		}
	}

	// ------------------------------------------------------------ fetch

	/**
	 * @param	int		$id		Rowid
	 * @param	string	$ref	Or reference
	 * @return	int				1 found, 0 not found, <0 error
	 */
	public function fetch($id = 0, $ref = '')
	{
		global $conf;

		$sql = "SELECT m.* FROM ".$this->db->prefix()."medrecord as m WHERE m.entity = ".((int) $conf->entity);
		if ((int) $id > 0) {
			$sql .= " AND m.rowid = ".((int) $id);
		} elseif ($ref !== '') {
			$sql .= " AND m.ref = '".$this->db->escape($ref)."'";
		} else {
			return -1;
		}
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);
		if (!$obj) {
			return 0;
		}
		$this->id = (int) $obj->rowid;
		$this->entity = (int) $obj->entity;
		$this->ref = $obj->ref;
		$this->fk_patient = (int) $obj->fk_patient;
		$this->fk_doctor = (int) $obj->fk_doctor;
		$this->fk_department = $obj->fk_department ? (int) $obj->fk_department : null;
		$this->visit_date = $this->db->jdate($obj->visit_date);
		$this->visit_type = (int) $obj->visit_type;
		foreach (self::TEXT_FIELDS as $f) {
			$this->$f = $obj->$f;
		}
		$this->fk_ref_medrecord = $obj->fk_ref_medrecord ? (int) $obj->fk_ref_medrecord : null;
		$this->status = (int) $obj->status;
		$this->date_signed = $obj->date_signed ? $this->db->jdate($obj->date_signed) : null;
		$this->fk_user_sign = $obj->fk_user_sign ? (int) $obj->fk_user_sign : null;
		$this->void_reason = $obj->void_reason;
		$this->date_void = $obj->date_void ? $this->db->jdate($obj->date_void) : null;
		$this->fk_user_void = $obj->fk_user_void ? (int) $obj->fk_user_void : null;
		$this->fk_user_creat = (int) $obj->fk_user_creat;
		$this->fk_user_modif = $obj->fk_user_modif ? (int) $obj->fk_user_modif : null;
		$this->date_creation = $this->db->jdate($obj->date_creation);
		$this->tms = $this->db->jdate($obj->tms);

		$this->diagnoses = array();
		$resql = $this->db->query("SELECT diag_type, code, label, is_primary FROM ".$this->db->prefix()."medrecord_diagnosis WHERE fk_medrecord = ".$this->id." ORDER BY position ASC");
		if ($resql) {
			while ($d = $this->db->fetch_object($resql)) {
				$this->diagnoses[] = array('code' => $d->code, 'label' => $d->label, 'is_primary' => (int) $d->is_primary, 'diag_type' => $d->diag_type);
			}
			$this->db->free($resql);
		}
		return 1;
	}

	// ------------------------------------------------------------ update

	/**
	 * Save edits. Allowed on drafts (canEdit) or on signed records within the
	 * lock window (audited as MODIFY_AFTER_SIGN). Patient and ref never change.
	 *
	 * @param	User	$user	Acting user
	 * @return	int				1 ok, <0 error
	 */
	public function update(User $user)
	{
		$this->error = '';
		if ($this->id <= 0) {
			return -1;
		}
		if (!$this->canEdit($user)) {
			$this->error = 'MedRecordErrNotEditable';
			return -2;
		}
		if (!$this->validate(false)) {
			return -1;
		}
		$this->snapshotTcmLabels();
		$this->normalizeDiagnoses();
		$afterSign = ((int) $this->status === MEDRECORD_STATUS_SIGNED);

		// Field-level diff against the stored row, for the audit trail
		$changes = array();
		$old = new MedRecord($this->db);
		if ($old->fetch($this->id) > 0) {
			$changes = $this->diffAgainst($old);
		}

		$this->db->begin();
		try {
			$sql = "UPDATE ".$this->db->prefix()."medrecord SET";
			$sql .= " fk_doctor = ".$this->fk_doctor;
			$sql .= ", fk_department = ".((int) $this->fk_department > 0 ? (int) $this->fk_department : 'NULL');
			$sql .= ", visit_date = '".$this->db->idate($this->visit_date)."'";
			$sql .= ", visit_type = ".$this->visit_type;
			foreach (self::TEXT_FIELDS as $f) {
				$sql .= ", ".$f." = ".$this->nullOrString($this->$f);
			}
			$sql .= ", fk_user_modif = ".((int) $user->id);
			$sql .= " WHERE rowid = ".((int) $this->id)." AND status <> ".MEDRECORD_STATUS_VOIDED;
			if (!$this->db->query($sql)) {
				$this->error = $this->db->lasterror();
				throw new RuntimeException('record update failed');
			}
			$this->saveDiagnoses();
			patient_audit($this->db, $this->fk_patient, $afterSign ? 'MEDRECORD_MODIFY_AFTER_SIGN' : 'MEDRECORD_MODIFY', $user,
				array('ref' => $this->ref, 'record' => $this->id, 'changes' => $changes));
			$this->db->commit();
			return 1;
		} catch (Throwable $e) {
			if (empty($this->error)) {
				$this->error = $e->getMessage();
			}
			$this->db->rollback();
			return -1;
		}
	}

	// ------------------------------------------------------------ state machine

	/**
	 * Draft -> signed. Requires chief complaint and at least one diagnosis.
	 *
	 * @param	User	$user	Signing doctor
	 * @return	int				1 ok, <0 error
	 */
	public function sign(User $user)
	{
		$this->error = '';
		if ($this->id <= 0 || !$this->canSign($user)) {
			$this->error = 'MedRecordErrCannotSign';
			return -2;
		}
		if (!$this->validate(true)) {
			return -1;
		}
		$now = dol_now();
		$this->db->begin();
		$sql = "UPDATE ".$this->db->prefix()."medrecord SET status = ".MEDRECORD_STATUS_SIGNED.", date_signed = '".$this->db->idate($now)."', fk_user_sign = ".((int) $user->id);
		$sql .= " WHERE rowid = ".((int) $this->id)." AND status = ".MEDRECORD_STATUS_DRAFT;
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}
		if ($this->db->affected_rows($resql) < 1) {
			// Someone else signed or voided it meanwhile
			$this->error = 'MedRecordErrCannotSign';
			$this->db->rollback();
			return -2;
		}
		$this->status = MEDRECORD_STATUS_SIGNED;
		$this->date_signed = $now;
		$this->fk_user_sign = (int) $user->id;
		patient_audit($this->db, $this->fk_patient, 'MEDRECORD_SIGN', $user, array('ref' => $this->ref, 'record' => $this->id));
		$this->db->commit();
		return 1;
	}

	/**
	 * Draft/signed -> voided with a mandatory reason. Nothing is deleted.
	 *
	 * @param	User	$user	Acting user
	 * @param	string	$reason	Reason (required)
	 * @return	int				1 ok, <0 error
	 */
	public function void(User $user, $reason)
	{
		$this->error = '';
		$reason = trim((string) $reason);
		if ($this->id <= 0 || !$this->canVoid($user)) {
			$this->error = 'MedRecordErrCannotVoid';
			return -2;
		}
		if ($reason === '') {
			$this->error = 'MedRecordErrVoidReasonRequired';
			return -1;
		}
		$now = dol_now();
		$this->db->begin();
		$sql = "UPDATE ".$this->db->prefix()."medrecord SET status = ".MEDRECORD_STATUS_VOIDED.", void_reason = '".$this->db->escape(dol_substr($reason, 0, 255))."',";
		$sql .= " date_void = '".$this->db->idate($now)."', fk_user_void = ".((int) $user->id);
		$sql .= " WHERE rowid = ".((int) $this->id)." AND status <> ".MEDRECORD_STATUS_VOIDED;
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}
		$this->status = MEDRECORD_STATUS_VOIDED;
		$this->void_reason = $reason;
		$this->date_void = $now;
		$this->fk_user_void = (int) $user->id;
		patient_audit($this->db, $this->fk_patient, 'MEDRECORD_VOID', $user, array('ref' => $this->ref, 'record' => $this->id, 'reason' => dol_substr($reason, 0, 100)));
		$this->db->commit();
		return 1;
	}

	/**
	 * Prefill a new follow-up draft from this (signed) record: diagnoses,
	 * TCM disease/syndrome, treatment principle; fk_ref_medrecord set.
	 *
	 * @return	MedRecord	Unsaved draft
	 */
	public function newFollowUp()
	{
		$next = new MedRecord($this->db);
		$next->fk_patient = $this->fk_patient;
		$next->fk_doctor = $this->fk_doctor;
		$next->fk_department = $this->fk_department;
		$next->visit_type = 2;
		$next->fk_ref_medrecord = $this->id;
		$next->diagnoses = $this->diagnoses;
		$next->tcm_disease_code = $this->tcm_disease_code;
		$next->tcm_disease_label = $this->tcm_disease_label;
		$next->tcm_syndrome_code = $this->tcm_syndrome_code;
		$next->tcm_syndrome_label = $this->tcm_syndrome_label;
		$next->treatment_principle = $this->treatment_principle;
		$next->past_history_snapshot = $this->past_history_snapshot;
		return $next;
	}

	// ------------------------------------------------------------ list

	/**
	 * @param	array	$f		Filters: q (ref/card/name), doctor, status (-1 all, else value), from, to (timestamps), patient
	 * @param	int		$limit	Page size
	 * @param	int		$offset	Offset
	 * @return	array{total:int,rows:array<int,object>}|null
	 */
	public function search(array $f, $limit = 25, $offset = 0)
	{
		global $conf;

		$from = " FROM ".$this->db->prefix()."medrecord as m";
		$from .= " INNER JOIN ".$this->db->prefix()."patient_profile as p ON p.rowid = m.fk_patient";
		$from .= " INNER JOIN ".$this->db->prefix()."societe as s ON s.rowid = p.fk_soc";
		$from .= " LEFT JOIN ".$this->db->prefix()."user as u ON u.rowid = m.fk_doctor";
		$where = " WHERE m.entity = ".((int) $conf->entity);
		if (!empty($f['q'])) {
			$like = "'%".$this->db->escape(trim($f['q']))."%'";
			$where .= " AND (m.ref LIKE ".$like." OR p.card_no LIKE ".$like." OR s.nom LIKE ".$like.")";
		}
		if (!empty($f['patient'])) {
			$where .= " AND m.fk_patient = ".((int) $f['patient']);
		}
		if (!empty($f['doctor'])) {
			$where .= " AND m.fk_doctor = ".((int) $f['doctor']);
		}
		if (isset($f['status']) && (int) $f['status'] >= 0) {
			$where .= " AND m.status = ".((int) $f['status']);
		} elseif (empty($f['include_voided'])) {
			$where .= " AND m.status <> ".MEDRECORD_STATUS_VOIDED;
		}
		if (!empty($f['from'])) {
			$where .= " AND m.visit_date >= '".$this->db->idate((int) $f['from'])."'";
		}
		if (!empty($f['to'])) {
			$where .= " AND m.visit_date <= '".$this->db->idate((int) $f['to'])."'";
		}

		$resql = $this->db->query("SELECT COUNT(m.rowid) as total".$from.$where);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return null;
		}
		$total = (int) $this->db->fetch_object($resql)->total;
		$this->db->free($resql);

		$sql = "SELECT m.rowid, m.ref, m.fk_patient, m.fk_doctor, m.visit_date, m.visit_type, m.status, m.tcm_disease_label, m.tcm_syndrome_label,";
		$sql .= " p.card_no, s.nom as patient_name, u.lastname, u.firstname,";
		$sql .= " (SELECT d.label FROM ".$this->db->prefix()."medrecord_diagnosis as d WHERE d.fk_medrecord = m.rowid AND d.diag_type = 'WM' ORDER BY d.is_primary DESC, d.position ASC LIMIT 1) as wm_label";
		$sql .= $from.$where." ORDER BY m.visit_date DESC, m.rowid DESC".$this->db->plimit((int) $limit, (int) $offset);
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return null;
		}
		$rows = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$rows[] = $obj;
		}
		$this->db->free($resql);
		return array('total' => $total, 'rows' => $rows);
	}

	// ------------------------------------------------------------ helpers

	/**
	 * Field-level differences between this (new values) and a stored copy,
	 * for the audit trail: field => {old, new}. Long texts are truncated to
	 * keep the audit row readable; diagnoses compared as "code label" lists.
	 *
	 * @param	MedRecord	$old	Stored copy
	 * @return	array<string,array{old:string,new:string}>
	 */
	public function diffAgainst(MedRecord $old)
	{
		$changes = array();
		$norm = function ($v) {
			return trim(str_replace(array("\r\n", "\r"), "\n", (string) $v));
		};
		$scalar = array_merge(array('fk_doctor', 'fk_department', 'visit_type'), self::TEXT_FIELDS);
		foreach ($scalar as $f) {
			$a = $norm($old->$f);
			$b = $norm($this->$f);
			if (in_array($f, array('fk_doctor', 'fk_department', 'visit_type'), true)) {
				$a = (int) $a > 0 ? (string) (int) $a : '';
				$b = (int) $b > 0 ? (string) (int) $b : '';
			}
			if ($a !== $b) {
				$changes[$f] = array('old' => dol_substr($a, 0, 1000), 'new' => dol_substr($b, 0, 1000));
			}
		}
		if ((int) $old->visit_date !== (int) $this->visit_date) {
			$changes['visit_date'] = array('old' => dol_print_date($old->visit_date, 'dayhour'), 'new' => dol_print_date($this->visit_date, 'dayhour'));
		}
		$fmt = function ($list) {
			$out = array();
			foreach ((array) $list as $d) {
				$out[] = (!empty($d['is_primary']) ? '*' : '').$d['code'].' '.$d['label'];
			}
			return implode('; ', $out);
		};
		$a = $fmt($old->diagnoses);
		$b = $fmt($this->diagnoses);
		if ($a !== $b) {
			$changes['diagnoses'] = array('old' => dol_substr($a, 0, 300), 'new' => dol_substr($b, 0, 300));
		}
		return $changes;
	}

	/**
	 * @param	int		$withpicto	0/1
	 * @return	string				Link
	 */
	public function getNomUrl($withpicto = 0)
	{
		$url = dol_buildpath('/medrecord/card.php', 1).'?id='.((int) $this->id);
		$out = '<a href="'.$url.'">';
		if ($withpicto) {
			$out .= img_picto('', $this->picto, 'class="pictofixedwidth"');
		}
		return $out.dol_escape_htmltag($this->ref).'</a>';
	}

	/**
	 * @param	int		$mode	Unused
	 * @return	string			Status badge
	 */
	public function getLibStatut($mode = 0)
	{
		return medrecord_status_badge($this->status);
	}

	/**
	 * @return	string	Primary western diagnosis label or ''
	 */
	public function primaryDiagnosisLabel()
	{
		foreach ($this->diagnoses as $d) {
			if (!empty($d['is_primary'])) {
				return $d['label'];
			}
		}
		return !empty($this->diagnoses) ? $this->diagnoses[0]['label'] : '';
	}

	/**
	 * @param	mixed	$value	Value
	 * @return	string			SQL literal or NULL
	 */
	private function nullOrString($value)
	{
		if ($value === null || $value === '') {
			return 'NULL';
		}
		return "'".$this->db->escape((string) $value)."'";
	}
}
