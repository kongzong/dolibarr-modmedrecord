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
 * \file    htdocs/custom/medrecord/list.php
 * \ingroup medrecord
 * \brief   Record list: ref / card no / patient name, doctor, status, date
 *          range. Voided records hidden unless filtered explicitly.
 */

$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
dol_include_once('/patient/lib/patient.lib.php');
dol_include_once('/medrecord/lib/medrecord.lib.php');
dol_include_once('/medrecord/class/medicalrecord.class.php');

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array("patient@patient", "medrecord@medrecord"));

if (!$user->hasRight('medrecord', 'read')) {
	accessforbidden();
}

$form = new Form($db);

$search = trim(GETPOST('search', 'alphanohtml'));
$searchDoctor = GETPOSTINT('search_doctor');
$searchStatus = GETPOST('search_status', 'alpha');
$status = ($searchStatus !== '' && is_numeric($searchStatus)) ? (int) $searchStatus : -1;
$dateFrom = dol_mktime(0, 0, 0, GETPOSTINT('search_frommonth'), GETPOSTINT('search_fromday'), GETPOSTINT('search_fromyear'));
$dateTo = dol_mktime(23, 59, 59, GETPOSTINT('search_tomonth'), GETPOSTINT('search_today'), GETPOSTINT('search_toyear'));
if (GETPOST('button_removefilter_x', 'alpha') || GETPOST('button_removefilter', 'alpha')) {
	$search = '';
	$searchDoctor = 0;
	$status = -1;
	$dateFrom = '';
	$dateTo = '';
}

$limit = GETPOSTINT('limit') > 0 ? GETPOSTINT('limit') : $conf->liste_limit;
$page = (int) GETPOST('page', 'int');
if ($page < 0) {
	$page = 0;
}
$offset = $limit * $page;

$dao = new MedicalRecord($db);
$result = $dao->search(array('q' => $search, 'doctor' => $searchDoctor, 'status' => $status, 'from' => $dateFrom, 'to' => $dateTo), $limit, $offset);
if ($result === null) {
	dol_print_error($db, $dao->error);
	exit;
}
$total = $result['total'];
$rows = $result['rows'];
$doctors = patient_doctor_options($db);

llxHeader('', $langs->trans("MedRecordList"));

$param = '&limit='.(int) $limit;
if ($search !== '') {
	$param .= '&search='.urlencode($search);
}
if ($searchDoctor > 0) {
	$param .= '&search_doctor='.$searchDoctor;
}
if ($status >= 0) {
	$param .= '&search_status='.$status;
}
foreach (array('from', 'to') as $bound) {
	foreach (array('day', 'month', 'year') as $part) {
		$v = GETPOSTINT('search_'.$bound.$part);
		if ($v > 0) {
			$param .= '&search_'.$bound.$part.'='.$v;
		}
	}
}

$newcardbutton = '';
if ($user->hasRight('medrecord', 'write')) {
	$newcardbutton = dolGetButtonTitle($langs->trans("MedRecordNew"), '', 'fa fa-plus-circle', dol_buildpath('/medrecord/card.php', 1).'?action=create');
}

print '<form method="GET" action="'.$_SERVER["PHP_SELF"].'" name="formmedrecordlist">';
print '<input type="hidden" name="token" value="'.newToken().'">';

print_barre_liste($langs->trans("MedRecordList"), $page, $_SERVER["PHP_SELF"], $param, '', '', '', $total, $total, 'fa-notes-medical', 0, $newcardbutton, '', $limit, 0, 0, 1);

$statusOptions = array(
	(string) MEDRECORD_STATUS_DRAFT => medrecord_status_label(MEDRECORD_STATUS_DRAFT),
	(string) MEDRECORD_STATUS_SIGNED => medrecord_status_label(MEDRECORD_STATUS_SIGNED),
	(string) MEDRECORD_STATUS_VOIDED => medrecord_status_label(MEDRECORD_STATUS_VOIDED),
);

print '<div class="div-table-responsive">';
print '<table class="tagtable liste centpercent">';
print '<tr class="liste_titre_filter">';
print '<td class="liste_titre" colspan="2"><input type="text" name="search" class="minwidth200" placeholder="'.dol_escape_htmltag($langs->trans("MedRecordSearchHint")).'" value="'.dol_escape_htmltag($search).'"></td>';
print '<td class="liste_titre">'.$form->selectDate($dateFrom, 'search_from', 0, 0, 1, '', 1, 0).' - '.$form->selectDate($dateTo, 'search_to', 0, 0, 1, '', 1, 0).'</td>';
print '<td class="liste_titre">'.$form->selectarray('search_doctor', $doctors, $searchDoctor, 1, 0, 0, '', 0, 0, 0, '', 'maxwidth150').'</td>';
print '<td class="liste_titre"></td>';
print '<td class="liste_titre center">'.$form->selectarray('search_status', $statusOptions, $status >= 0 ? (string) $status : '', 1, 0, 0, '', 0, 0, 0, '', 'maxwidth100').'</td>';
print '<td class="liste_titre center maxwidthsearch">';
print '<button type="submit" class="liste_titre button_search reposition" name="button_search" value="x"><span class="fa fa-search"></span></button>';
print '<button type="submit" class="liste_titre button_removefilter reposition" name="button_removefilter" value="x"><span class="fa fa-remove"></span></button>';
print '</td></tr>';

print '<tr class="liste_titre">';
print '<th>'.$langs->trans("MedRecordRef").'</th>';
print '<th>'.$langs->trans("MedRecordPatient").'</th>';
print '<th>'.$langs->trans("MedRecordVisitDate").'</th>';
print '<th>'.$langs->trans("MedRecordDoctor").'</th>';
print '<th>'.$langs->trans("MedRecordDiagnosis").'</th>';
print '<th class="center">'.$langs->trans("Status").'</th>';
print '<th></th>';
print '</tr>';

if (empty($rows)) {
	print '<tr><td colspan="7"><span class="opacitymedium">'.$langs->trans("NoRecordFound").'</span></td></tr>';
}
foreach ($rows as $row) {
	$url = dol_buildpath('/medrecord/card.php', 1).'?id='.((int) $row->rowid);
	print '<tr class="oddeven"'.((int) $row->status === MEDRECORD_STATUS_VOIDED ? ' style="opacity:.55"' : '').'>';
	print '<td><a href="'.$url.'">'.img_picto('', 'fa-notes-medical', 'class="pictofixedwidth"').dol_escape_htmltag($row->ref).'</a></td>';
	print '<td><a href="'.dol_buildpath('/patient/card.php', 1).'?id='.((int) $row->fk_patient).'">'.dol_escape_htmltag($row->patient_name).'</a> <span class="opacitymedium">'.dol_escape_htmltag($row->card_no).'</span></td>';
	print '<td>'.dol_print_date($db->jdate($row->visit_date), 'dayhour').'</td>';
	print '<td>'.dol_escape_htmltag(trim($row->lastname.' '.$row->firstname)).'</td>';
	print '<td>'.dol_escape_htmltag(trim(($row->wm_label ? $row->wm_label.' ' : '').$row->tcm_disease_label.' '.$row->tcm_syndrome_label)).'</td>';
	print '<td class="center">'.medrecord_status_badge($row->status).'</td>';
	print '<td></td>';
	print '</tr>';
}

print '</table></div>';
print '</form>';

llxFooter();
$db->close();
