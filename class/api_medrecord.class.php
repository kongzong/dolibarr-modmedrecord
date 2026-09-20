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

use Luracast\Restler\RestException;

/**
 * \file    htdocs/custom/medrecord/class/api_medrecord.class.php
 * \ingroup medrecord
 * \brief   REST API for medical records (spec §3.5). Same state machine and
 *          permission rules as the UI; medical reads are audited; no endpoint
 *          exposes the patient's ID number.
 */

dol_include_once('/medrecord/class/medrecord.class.php');
dol_include_once('/medrecord/lib/medrecord.lib.php');
dol_include_once('/patient/lib/patient.lib.php');

/**
 * API class for MedRecord module
 *
 * @access protected
 * @class  DolibarrApiAccess {@requires user,external}
 */
class Medrecord extends DolibarrApi
{
	/**
	 * @var DoliDB $db Database object
	 */
	protected $db;

	/**
	 * Constructor
	 *
	 * @url GET /
	 */
	public function __construct()
	{
		global $db;
		$this->db = $db;
	}

	/**
	 * List records.
	 *
	 * @url	GET records
	 *
	 * @param	string	$q			Record no. / card no. / patient name
	 * @param	int		$patient	Patient rowid
	 * @param	int		$doctor		Doctor user id
	 * @param	int		$status		-1 all non-voided (default), 0 draft, 1 signed, 9 voided
	 * @param	string	$from		Visit date from (YYYY-MM-DD)
	 * @param	string	$to			Visit date to (YYYY-MM-DD)
	 * @param	int		$limit		Page size (max 100)
	 * @param	int		$page		Page (0-based)
	 * @return	array{total:int,rows:array<int,array<string,mixed>>}
	 * @throws RestException 403 Not allowed
	 */
	public function index($q = '', $patient = 0, $doctor = 0, $status = -1, $from = '', $to = '', $limit = 25, $page = 0)
	{
		if (!DolibarrApiAccess::$user->hasRight('medrecord', 'read')) {
			throw new RestException(403);
		}
		$limit = max(1, min(100, (int) $limit));
		$page = max(0, (int) $page);
		$filters = array(
			'q' => (string) $q,
			'patient' => (int) $patient,
			'doctor' => (int) $doctor,
			'status' => (int) $status,
			'from' => $from !== '' ? $this->dateToTs($from, false) : 0,
			'to' => $to !== '' ? $this->dateToTs($to, true) : 0,
		);
		$dao = new MedRecord($this->db);
		$result = $dao->search($filters, $limit, $limit * $page);
		if ($result === null) {
			throw new RestException(500, 'Search failed');
		}
		$rows = array();
		foreach ($result['rows'] as $r) {
			$rows[] = array(
				'id' => (int) $r->rowid,
				'ref' => $r->ref,
				'fk_patient' => (int) $r->fk_patient,
				'card_no' => $r->card_no,
				'patient_name' => $r->patient_name,
				'fk_doctor' => (int) $r->fk_doctor,
				'doctor_name' => trim($r->lastname.' '.$r->firstname),
				'visit_date' => dol_print_date($this->db->jdate($r->visit_date), 'dayhourrfc'),
				'visit_type' => (int) $r->visit_type,
				'status' => (int) $r->status,
				'primary_diagnosis' => $r->wm_label,
				'tcm_disease' => $r->tcm_disease_label,
				'tcm_syndrome' => $r->tcm_syndrome_label,
			);
		}
		return array('total' => (int) $result['total'], 'rows' => $rows);
	}

	/**
	 * Get one record (medical data: audited as MEDRECORD_READ).
	 *
	 * @url	GET records/{id}
	 *
	 * @param	int		$id		Record rowid
	 * @return	array<string,mixed>
	 * @throws RestException 403 Not allowed
	 * @throws RestException 404 Not found
	 */
	public function get($id)
	{
		if (!DolibarrApiAccess::$user->hasRight('medrecord', 'read')) {
			throw new RestException(403);
		}
		$rec = $this->load($id);
		patient_audit($this->db, $rec->fk_patient, 'MEDRECORD_READ', DolibarrApiAccess::$user, array('ref' => $rec->ref, 'record' => $rec->id, 'via' => 'api'));
		return $this->fields($rec);
	}

