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
 * \file    htdocs/custom/medrecord/class/followup.class.php
 * \brief   Follow-up plan (随访计划): a dated reminder attached to a patient
 *          (optionally to the visit that spawned it). Reminder is in-app only:
 *          the workbench lists due/overdue rows on load, no cron involved.
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';

/**
 * Class FollowupPlan
 */
class FollowupPlan extends CommonObject
{
	/** @var string Module element name */
	public $element = 'medrecord_followup';
	/** @var string Table without prefix */
	public $table_element = 'medrecord_followup';

	/** @var int */
	public $rowid;
	/** @var int */
	public $entity;
	/** @var int */
	public $fk_patient;
	/** @var int|null */
	public $fk_medrecord;
	/** @var string Y-m-d */
	public $due_date;
	/** @var string revisit | medication | chronic */
	public $ftype;
	/** @var string|null */
	public $note;
	/** @var int 0 pending, 1 done, 9 cancelled */
	public $status;
	/** @var string|null */
	public $done_date;
	/** @var string|null */
	public $done_note;
	/** @var int|null */
	public $fk_user_creat;
	/** @var string|int */
	public $date_creation;

	/** @var string Last error */
	public $error = '';

	/** @var array<string,string> Fixed ftype set (V1: no dictionary) */
	public static $types = array('revisit', 'medication', 'chronic');

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * @param	int		$id		Rowid
	 * @return	int				1 ok, 0 not found, <0 error
	 */
	public function fetch($id)
	{
		$prefix = $this->db->prefix();
		$sql = "SELECT rowid, entity, fk_patient, fk_medrecord, due_date, ftype, note, status, done_date, done_note, fk_user_creat, date_creation";
		$sql .= " FROM ".$prefix."medrecord_followup WHERE rowid = ".((int) $id);
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$o = $this->db->fetch_object($resql);
		$this->db->free($resql);
		if (!$o) {
			return 0;
		}
		$this->rowid = (int) $o->rowid;
		$this->entity = (int) $o->entity;
		$this->fk_patient = (int) $o->fk_patient;
		$this->fk_medrecord = $o->fk_medrecord !== null ? (int) $o->fk_medrecord : null;
		$this->due_date = (string) $o->due_date;
		$this->ftype = (string) $o->ftype;
		$this->note = $o->note !== null ? (string) $o->note : null;
		$this->status = (int) $o->status;
		$this->done_date = $o->done_date !== null ? (string) $o->done_date : null;
		$this->done_note = $o->done_note !== null ? (string) $o->done_note : null;
		$this->fk_user_creat = $o->fk_user_creat !== null ? (int) $o->fk_user_creat : null;
		$this->date_creation = $o->date_creation;
		return 1;
	}

	/**
	 * Normalise a due date into the 'YYYY-MM-DD' string the DATE column expects.
	 * Callers pass either a timestamp (dol_mktime) or an already formatted
	 * string; without this an int would reach MySQL as a raw number and blow up
	 * with "Incorrect date value".
	 *
	 * @param	mixed	$value	Timestamp, numeric string or date string
	 * @return	string			'YYYY-MM-DD', '' when the value is unusable
	 */
	private function normalizeDueDate($value)
	{
		if ($value === null || $value === '') {
			return '';
		}
		if (is_numeric($value)) {
			$ts = (int) $value;
			return $ts > 0 ? dol_print_date($ts, '%Y-%m-%d') : '';
		}
		$ts = strtotime((string) $value);
		return ($ts !== false && $ts > 0) ? dol_print_date($ts, '%Y-%m-%d') : '';
	}

	/**
	 * Create the plan row.
	 *
	 * @param	User	$user	Author
	 * @return	int				New rowid, <0 error
	 */
	public function create($user)
	{
		$due = $this->normalizeDueDate($this->due_date);
		if ((int) $this->fk_patient <= 0 || !in_array($this->ftype, self::$types, true) || $due === '') {
			$this->error = 'BadValueForPatientTypeOrDate';
			return -1;
		}
		$this->due_date = $due;
		$prefix = $this->db->prefix();
		$sql = "INSERT INTO ".$prefix."medrecord_followup (entity, fk_patient, fk_medrecord, due_date, ftype, note, status, fk_user_creat, date_creation)";
		$sql .= " VALUES (".getDolGlobalInt('entity', 1).", ".((int) $this->fk_patient).", ";
		$sql .= ($this->fk_medrecord > 0 ? (int) $this->fk_medrecord : 'NULL').", '".$this->db->escape($this->due_date)."', ";
		$sql .= "'".$this->db->escape($this->ftype)."', ";
		$sql .= ($this->note !== null && $this->note !== '' ? "'".$this->db->escape($this->note)."'" : 'NULL').", 0, ";
		$sql .= (!empty($user->id) ? (int) $user->id : 'NULL').", '".$this->db->idate(dol_now())."')";
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$this->rowid = (int) $this->db->last_insert_id($prefix.'medrecord_followup');
		return $this->rowid;
	}

