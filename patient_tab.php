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
 * \file    htdocs/custom/medrecord/patient_tab.php
 * \ingroup medrecord
 * \brief   "Medical records" tab on the patient card: timeline + new record.
 *          Requires 'medrecord read' (medical data). Phase 1: shell with
 *          timeline; actions arrive with the record card in phase 2.
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

dol_include_once('/patient/class/patientprofile.class.php');
dol_include_once('/patient/lib/patient.lib.php');
dol_include_once('/medrecord/lib/medrecord.lib.php');
dol_include_once('/clinicpay/lib/clinicpay.lib.php');

/**
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array("patient@patient", "medrecord@medrecord"));

$id = GETPOSTINT('id');
if ($id <= 0 || !$user->hasRight('patient', 'read') || !$user->hasRight('medrecord', 'read')) {
	accessforbidden();
}

$patient = new PatientProfile($db);
if ($patient->fetch($id) <= 0) {
	accessforbidden($langs->trans("PatientNotYet"));
}

llxHeader('', $langs->trans("MedRecordTab"));

$head = patient_prepare_head($patient);
print dol_get_fiche_head($head, 'medrecord', $langs->trans("PatientTab"), -1, 'user');

// Patient header in the card/allergies fiche style (no summary banner here;
// the summary mode with quick buttons is for sub-data detail pages, design §5.1)
$linkback = '<a href="'.dol_buildpath('/patient/list.php', 1).'?restore_lastsearch_values=1">'.$langs->trans("BackToList").'</a>';
print '<div class="arearef heightref valignmiddle centpercent">';
print '<div class="inline-block floatleft refid refidpadding">'.img_picto('', 'user', 'class="pictofixedwidth"').'<strong>'.dol_escape_htmltag($patient->card_no).'</strong>';
print ($patient->thirdparty ? ' - '.dol_escape_htmltag($patient->thirdparty->name) : '').'</div>';
print '<div class="inline-block floatright">'.$linkback.'</div>';
print '<div class="clearboth"></div></div>';
print '<div class="underbanner clearboth"></div>';

if ($user->hasRight('medrecord', 'write')) {
	print '<div class="tabsAction">';
	print dolGetButtonAction($langs->trans("MedRecordNew"), '', 'default', dol_buildpath('/medrecord/card.php', 1).'?action=create&fk_patient='.$patient->id, '', 1);
	print '</div>';
}

$rows = medrecord_timeline($db, $patient->id, 50, true);
print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<th>'.$langs->trans("MedRecordRef").'</th>';
print '<th>'.$langs->trans("MedRecordVisitDate").'</th>';
print '<th>'.$langs->trans("MedRecordVisitType").'</th>';
print '<th>'.$langs->trans("MedRecordDoctor").'</th>';
print '<th>'.$langs->trans("MedRecordDiagWm").'</th>';
print '<th>'.$langs->trans("MedRecordDiagTcm").' / '.$langs->trans("MedRecordSyndrome").'</th>';
print '<th class="right">'.$langs->trans("MedRecordVisitTotal").'</th>';
print '<th class="center">'.$langs->trans("Status").'</th>';
print '</tr>';
if (empty($rows)) {
	print '<tr><td colspan="8"><span class="opacitymedium">'.$langs->trans("NoRecordFound").'</span></td></tr>';
}
foreach ($rows as $r) {
	$url = dol_buildpath('/medrecord/card.php', 1).'?id='.((int) $r->rowid);
	print '<tr class="oddeven"'.((int) $r->status === MEDRECORD_STATUS_VOIDED ? ' style="opacity:.55"' : '').'>';
	print '<td><a href="'.$url.'">'.dol_escape_htmltag($r->ref).'</a></td>';
	print '<td>'.dol_print_date($db->jdate($r->visit_date), 'dayhour').'</td>';
	print '<td>'.$langs->trans((int) $r->visit_type === 2 ? "MedRecordVisitFollow" : "MedRecordVisitFirst").'</td>';
	print '<td>'.dol_escape_htmltag(trim($r->lastname.' '.$r->firstname)).'</td>';
	print '<td>'.dol_escape_htmltag((string) $r->wm_label).'</td>';
	print '<td>'.dol_escape_htmltag(trim($r->tcm_disease_label.' '.$r->tcm_syndrome_label)).'</td>';
	print '<td class="right">'.price(clinicpay_bill_total_by_medrecord($db, $r->rowid)).'</td>';
	print '<td class="center">'.medrecord_status_badge($r->status).'</td>';
	print '</tr>';
}
print '</table></div>';

print dol_get_fiche_end();

llxFooter();
$db->close();