	/**
	 * Create a draft.
	 *
	 * Body: { "fk_patient": 1, "fk_doctor": 2, "fk_department": 0, "visit_date": "YYYY-MM-DD HH:MM", "visit_type": 1|2,
	 *         "chief_complaint": "...", "present_illness": "...", "past_history_snapshot": "...", "tongue": "...", "pulse": "...",
	 *         "exam_note": "...", "tcm_disease_code": "BM-001", "tcm_syndrome_code": "ZH-001",
	 *         "treatment_principle": "...", "advice": "...", "fk_ref_medrecord": 0,
	 *         "diagnoses": [ { "code": "J06.900", "label": "", "is_primary": 1 } ] }
	 *
	 * @url	POST records
	 *
	 * @param	array	$request_data	Body
	 * @return	array<string,mixed>
	 * @throws RestException 403 Not allowed
	 * @throws RestException 400 Bad parameters
	 */
	public function post($request_data = null)
	{
		if (!DolibarrApiAccess::$user->hasRight('medrecord', 'write')) {
			throw new RestException(403);
		}
		$data = is_array($request_data) ? $request_data : array();
		$rec = new MedRecord($this->db);
		$rec->fk_patient = isset($data['fk_patient']) ? (int) $data['fk_patient'] : 0;
		$rec->fk_ref_medrecord = !empty($data['fk_ref_medrecord']) ? (int) $data['fk_ref_medrecord'] : null;
		$this->apply($rec, $data);
		$result = $rec->create(DolibarrApiAccess::$user);
		if ($result <= 0) {
			throw new RestException(400, 'Creation failed: '.$rec->error);
		}
		return $this->fields($rec);
	}

	/**
	 * Update a draft (or a signed record within the configured lock window).
	 * Body: same fields as POST except fk_patient / fk_ref_medrecord.
	 *
	 * @url	PUT records/{id}
	 *
	 * @param	int		$id				Record rowid
	 * @param	array	$request_data	Body
	 * @return	array<string,mixed>
	 * @throws RestException 403 Not allowed / not editable
	 * @throws RestException 404 Not found
	 * @throws RestException 400 Bad parameters
	 */
	public function put($id, $request_data = null)
	{
		if (!DolibarrApiAccess::$user->hasRight('medrecord', 'write')) {
			throw new RestException(403);
		}
		$rec = $this->load($id);
		if (!$rec->canEdit(DolibarrApiAccess::$user)) {
			throw new RestException(403, 'Record is not editable');
		}
		$this->apply($rec, is_array($request_data) ? $request_data : array());
		$result = $rec->update(DolibarrApiAccess::$user);
		if ($result <= 0) {
			throw new RestException($result === -2 ? 403 : 400, 'Update failed: '.$rec->error);
		}
		return $this->fields($rec);
	}

	/**
	 * Sign a draft (attending doctor or admin with 'sign').
	 *
	 * @url	POST records/{id}/sign
	 *
	 * @param	int		$id		Record rowid
	 * @return	array<string,mixed>
	 * @throws RestException 403 Not allowed
	 * @throws RestException 404 Not found
	 * @throws RestException 400 Missing complaint / diagnosis
	 */
	public function sign($id)
	{
		if (!DolibarrApiAccess::$user->hasRight('medrecord', 'sign')) {
			throw new RestException(403);
		}
		$rec = $this->load($id);
		$result = $rec->sign(DolibarrApiAccess::$user);
		if ($result <= 0) {
			throw new RestException($result === -2 ? 403 : 400, 'Sign failed: '.$rec->error);
		}
		return $this->fields($rec);
	}

	/**
	 * Void a record. Body: { "reason": "..." } (required). Nothing is deleted.
	 *
	 * @url	POST records/{id}/void
	 *
	 * @param	int		$id				Record rowid
	 * @param	array	$request_data	Body
	 * @return	array<string,mixed>
	 * @throws RestException 403 Not allowed
	 * @throws RestException 404 Not found
	 * @throws RestException 400 Reason missing
	 */
	public function void($id, $request_data = null)
	{
		if (!DolibarrApiAccess::$user->hasRight('medrecord', 'void')) {
			throw new RestException(403);
		}
		$rec = $this->load($id);
		$reason = is_array($request_data) && isset($request_data['reason']) ? (string) $request_data['reason'] : '';
		$result = $rec->void(DolibarrApiAccess::$user, $reason);
		if ($result <= 0) {
			throw new RestException($result === -2 ? 403 : 400, 'Void failed: '.$rec->error);
		}
		return $this->fields($rec);
	}

	/**
	 * Search a dictionary (autocomplete for client apps).
	 *
	 * @url	GET dictionaries/{name}
	 *
	 * @param	string	$name	icd10 | tcm_disease | tcm_syndrome
	 * @param	string	$q		Term (code prefix or label containment)
	 * @param	int		$limit	Max rows (max 100)
	 * @return	array<int,array{code:string,label:string,extra_code:?string}>
	 * @throws RestException 403 Not allowed
	 * @throws RestException 400 Unknown dictionary
	 */
	public function getDictionary($name, $q = '', $limit = 20)
	{
		if (!DolibarrApiAccess::$user->hasRight('medrecord', 'read')) {
			throw new RestException(403);
		}
		$tables = array('icd10' => 'c_medrecord_icd10', 'tcm_disease' => 'c_medrecord_tcm_disease', 'tcm_syndrome' => 'c_medrecord_tcm_syndrome');
		if (!isset($tables[$name])) {
			throw new RestException(400, 'Unknown dictionary');
		}
		if ((string) $q === '') {
			$out = array();
			foreach (medrecord_dict_options($this->db, $tables[$name], max(1, min(100, (int) $limit))) as $code => $label) {
				$out[] = array('code' => $code, 'label' => $label, 'extra_code' => null);
			}
			return $out;
		}
		return medrecord_dict_search($this->db, $tables[$name], (string) $q, max(1, min(100, (int) $limit)));
	}

