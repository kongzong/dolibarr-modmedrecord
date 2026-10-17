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
 * \file    htdocs/custom/medrecord/followup.php
 * \brief   Follow-up plans of one patient (随访计划): list + create + status
 *          actions (done / snooze 7d / cancel). Entry points: the visit card
 *          button (fk_medrecord) or the workbench (fk_patient). In-app
 *          reminder only — due rows surface on patientindex.php.
 *          Requires 'medrecord read' (write to modify).
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
dol_include_once('/medrecord/class/followup.class.php');
dol_include_once('/medrecord/class/medicalrecord.class.php');

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array("patient@patient", "medrecord@medrecord"));

$id = GETPOSTINT('id');
$action = GETPOST('action', 'aZ09');
$fkPatientParam = GETPOSTINT('fk_patient');
$fkMedrecordParam = GETPOSTINT('fk_medrecord');
$backUrl = (string) GETPOST('backtopage', 'alpha');

$canRead = $user->hasRight('medrecord', 'read');
$canWrite = $user->hasRight('medrecord', 'write');
if (!$canRead) {
	accessforbidden();
}

$form = new Form($db);
$object = new FollowupPlan($db);

// Resolve the patient: explicit param > record param > plan row
$recordRef = '';
if ($fkMedrecordParam > 0) {
	$rec = new MedicalRecord($db);
	if ($rec->fetch($fkMedrecordParam) > 0) {
		$fkPatientParam = (int) $rec->fk_patient;
		$recordRef = (string) $rec->ref;
	} else {
		$fkMedrecordParam = 0;
	}
}
if ($id > 0 && $object->fetch($id) > 0) {
	$fkPatientParam = (int) $object->fk_patient;
}
if ($fkPatientParam <= 0) {
	accessforbidden($langs->trans("ErrorRecordNotFound"));
}
if ($fkPatientParam > 0) {
	$summary = patient_get_summary($db, $fkPatientParam);
	if (!$summary) {
		accessforbidden($langs->trans("ErrorRecordNotFound"));
	}
}

$baseUrl = dol_buildpath('/medrecord/followup.php', 1);
$back = $backUrl !== '' ? $backUrl : ($fkMedrecordParam > 0 ? dol_buildpath('/medrecord/card.php', 1).'?id='.$fkMedrecordParam : $baseUrl.'?fk_patient='.$fkPatientParam);

$today = date('Y-m-d');

// ---------------------------------------------------------------- actions
if ($action == 'add' && $canWrite) {
	$ftype = (string) GETPOST('ftype', 'aZ');
	$due = GETPOST('due_dateyear') ? sprintf('%04d-%02d-%02d', GETPOSTINT('due_dateyear'), GETPOSTINT('due_datemonth'), GETPOSTINT('due_dateday')) : '';
	$object = new FollowupPlan($db);
	$object->fk_patient = $fkPatientParam;
	$object->fk_medrecord = $fkMedrecordParam > 0 ? $fkMedrecordParam : null;
	$object->ftype = in_array($ftype, FollowupPlan::$types, true) ? $ftype : 'revisit';
	$object->due_date = $due;
	$object->note = trim((string) GETPOST('note', 'alphanohtml'));
	if ($due === '' || $due === '0000-00-00') {
		setEventMessages($langs->trans("FollowupPlanErrDate"), null, 'errors');
		header('Location: '.$baseUrl.'?action=create&fk_patient='.$fkPatientParam.($fkMedrecordParam > 0 ? '&fk_medrecord='.$fkMedrecordParam : '').'&token='.newToken());
		exit;
	}
	$ok = $object->create($user);
	setEventMessages($langs->trans($ok > 0 ? "RecordSaved" : ($object->error !== '' ? $object->error : "ErrorFailedToSaveRecord")), null, $ok > 0 ? 'mesgs' : 'errors');
	header('Location: '.$back);
	exit;
}

if ($id > 0 && $object->rowid <= 0) {
	$object->fetch($id); // first fetch failed (bad id); retry keeps state clean
}

if ($id > 0 && $object->rowid > 0 && $canWrite && $object->status == 0) {
	$done = 0;
	if ($action == 'fu_done') {
		$done = $object->markDone(trim((string) GETPOST('done_note', 'alphanohtml')));
	} elseif ($action == 'fu_snooze') {
		$done = $object->snooze(GETPOSTINT('days') > 0 ? GETPOSTINT('days') : 7);
	} elseif ($action == 'fu_cancel') {
		$done = $object->cancel();
	}
	setEventMessages($langs->trans($done > 0 ? "RecordSaved" : ($object->error !== '' ? $object->error : "ErrorFailedToSaveRecord")), null, $done > 0 ? 'mesgs' : 'errors');
	header('Location: '.$back);
	exit;
}

// ---------------------------------------------------------------- view
llxHeader('', $langs->trans("FollowupPlan"));
print patient_summary_banner($summary, array(array('label' => $langs->trans("FollowupPlanList"), 'url' => $baseUrl.'?fk_patient='.$fkPatientParam)), 'medrecord');

$typeLabels = array(
	'revisit' => $langs->trans("FollowupPlanTypeRevisit"),
	'medication' => $langs->trans("FollowupPlanTypeMedication"),
	'chronic' => $langs->trans("FollowupPlanTypeChronic"),
);

