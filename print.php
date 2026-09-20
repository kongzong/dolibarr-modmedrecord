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
 * \file    htdocs/custom/medrecord/print.php
 * \ingroup medrecord
 * \brief   Print view of one record (A4, no navigation). Footer carries the
 *          operator watermark (user / time / record no.); opening it writes
 *          MEDRECORD_PRINT (spec §3.3 / §0). Never shows the ID number.
 */

if (!defined('NOREQUIREMENU')) {
	define('NOREQUIREMENU', '1');
}

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

dol_include_once('/patient/lib/patient.lib.php');
dol_include_once('/medrecord/lib/medrecord.lib.php');
dol_include_once('/medrecord/class/medrecord.class.php');

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 * @var Societe $mysoc
 */

$langs->loadLangs(array("patient@patient", "medrecord@medrecord"));

$id = GETPOSTINT('id');
if ($id <= 0 || !$user->hasRight('medrecord', 'read')) {
	accessforbidden();
}
$object = new MedRecord($db);
if ($object->fetch($id) <= 0) {
	accessforbidden($langs->trans("ErrorRecordNotFound"));
}
$summary = patient_get_summary($db, $object->fk_patient);
$doctors = patient_doctor_options($db);
$departments = patient_dict_rows($db, 'c_patient_department');

patient_audit($db, $object->fk_patient, 'MEDRECORD_PRINT', $user, array('ref' => $object->ref, 'record' => $object->id));

$printedBy = trim($user->lastname.' '.$user->firstname);
if ($printedBy === '') {
	$printedBy = $user->login;
}

llxHeader('', $object->ref, '', 0, 0, 0, '', '', '', 'medrecord-print');

