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
 * \file    htdocs/custom/medrecord/lib/medrecord.lib.php
 * \ingroup medrecord
 * \brief   Shared helpers: status labels, dictionary lookups, admin tabs,
 *          timeline query. Audit goes through patient_audit() of modPatient
 *          with MEDRECORD_* actions (spec §1).
 */

dol_include_once('/patient/lib/patient.lib.php');

/** Record status values (spec §3.3) */
define('MEDRECORD_STATUS_DRAFT', 0);
define('MEDRECORD_STATUS_SIGNED', 1);
define('MEDRECORD_STATUS_VOIDED', 9);

/**
 * @param	int		$status		Status value
 * @return	string				Translated label
 */
function medrecord_status_label($status)
{
	global $langs;
	$langs->load('medrecord@medrecord');
	switch ((int) $status) {
		case MEDRECORD_STATUS_SIGNED:
			return $langs->trans('MedRecordStatusSigned');
		case MEDRECORD_STATUS_VOIDED:
			return $langs->trans('MedRecordStatusVoided');
		default:
			return $langs->trans('MedRecordStatusDraft');
	}
}

/**
 * @param	int		$status		Status value
 * @return	string				Badge HTML
 */
function medrecord_status_badge($status)
{
	$cls = array(MEDRECORD_STATUS_DRAFT => 'badge-status0', MEDRECORD_STATUS_SIGNED => 'badge-status4', MEDRECORD_STATUS_VOIDED => 'badge-status9');
	$status = (int) $status;
	return '<span class="badge '.(isset($cls[$status]) ? $cls[$status] : 'badge-status0').'">'.medrecord_status_label($status).'</span>';
}

/**
 * Active rows of a module dictionary as code => label.
 *
 * @param	DoliDB	$db		Database handler
 * @param	string	$table	c_medrecord_icd10 | c_medrecord_tcm_disease | c_medrecord_tcm_syndrome
 * @param	int		$limit	0 = all (TCM tables are small); ICD-10 callers should use medrecord_dict_search()
 * @return	array<string,string>
 */
function medrecord_dict_options($db, $table, $limit = 0)
{
	if (!preg_match('/^c_medrecord_[a-z0-9_]+$/', $table)) {
		return array();
	}
	$hasPos = ($table !== 'c_medrecord_icd10');
	$sql = "SELECT code, label FROM ".$db->prefix().$table." WHERE active = 1 ORDER BY ".($hasPos ? "pos ASC, label ASC" : "code ASC");
	if ($limit > 0) {
		$sql .= $db->plimit((int) $limit, 0);
	}
	$out = array();
	$resql = $db->query($sql);
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			$out[$obj->code] = $obj->label;
		}
		$db->free($resql);
	}
	return $out;
}

/**
 * Search a dictionary by code prefix or label containment (autocomplete).
 *
 * @param	DoliDB	$db		Database handler
 * @param	string	$table	Dictionary table without prefix
 * @param	string	$q		Term
 * @param	int		$limit	Max rows
 * @return	array<int,array{code:string,label:string,extra_code:?string}>
 */
function medrecord_dict_search($db, $table, $q, $limit = 20)
{
	if (!preg_match('/^c_medrecord_[a-z0-9_]+$/', $table)) {
		return array();
	}
	$q = trim((string) $q);
	if ($q === '') {
		return array();
	}
	$isIcd = ($table === 'c_medrecord_icd10');
	$sql = "SELECT code, label".($isIcd ? ", extra_code" : "")." FROM ".$db->prefix().$table;
	$sql .= " WHERE active = 1 AND (code LIKE '".$db->escape($q)."%' OR label LIKE '%".$db->escape($q)."%')";
	$sql .= " ORDER BY CASE WHEN code LIKE '".$db->escape($q)."%' THEN 0 ELSE 1 END, code ASC";
	$sql .= $db->plimit(max(1, (int) $limit), 0);
	$out = array();
	$resql = $db->query($sql);
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			$out[] = array('code' => $obj->code, 'label' => $obj->label, 'extra_code' => $isIcd ? $obj->extra_code : null);
		}
		$db->free($resql);
	}
	return $out;
}

/**
 * Label of a dictionary code (live), or '' when unknown.
 *
 * @param	DoliDB	$db		Database handler
 * @param	string	$table	Dictionary table without prefix
 * @param	string	$code	Code
 * @return	string
 */
function medrecord_dict_label($db, $table, $code)
{
	$code = trim((string) $code);
	if ($code === '' || !preg_match('/^c_medrecord_[a-z0-9_]+$/', $table)) {
		return '';
	}
	$resql = $db->query("SELECT label FROM ".$db->prefix().$table." WHERE code = '".$db->escape($code)."'");
	if ($resql) {
		$obj = $db->fetch_object($resql);
		$db->free($resql);
		if ($obj) {
			return $obj->label;
		}
	}
	return '';
}

/**
 * Timeline of a patient's records (newest first): ref, date, status, doctor,
 * primary diagnosis label. Callers must hold 'medrecord read'.
 *
 * @param	DoliDB	$db			Database handler
 * @param	int		$fkPatient	Patient rowid
 * @param	int		$limit		Max rows
 * @param	bool	$includeVoided	Include status 9
 * @return	array<int,object>
 */
function medrecord_timeline($db, $fkPatient, $limit = 20, $includeVoided = false)
{
	global $conf;

	$sql = "SELECT m.rowid, m.ref, m.visit_date, m.visit_type, m.status, m.fk_doctor, m.tcm_disease_label, m.tcm_syndrome_label,";
	$sql .= " u.lastname, u.firstname,";
	$sql .= " (SELECT d.label FROM ".$db->prefix()."medrecord_diagnosis as d WHERE d.fk_medrecord = m.rowid AND d.diag_type = 'WM' ORDER BY d.is_primary DESC, d.position ASC LIMIT 1) as wm_label";
	$sql .= " FROM ".$db->prefix()."medrecord as m";
	$sql .= " LEFT JOIN ".$db->prefix()."user as u ON u.rowid = m.fk_doctor";
	$sql .= " WHERE m.fk_patient = ".((int) $fkPatient)." AND m.entity = ".((int) $conf->entity);
	if (!$includeVoided) {
		$sql .= " AND m.status <> ".MEDRECORD_STATUS_VOIDED;
	}
	$sql .= " ORDER BY m.visit_date DESC, m.rowid DESC".$db->plimit(max(1, (int) $limit), 0);
	$out = array();
	$resql = $db->query($sql);
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			$out[] = $obj;
		}
		$db->free($resql);
	}
	return $out;
}

/**
 * Tabs of the record card.
 *
 * @param	object	$object		Loaded record (id)
 * @return	array<int,array{0:string,1:string,2:string}>
 */
function medrecord_prepare_head($object)
{
	global $langs;

	$langs->load('medrecord@medrecord');
	$h = 0;
	$head = array();
	$head[$h][0] = dol_buildpath('/medrecord/card.php', 1).'?id='.((int) $object->id);
	$head[$h][1] = $langs->trans('MedRecordTab');
	$head[$h][2] = 'card';
	$h++;
	return $head;
}

/**
 * Tabs for the module admin pages.
 *
 * @return	array<int,array{0:string,1:string,2:string}>
 */
function medrecord_admin_prepare_head()
{
	global $langs;

	$langs->load('medrecord@medrecord');
	$h = 0;
	$head = array();
	$head[$h][0] = dol_buildpath('/medrecord/admin/setup.php', 1);
	$head[$h][1] = $langs->trans('Settings');
	$head[$h][2] = 'settings';
	$h++;
	return $head;
}