// ---- create form
if ($action == 'create' && $canWrite) {
	print load_fiche_titre($langs->trans("FollowupPlanNew"), '', 'fa-bell');
	print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="add">';
	print '<input type="hidden" name="fk_patient" value="'.((int) $fkPatientParam).'">';
	if ($fkMedrecordParam > 0) {
		print '<input type="hidden" name="fk_medrecord" value="'.((int) $fkMedrecordParam).'">';
		print '<input type="hidden" name="backtopage" value="'.dol_escape_htmltag($back).'">';
	}
	print '<table class="border centpercent tableforfieldcreate">';
	if ($recordRef !== '') {
		print '<tr><td class="titlefieldcreate">'.$langs->trans("FollowupPlanFromRecord").'</td><td><a href="'.dol_buildpath('/medrecord/card.php', 1).'?id='.((int) $fkMedrecordParam).'">'.dol_escape_htmltag($recordRef).'</a></td></tr>';
	}
	print '<tr><td class="titlefieldcreate fieldrequired">'.$langs->trans("FollowupPlanType").'</td><td>';
	print $form->selectarray('ftype', $typeLabels, 'revisit', 0, 0, 0, '', 0, 0, 0, '', 'minwidth150');
	print '</td></tr>';
	print '<tr><td class="fieldrequired">'.$langs->trans("FollowupPlanDue").'</td><td>';
	print $form->selectDate(dol_now(), 'due_date', 0, 0, 0, '', 1, 1);
	print ' <span class="inline-block">';
	foreach (array(3, 7, 30) as $d) {
		print '<button type="button" class="butActionSmall fu-quick" data-days="'.$d.'">+'.$d.$langs->trans("Day").'</button> ';
	}
	print '</span></td></tr>';
	print '<tr><td class="tdtop">'.$langs->trans("FollowupPlanNote").'</td><td><textarea name="note" class="quatrevingtpercent" rows="2"></textarea></td></tr>';
	print '</table>';
	print $form->buttonsSaveCancel("Create", "Cancel");
	print '<script>
$(function() {
	$(".fu-quick").on("click", function() {
		var d = new Date(); d.setDate(d.getDate() + parseInt($(this).data("days"), 10));
		$("#due_date").val(d.getFullYear() + "-" + ("0"+(d.getMonth()+1)).slice(-2) + "-" + ("0"+d.getDate()).slice(-2));
	});
});
</script>';
	print '</form>';
	print '<br>';
}

// ---- list
$plans = FollowupPlan::listByPatient($db, $fkPatientParam);
if ($action != 'create') {
	print '<div style="margin-bottom:10px;">';
	if ($canWrite) {
		print '<a class="butAction" href="'.$baseUrl.'?action=create&fk_patient='.((int) $fkPatientParam).($fkMedrecordParam > 0 ? '&fk_medrecord='.((int) $fkMedrecordParam) : '').'">+ '.$langs->trans("FollowupPlanNew").'</a>';
	}
	print '</div>';
}

print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
print '<tr class="liste_titre"><th>'.$langs->trans("FollowupPlanDue").'</th><th>'.$langs->trans("FollowupPlanType").'</th><th>'.$langs->trans("FollowupPlanNote").'</th><th class="center">'.$langs->trans("Status").'</th><th class="center" style="width:220px;"></th></tr>';
if (empty($plans)) {
	print '<tr><td colspan="5"><span class="opacitymedium">'.$langs->trans("FollowupPlanEmpty").'</span></td></tr>';
}
foreach ($plans as $p) {
	$isOverdue = ($p->status == 0 && $p->due_date < $today);
	print '<tr class="oddeven">';
	print '<td'.($isOverdue ? ' style="color:#d32f2f;font-weight:bold;"' : '').'>'.dol_print_date($db->jdate($p->due_date), 'day');
	if ($p->status == 0) {
		print $isOverdue ? ' <span class="badge badge-status8">'.$langs->trans("FollowupPlanOverdue").'</span>' : ($p->due_date == $today ? ' <span class="badge badge-status4">'.$langs->trans("FollowupPlanToday").'</span>' : '');
	}
	print '</td>';
	print '<td>'.dol_escape_htmltag(isset($typeLabels[$p->ftype]) ? $typeLabels[$p->ftype] : $p->ftype).'</td>';
	print '<td>'.dol_escape_htmltag((string) $p->note).'</td>';
	print '<td class="center">';
	if ($p->status == 1) {
		print '<span class="opacitymedium">'.$langs->trans("FollowupPlanDone").'</span>'.($p->done_date ? ' <span class="opacitymedium fontsmall">'.dol_print_date($db->jdate($p->done_date), 'day').'</span>' : '');
	} elseif ($p->status == 9) {
		print '<span class="opacitymedium">'.$langs->trans("FollowupPlanCancelled").'</span>';
	} else {
		print '<span class="badge badge-status0">'.$langs->trans("FollowupPlanPending").'</span>';
	}
	print '</td>';
	print '<td class="center">';
	if ($p->status == 0 && $canWrite) {
		print '<a class="butActionSmall paddingright" href="'.$_SERVER["PHP_SELF"].'?id='.$p->rowid.'&action=fu_done&backtopage='.urlencode($back).'&token='.newToken().'">'.$langs->trans("FollowupPlanMarkDone").'</a> ';
		print '<a class="butActionSmall paddingright" href="'.$_SERVER["PHP_SELF"].'?id='.$p->rowid.'&action=fu_snooze&days=7&backtopage='.urlencode($back).'&token='.newToken().'">'.$langs->trans("FollowupPlanSnooze").'</a> ';
		print '<a class="butActionSmall" href="'.$_SERVER["PHP_SELF"].'?id='.$p->rowid.'&action=fu_cancel&backtopage='.urlencode($back).'&token='.newToken().'">'.$langs->trans("FollowupPlanCancel").'</a>';
	}
	print '</td></tr>';
}
print '</table></div>';