print '<style>
@page { size: A4; margin: 15mm; }
.mrp { max-width: 180mm; margin: 0 auto; font-size: 11pt; line-height: 1.5; color: #000; }
.mrp h1 { text-align: center; font-size: 16pt; margin: 0 0 4mm 0; letter-spacing: 4px; }
.mrp .head { display: flex; justify-content: space-between; border-bottom: 1px solid #000; padding-bottom: 2mm; margin-bottom: 3mm; font-size: 10pt; }
.mrp table.f { width: 100%; border-collapse: collapse; }
.mrp table.f th { text-align: left; width: 22mm; vertical-align: top; padding: 1.5mm 1mm; font-weight: bold; white-space: nowrap; }
.mrp table.f td { vertical-align: top; padding: 1.5mm 1mm; white-space: pre-wrap; }
.mrp .sec { margin-top: 3mm; border-top: 1px dashed #999; padding-top: 2mm; font-weight: bold; }
.mrp .sign { margin-top: 8mm; display: flex; justify-content: space-between; }
.mrp .foot { margin-top: 6mm; border-top: 1px solid #000; padding-top: 1mm; font-size: 8.5pt; color: #333; display: flex; justify-content: space-between; }
.mrp .void { color: #b00; font-weight: bold; border: 2px solid #b00; display: inline-block; padding: 1mm 3mm; margin-bottom: 2mm; }
.noprint { text-align: center; margin: 4mm 0; }
@media print { .noprint, #id-top, #id-left, .tmenu, .side-nav, .login_block { display: none !important; } body { background: #fff; } #id-container, #id-right { margin: 0; padding: 0; width: auto; } }
</style>';

print '<div class="noprint"><a class="button" href="javascript:window.print()">'.$langs->trans("MedRecordPrint").'</a> ';
print '<a class="button" href="'.dol_buildpath('/medrecord/card.php', 1).'?id='.$object->id.'">'.$langs->trans("Back").'</a></div>';

print '<div class="mrp">';
print '<h1>'.dol_escape_htmltag($mysoc->name).' '.$langs->trans("MedRecordPrintTitle").'</h1>';
if ($object->status == MEDRECORD_STATUS_VOIDED) {
	print '<div class="void">'.$langs->trans("MedRecordStatusVoided").': '.dol_escape_htmltag((string) $object->void_reason).'</div>';
}
print '<div class="head">';
print '<span>'.$langs->trans("MedRecordRef").': <strong>'.dol_escape_htmltag($object->ref).'</strong></span>';
print '<span>'.$langs->trans("MedRecordVisitDate").': '.dol_print_date($object->visit_date, 'dayhour').'</span>';
print '<span>'.$langs->trans($object->visit_type == 2 ? "MedRecordVisitFollow" : "MedRecordVisitFirst").'</span>';
print '</div>';

print '<table class="f">';
if ($summary) {
	$line = array($summary['name'], $summary['card_no'], $summary['gender_label']);
	if ($summary['age'] !== null) {
		$line[] = $langs->trans("PatientAgeYears", $summary['age']);
	}
	if ($summary['phone'] !== '') {
		$line[] = $summary['phone'];
	}
	print '<tr><th>'.$langs->trans("MedRecordPatient").'</th><td>'.dol_escape_htmltag(implode('　', array_filter($line))).'</td></tr>';
	if (is_array($summary['allergies'])) {
		$names = array();
		foreach ($summary['allergies'] as $a) {
			$names[] = $a['name'].($a['severity'] >= 3 ? '(重)' : '');
		}
		print '<tr><th>'.$langs->trans("PatientAllergies").'</th><td>'.($names ? dol_escape_htmltag(implode('、', $names)) : $langs->trans("PatientAllergyNone")).'</td></tr>';
	}
}
print '<tr><th>'.$langs->trans("MedRecordDoctor").'</th><td>'.dol_escape_htmltag(isset($doctors[$object->fk_doctor]) ? $doctors[$object->fk_doctor] : '').(isset($departments[$object->fk_department]) ? '　'.dol_escape_htmltag($departments[$object->fk_department]) : '').'</td></tr>';
print '<tr><th>'.$langs->trans("MedRecordChiefComplaint").'</th><td>'.dol_escape_htmltag((string) $object->chief_complaint).'</td></tr>';
print '<tr><th>'.$langs->trans("MedRecordPresentIllness").'</th><td>'.dol_escape_htmltag((string) $object->present_illness).'</td></tr>';
print '<tr><th>'.$langs->trans("MedRecordPastHistory").'</th><td>'.dol_escape_htmltag((string) $object->past_history_snapshot).'</td></tr>';
print '</table>';

print '<div class="sec">'.$langs->trans("MedRecordFourDiag").'</div>';
print '<table class="f">';
print '<tr><th>'.$langs->trans("MedRecordTongue").'</th><td>'.dol_escape_htmltag((string) $object->tongue).'</td></tr>';
print '<tr><th>'.$langs->trans("MedRecordPulse").'</th><td>'.dol_escape_htmltag((string) $object->pulse).'</td></tr>';
print '<tr><th>'.$langs->trans("MedRecordExam").'</th><td>'.dol_escape_htmltag((string) $object->exam_note).'</td></tr>';
print '</table>';

print '<div class="sec">'.$langs->trans("MedRecordDiagnosis").'</div>';
print '<table class="f">';
$wm = array();
foreach ($object->diagnoses as $d) {
	$wm[] = ($d['is_primary'] ? '★' : '').$d['code'].' '.$d['label'];
}
print '<tr><th>'.$langs->trans("MedRecordDiagWm").'</th><td>'.dol_escape_htmltag(implode('；', $wm)).'</td></tr>';
print '<tr><th>'.$langs->trans("MedRecordDiagTcm").'</th><td>'.dol_escape_htmltag((string) $object->tcm_disease_label).'</td></tr>';
print '<tr><th>'.$langs->trans("MedRecordSyndrome").'</th><td>'.dol_escape_htmltag((string) $object->tcm_syndrome_label).'</td></tr>';
print '</table>';

print '<div class="sec">'.$langs->trans("MedRecordPlan").'</div>';
print '<table class="f">';
print '<tr><th>'.$langs->trans("MedRecordTreatment").'</th><td>'.dol_escape_htmltag((string) $object->treatment_principle).'</td></tr>';
print '<tr><th>'.$langs->trans("MedRecordAdvice").'</th><td>'.dol_escape_htmltag((string) $object->advice).'</td></tr>';
print '</table>';

print '<div class="sign">';
print '<span>'.$langs->trans("MedRecordSignedBy").': '.($object->fk_user_sign ? dol_escape_htmltag(isset($doctors[$object->fk_user_sign]) ? $doctors[$object->fk_user_sign] : '').' '.dol_print_date($object->date_signed, 'dayhour') : '______________').'</span>';
print '<span>'.medrecord_status_label($object->status).'</span>';
print '</div>';

print '<div class="foot">';
print '<span>'.$langs->trans("MedRecordPrintedBy").': '.dol_escape_htmltag($printedBy).'</span>';
print '<span>'.dol_print_date(dol_now(), 'dayhoursec').'</span>';
print '<span>'.dol_escape_htmltag($object->ref).'</span>';
print '</div>';
print '</div>';

llxFooter();
$db->close();