	// ------------------------------------------------------------ helpers

	/**
	 * @param	int		$id		Record rowid
	 * @return	MedRecord
	 * @throws	RestException 404
	 */
	private function load($id)
	{
		$rec = new MedRecord($this->db);
		if ((int) $id <= 0 || $rec->fetch((int) $id) <= 0) {
			throw new RestException(404, 'Record not found');
		}
		return $rec;
	}

	/**
	 * Copy editable body fields onto the record.
	 *
	 * @param	MedRecord	$rec	Target
	 * @param	array		$data	Body
	 * @return	void
	 */
	private function apply(MedRecord $rec, array $data)
	{
		if (isset($data['fk_doctor'])) {
			$rec->fk_doctor = (int) $data['fk_doctor'];
		}
		if (array_key_exists('fk_department', $data)) {
			$rec->fk_department = (int) $data['fk_department'] > 0 ? (int) $data['fk_department'] : null;
		}
		if (isset($data['visit_type'])) {
			$rec->visit_type = (int) $data['visit_type'];
		}
		if (!empty($data['visit_date'])) {
			$ts = $this->dateToTs((string) $data['visit_date'], false);
			if ($ts <= 0) {
				throw new RestException(400, 'visit_date must be YYYY-MM-DD or YYYY-MM-DD HH:MM');
			}
			$rec->visit_date = $ts;
		}
		foreach (MedRecord::TEXT_FIELDS as $f) {
			if (array_key_exists($f, $data) && !in_array($f, array('tcm_disease_label', 'tcm_syndrome_label'), true)) {
				$rec->$f = $data[$f] === null ? null : (string) $data[$f];
			}
		}
		if (isset($data['diagnoses']) && is_array($data['diagnoses'])) {
			$rec->diagnoses = array();
			foreach ($data['diagnoses'] as $d) {
				if (!is_array($d) || empty($d['code'])) {
					continue;
				}
				$rec->diagnoses[] = array('code' => (string) $d['code'], 'label' => isset($d['label']) ? (string) $d['label'] : '', 'is_primary' => !empty($d['is_primary']) ? 1 : 0, 'diag_type' => 'WM');
			}
		}
	}

	/**
	 * @param	string	$s			YYYY-MM-DD[ HH:MM[:SS]]
	 * @param	bool	$endOfDay	Use 23:59:59 when no time given
	 * @return	int					Timestamp or 0
	 */
	private function dateToTs($s, $endOfDay)
	{
		if (!preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})(?:[ T]([0-9]{2}):([0-9]{2})(?::([0-9]{2}))?)?$/', trim($s), $m)) {
			return 0;
		}
		$h = isset($m[4]) ? (int) $m[4] : ($endOfDay ? 23 : 0);
		$i = isset($m[5]) ? (int) $m[5] : ($endOfDay ? 59 : 0);
		$sec = isset($m[6]) ? (int) $m[6] : ($endOfDay ? 59 : 0);
		return (int) dol_mktime($h, $i, $sec, (int) $m[2], (int) $m[3], (int) $m[1]);
	}

	/**
	 * Full record fields. No patient identity beyond fk_patient / card no.
	 *
	 * @param	MedRecord	$r	Record
	 * @return	array<string,mixed>
	 */
	private function fields(MedRecord $r)
	{
		$summary = patient_get_summary($this->db, $r->fk_patient);
		$out = array(
			'id' => (int) $r->id,
			'ref' => $r->ref,
			'fk_patient' => (int) $r->fk_patient,
			'card_no' => $summary ? $summary['card_no'] : null,
			'patient_name' => $summary ? $summary['name'] : null,
			'fk_doctor' => (int) $r->fk_doctor,
			'fk_department' => $r->fk_department,
			'visit_date' => dol_print_date($r->visit_date, 'dayhourrfc'),
			'visit_type' => (int) $r->visit_type,
			'status' => (int) $r->status,
			'status_label' => medrecord_status_label($r->status),
			'fk_ref_medrecord' => $r->fk_ref_medrecord,
			'diagnoses' => $r->diagnoses,
			'date_signed' => $r->date_signed ? dol_print_date($r->date_signed, 'dayhourrfc') : null,
			'fk_user_sign' => $r->fk_user_sign,
			'void_reason' => $r->void_reason,
			'date_void' => $r->date_void ? dol_print_date($r->date_void, 'dayhourrfc') : null,
			'date_creation' => dol_print_date($r->date_creation, 'dayhourrfc'),
		);
		foreach (MedRecord::TEXT_FIELDS as $f) {
			$out[$f] = $r->$f;
		}
		return $out;
	}
}