	/**
	 * Mark done (status 1).
	 *
	 * @param	string|null	$note	Optional closing note
	 * @return	int					1 ok, <0 error
	 */
	public function markDone($note = null)
	{
		if ($this->rowid <= 0) {
			return -1;
		}
		$prefix = $this->db->prefix();
		$sql = "UPDATE ".$prefix."medrecord_followup SET status = 1, done_date = '".$this->db->idate(dol_now())."'";
		$sql .= ", done_note = ".($note !== null && $note !== '' ? "'".$this->db->escape($note)."'" : 'NULL');
		$sql .= " WHERE rowid = ".((int) $this->rowid);
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$this->status = 1;
		return 1;
	}

	/**
	 * Snooze: push the due date by N days (stays pending).
	 *
	 * @param	int		$days	>0
	 * @return	int				1 ok, <0 error
	 */
	public function snooze($days)
	{
		$days = (int) $days;
		if ($this->rowid <= 0 || $days <= 0) {
			return -1;
		}
		$prefix = $this->db->prefix();
		$sql = "UPDATE ".$prefix."medrecord_followup SET due_date = DATE_ADD(due_date, INTERVAL ".$days." DAY) WHERE rowid = ".((int) $this->rowid);
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		return 1;
	}

	/**
	 * Cancel (status 9).
	 *
	 * @return	int		1 ok, <0 error
	 */
	public function cancel()
	{
		if ($this->rowid <= 0) {
			return -1;
		}
		$prefix = $this->db->prefix();
		$sql = "UPDATE ".$prefix."medrecord_followup SET status = 9 WHERE rowid = ".((int) $this->rowid);
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$this->status = 9;
		return 1;
	}

	/**
	 * All plans of one patient, newest first.
	 *
	 * @param	DoliDB	$db
	 * @param	int		$fkPatient
	 * @param	int		$limit
	 * @return	array<int,object>
	 */
	public static function listByPatient($db, $fkPatient, $limit = 50)
	{
		$prefix = $db->prefix();
		$sql = "SELECT f.rowid, f.due_date, f.ftype, f.note, f.status, f.done_date, f.done_note, f.fk_medrecord, f.date_creation";
		$sql .= " FROM ".$prefix."medrecord_followup as f";
		$sql .= " WHERE f.entity = ".getDolGlobalInt('entity', 1)." AND f.fk_patient = ".((int) $fkPatient);
		$sql .= " ORDER BY f.status ASC, f.due_date ASC, f.rowid DESC";
		$sql .= $db->plimit((int) $limit);
		return self::fetchRows($db, $sql);
	}

	/**
	 * Due today or overdue (pending only), oldest first, with patient names.
	 * Used by the workbench (in-app reminder, no cron).
	 *
	 * @param	DoliDB	$db
	 * @param	int		$limit
	 * @return	array<int,object>	Rows with extra patient_name
	 */
	public static function listDue($db, $limit = 20)
	{
		$prefix = $db->prefix();
		$sql = "SELECT f.rowid, f.due_date, f.ftype, f.note, f.fk_patient, f.fk_medrecord, s.nom as patient_name";
		$sql .= " FROM ".$prefix."medrecord_followup as f";
		$sql .= " INNER JOIN ".$prefix."patient_profile as pp ON pp.rowid = f.fk_patient";
		$sql .= " INNER JOIN ".$prefix."societe as s ON s.rowid = pp.fk_soc";
		$sql .= " WHERE f.entity = ".getDolGlobalInt('entity', 1)." AND f.status = 0 AND f.due_date <= CURDATE()";
		$sql .= " ORDER BY f.due_date ASC, f.rowid ASC";
		$sql .= $db->plimit((int) $limit);
		return self::fetchRows($db, $sql);
	}

	/**
	 * Run a select and collect rows.
	 *
	 * @param	DoliDB	$db
	 * @param	string	$sql
	 * @return	array<int,object>	Empty array on error
	 */
	private static function fetchRows($db, $sql)
	{
		$out = array();
		$resql = $db->query($sql);
		if (!$resql) {
			return $out;
		}
		while ($o = $db->fetch_object($resql)) {
			$out[] = $o;
		}
		$db->free($resql);
		return $out;
	}
}
