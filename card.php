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
 * \file    htdocs/custom/medrecord/card.php
 * \ingroup medrecord
 * \brief   Record card: create / view / edit / sign / void / follow-up.
 *          Opening a record writes MEDRECORD_READ (spec §3.3).
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
dol_include_once('/prescription/lib/prescription.lib.php');
dol_include_once('/pharmacy/lib/pharmacy.lib.php');
dol_include_once('/clinicpay/lib/clinicpay.lib.php');

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var HookManager $hookmanager
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array("patient@patient", "medrecord@medrecord"));

$id = GETPOSTINT('id');
$action = GETPOST('action', 'aZ09');
$confirm = GETPOST('confirm', 'alpha');
$fkPatientParam = GETPOSTINT('fk_patient');
$refFrom = GETPOSTINT('ref_from');

$canRead = $user->hasRight('medrecord', 'read');
$canWrite = $user->hasRight('medrecord', 'write');
if (!$canRead) {
	accessforbidden();
}

$form = new Form($db);
$object = new MedicalRecord($db);
$hookmanager->initHooks(array('medrecordcard'));

if ($id > 0) {
	$r = $object->fetch($id);
	if ($r < 0) {
		dol_print_error($db, $object->error);
		exit;
	}
	if ($r == 0) {
		accessforbidden($langs->trans("ErrorRecordNotFound"));
	}
}

$doctors = patient_doctor_options($db);
$departments = patient_dict_rows($db, 'c_patient_department');
$tcmDiseases = medrecord_dict_options($db, 'c_medrecord_tcm_disease');
$tcmSyndromes = medrecord_dict_options($db, 'c_medrecord_tcm_syndrome');

/**
 * Read the shared form fields into the object.
 *
 * @param	MedicalRecord	$o	Target
 * @return	void
 */
function medrecord_read_form(MedicalRecord $o)
{
	$o->fk_doctor = GETPOSTINT('fk_doctor');
	$o->fk_department = GETPOSTINT('fk_department');
	$o->visit_type = GETPOSTINT('visit_type');
	$o->visit_date = dol_mktime(GETPOSTINT('visit_datehour'), GETPOSTINT('visit_datemin'), 0, GETPOSTINT('visit_datemonth'), GETPOSTINT('visit_dateday'), GETPOSTINT('visit_dateyear'));
	foreach (array('chief_complaint', 'present_illness', 'past_history_snapshot', 'exam_note', 'treatment_principle', 'advice') as $f) {
		$o->$f = GETPOST($f, 'restricthtml');
	}
	$o->tongue = GETPOST('tongue', 'alphanohtml');
	$o->pulse = GETPOST('pulse', 'alphanohtml');
	$o->tcm_disease_code = GETPOST('tcm_disease_code', 'alphanohtml');
	$o->tcm_syndrome_code = GETPOST('tcm_syndrome_code', 'alphanohtml');
	// Western diagnoses: parallel arrays from the chip editor
	$codes = GETPOST('wm_code', 'array');
	$labels = GETPOST('wm_label', 'array');
	$primary = GETPOST('wm_primary', 'alphanohtml');
	$o->diagnoses = array();
	foreach ((array) $codes as $i => $code) {
		$code = trim((string) $code);
		if ($code === '') {
			continue;
		}
		$o->diagnoses[] = array('code' => $code, 'label' => isset($labels[$i]) ? trim((string) $labels[$i]) : '', 'is_primary' => ($primary !== '' && $primary === $code) ? 1 : 0, 'diag_type' => 'WM');
	}
}


/*
 * Actions
 */

if ($action == 'add' && $canWrite) {
	if (GETPOST('cancel', 'alpha')) {
		header("Location: ".dol_buildpath('/medrecord/list.php', 1));
		exit;
	}
	$object = new MedicalRecord($db);
	$object->fk_patient = $fkPatientParam;
	$object->fk_ref_medrecord = $refFrom > 0 ? $refFrom : null;
	medrecord_read_form($object);
	$result = $object->create($user);
	if ($result > 0) {
		setEventMessages($langs->trans("MedRecordCreated", $object->ref), null, 'mesgs');
		header("Location: ".$_SERVER["PHP_SELF"]."?id=".$object->id);
		exit;
	}
	setEventMessages($langs->trans($object->error), $object->errors, 'errors');
	$action = 'create';
}

if ($action == 'update' && $object->id > 0) {
	if (GETPOST('cancel', 'alpha')) {
		header("Location: ".$_SERVER["PHP_SELF"]."?id=".$object->id);
		exit;
	}
	if (!$object->canEdit($user)) {
		accessforbidden($langs->trans("MedRecordErrNotEditable"));
	}
	medrecord_read_form($object);
	$result = $object->update($user);
	if ($result > 0) {
		setEventMessages($langs->trans("RecordSaved"), null, 'mesgs');
		header("Location: ".$_SERVER["PHP_SELF"]."?id=".$object->id);
		exit;
	}
	setEventMessages($langs->trans($object->error), $object->errors, 'errors');
	$action = 'edit';
}

if ($action == 'confirm_sign' && $confirm == 'yes' && $object->id > 0) {
	$result = $object->sign($user);
	setEventMessages($langs->trans($result > 0 ? "MedRecordSigned" : $object->error), null, $result > 0 ? 'mesgs' : 'errors');
	header("Location: ".$_SERVER["PHP_SELF"]."?id=".$object->id);
	exit;
}

if ($action == 'confirm_void' && $confirm == 'yes' && $object->id > 0) {
	$result = $object->void($user, GETPOST('void_reason', 'alphanohtml'));
	setEventMessages($langs->trans($result > 0 ? "MedRecordVoided" : $object->error), null, $result > 0 ? 'mesgs' : 'errors');
	header("Location: ".$_SERVER["PHP_SELF"]."?id=".$object->id);
	exit;
}

// Prepare a follow-up draft prefilled from this record (not saved until 'add')
$prefill = null;
if ($action == 'followup' && $object->id > 0 && $canWrite) {
	$prefill = $object->newFollowUp();
	$fkPatientParam = $object->fk_patient;
	$refFrom = $object->id;
	$action = 'create';
}

// Reading a record is a medical-data read
if ($object->id > 0 && $action != 'create') {
	patient_audit($db, $object->fk_patient, 'MEDRECORD_READ', $user, array('ref' => $object->ref, 'record' => $object->id));
}


/*
 * View
 */

$title = $action == 'create' ? $langs->trans("MedRecordNew") : ($object->ref ? $object->ref : $langs->trans("MedRecordTab"));
llxHeader('', $title);

/**
 * Editable rows shared by create/edit.
 *
 * @param	MedicalRecord	$o			Object
 * @param	bool		$isCreate	Create mode
 * @return	void
 */
function medrecord_print_form_rows(MedicalRecord $o, $isCreate)
{
	global $langs, $form, $user, $doctors, $departments, $tcmDiseases, $tcmSyndromes;

	$v = function ($field) use ($o) {
		return GETPOSTISSET($field) ? GETPOST($field, 'restricthtml') : (string) $o->$field;
	};

	print '<tr><td class="titlefieldcreate fieldrequired">'.$langs->trans("MedRecordDoctor").'</td><td>';
	$selDoctor = GETPOSTISSET('fk_doctor') ? GETPOSTINT('fk_doctor') : ($o->fk_doctor ? $o->fk_doctor : (isset($doctors[$user->id]) ? $user->id : 0));
	print $form->selectarray('fk_doctor', $doctors, $selDoctor, 1, 0, 0, '', 0, 0, 0, '', 'minwidth200');
	print '</td></tr>';
	print '<tr><td>'.$langs->trans("MedRecordDepartment").'</td><td>';
	print $form->selectarray('fk_department', $departments, GETPOSTISSET('fk_department') ? GETPOSTINT('fk_department') : (int) $o->fk_department, 1, 0, 0, '', 0, 0, 0, '', 'minwidth200');
	print '</td></tr>';
	print '<tr><td>'.$langs->trans("MedRecordVisitDate").'</td><td>';
	$ts = GETPOSTISSET('visit_dateyear') ? dol_mktime(GETPOSTINT('visit_datehour'), GETPOSTINT('visit_datemin'), 0, GETPOSTINT('visit_datemonth'), GETPOSTINT('visit_dateday'), GETPOSTINT('visit_dateyear')) : ($o->visit_date ? $o->visit_date : dol_now());
	print $form->selectDate($ts, 'visit_date', 1, 1, 0, 'formmedrecord', 1, 1);
	print '</td></tr>';
	print '<tr><td>'.$langs->trans("MedRecordVisitType").'</td><td>';
	print $form->selectarray('visit_type', array(1 => $langs->trans("MedRecordVisitFirst"), 2 => $langs->trans("MedRecordVisitFollow")), GETPOSTISSET('visit_type') ? GETPOSTINT('visit_type') : (int) $o->visit_type, 0, 0, 0, '', 0, 0, 0, '', 'minwidth100');
	print '</td></tr>';

	print '<tr><td class="tdtop fieldrequired">'.$langs->trans("MedRecordChiefComplaint").'</td><td><textarea name="chief_complaint" class="quatrevingtpercent" rows="2">'.dol_escape_htmltag($v('chief_complaint')).'</textarea></td></tr>';
	print '<tr><td class="tdtop">'.$langs->trans("MedRecordPresentIllness").'</td><td><textarea name="present_illness" class="quatrevingtpercent" rows="4">'.dol_escape_htmltag($v('present_illness')).'</textarea></td></tr>';
	print '<tr><td class="tdtop">'.$langs->trans("MedRecordPastHistory").'</td><td><textarea name="past_history_snapshot" class="quatrevingtpercent" rows="2">'.dol_escape_htmltag($v('past_history_snapshot')).'</textarea>';
	if ($isCreate) {
		print '<br><span class="opacitymedium">'.$langs->trans("MedRecordPastHistoryHint").'</span>';
	}
	print '</td></tr>';

	print '<tr class="liste_titre"><td colspan="2">'.$langs->trans("MedRecordFourDiag").'</td></tr>';
	print '<tr><td>'.$langs->trans("MedRecordTongue").'</td><td><input type="text" name="tongue" class="quatrevingtpercent" maxlength="255" value="'.dol_escape_htmltag(GETPOSTISSET('tongue') ? GETPOST('tongue', 'alphanohtml') : (string) $o->tongue).'"></td></tr>';
	print '<tr><td>'.$langs->trans("MedRecordPulse").'</td><td><input type="text" name="pulse" class="quatrevingtpercent" maxlength="255" value="'.dol_escape_htmltag(GETPOSTISSET('pulse') ? GETPOST('pulse', 'alphanohtml') : (string) $o->pulse).'"></td></tr>';
	print '<tr><td class="tdtop">'.$langs->trans("MedRecordExam").'</td><td><textarea name="exam_note" class="quatrevingtpercent" rows="2">'.dol_escape_htmltag($v('exam_note')).'</textarea></td></tr>';

	print '<tr class="liste_titre"><td colspan="2">'.$langs->trans("MedRecordDiagnosis").'</td></tr>';
	// Western diagnoses: chip editor fed by ajax/dict.php (jQuery UI autocomplete is loaded by main.inc.php)
	print '<tr><td class="tdtop">'.$langs->trans("MedRecordDiagWm").'</td><td>';
	print '<input type="text" id="wm_search" class="minwidth300" placeholder="'.dol_escape_htmltag($langs->trans("MedRecordDiagSearchHint")).'" autocomplete="off">';
	print '<div id="wm_chips" style="margin-top:6px;"></div>';
	$diags = array();
	if (GETPOSTISSET('wm_code')) {
		$codes = GETPOST('wm_code', 'array');
		$labels = GETPOST('wm_label', 'array');
		$primary = GETPOST('wm_primary', 'alphanohtml');
		foreach ((array) $codes as $i => $c) {
			$diags[] = array('code' => $c, 'label' => isset($labels[$i]) ? $labels[$i] : '', 'is_primary' => ($primary === $c) ? 1 : 0);
		}
	} else {
		$diags = $o->diagnoses;
	}
	print '<span class="opacitymedium">'.$langs->trans("MedRecordDiagPrimaryHint").'</span>';
	print '<script>
$(function() {
	var chips = $("#wm_chips");
	function render(list) {
		chips.empty();
		$.each(list, function(i, d) {
			var chip = $("<span class=\"badge badge-status0\" style=\"margin:2px;display:inline-block;\"></span>");
			chip.append($("<input type=\"radio\" name=\"wm_primary\" title=\"'.dol_escape_js($langs->trans("MedRecordDiagPrimary")).'\">").val(d.code).prop("checked", !!d.is_primary));
			chip.append(" " + $("<span></span>").text(d.code + " " + d.label).html() + " ");
			chip.append($("<input type=\"hidden\" name=\"wm_code[]\">").val(d.code));
			chip.append($("<input type=\"hidden\" name=\"wm_label[]\">").val(d.label));
			chip.append($("<a href=\"#\" title=\"'.dol_escape_js($langs->trans("Remove")).'\">&times;</a>").on("click", function(e) { e.preventDefault(); list.splice(i, 1); if (list.length && !list.some(function(x){return x.is_primary;})) { list[0].is_primary = 1; } render(list); }));
			chips.append(chip);
		});
	}
	var list = '.json_encode(array_values(array_map(function ($d) { return array('code' => $d['code'], 'label' => $d['label'], 'is_primary' => (int) $d['is_primary']); }, $diags)), JSON_UNESCAPED_UNICODE).';
	render(list);
	chips.on("change", "input[name=wm_primary]", function() { var c = $(this).val(); $.each(list, function(i, d) { d.is_primary = (d.code === c) ? 1 : 0; }); });
	$("#wm_search").autocomplete({
		minLength: 1,
		source: function(request, response) {
			$.getJSON("'.dol_buildpath('/medrecord/ajax/dict.php', 1).'", { table: "icd10", term: request.term }, function(data) { response(data); });
		},
		select: function(event, ui) {
			if (!list.some(function(d){ return d.code === ui.item.code; })) {
				list.push({ code: ui.item.code, label: ui.item.name, is_primary: list.length ? 0 : 1 });
				render(list);
			}
			$(this).val("");
			return false;
		}
	});
});
</script>';
	print '</td></tr>';
	print '<tr><td>'.$langs->trans("MedRecordDiagTcm").'</td><td>';
	print $form->selectarray('tcm_disease_code', $tcmDiseases, GETPOSTISSET('tcm_disease_code') ? GETPOST('tcm_disease_code', 'alphanohtml') : (string) $o->tcm_disease_code, 1, 0, 0, '', 0, 0, 0, '', 'minwidth200', 1);
	print '</td></tr>';
	print '<tr><td>'.$langs->trans("MedRecordSyndrome").'</td><td>';
	print $form->selectarray('tcm_syndrome_code', $tcmSyndromes, GETPOSTISSET('tcm_syndrome_code') ? GETPOST('tcm_syndrome_code', 'alphanohtml') : (string) $o->tcm_syndrome_code, 1, 0, 0, '', 0, 0, 0, '', 'minwidth200', 1);
	print '</td></tr>';

	print '<tr class="liste_titre"><td colspan="2">'.$langs->trans("MedRecordPlan").'</td></tr>';
	print '<tr><td class="tdtop">'.$langs->trans("MedRecordTreatment").'</td><td><textarea name="treatment_principle" class="quatrevingtpercent" rows="2">'.dol_escape_htmltag($v('treatment_principle')).'</textarea></td></tr>';
	print '<tr><td class="tdtop">'.$langs->trans("MedRecordAdvice").'</td><td><textarea name="advice" class="quatrevingtpercent" rows="3">'.dol_escape_htmltag($v('advice')).'</textarea></td></tr>';
}

// ---------------------------------------------------------------- create
if ($action == 'create') {
	if (!$canWrite) {
		accessforbidden();
	}
	$draft = $prefill ? $prefill : new MedicalRecord($db);
	if ($fkPatientParam > 0) {
		$draft->fk_patient = $fkPatientParam;
	}
	print load_fiche_titre($langs->trans("MedRecordNew"), '', 'fa-notes-medical');

	print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'" name="formmedrecord">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="add">';
	if ($refFrom > 0) {
		print '<input type="hidden" name="ref_from" value="'.$refFrom.'">';
	}
	print dol_get_fiche_head(array(), '', '', -1);
	print '<table class="border centpercent tableforfieldcreate">';
	print '<tr><td class="titlefieldcreate fieldrequired">'.$langs->trans("MedRecordPatient").'</td><td>';
	if ($draft->fk_patient > 0) {
		print '<input type="hidden" name="fk_patient" value="'.((int) $draft->fk_patient).'">';
		print patient_summary_banner(patient_get_summary($db, $draft->fk_patient), array(array('label' => $langs->trans("MedRecordTab"), 'url' => dol_buildpath('/medrecord/patient_tab.php', 1).'?id='.((int) $draft->fk_patient)), array('label' => $langs->trans("MedRecordNew"))), 'medrecord');
	} else {
		print patient_select_html($db, 'fk_patient', GETPOSTINT('fk_patient'));
	}
	print '</td></tr>';
	if ($refFrom > 0) {
		print '<tr><td>'.$langs->trans("MedRecordFollowFrom").'</td><td>'.$object->getNomUrl(1).'</td></tr>';
	}
	medrecord_print_form_rows($draft, true);
	print '</table>';
	print dol_get_fiche_end();
	print $form->buttonsSaveCancel("Create");
	print '</form>';
} elseif ($object->id > 0) {
	$head = medrecord_prepare_head($object);
	print dol_get_fiche_head($head, 'card', $langs->trans("MedRecordTab"), -1, 'fa-notes-medical');

	$summary = patient_get_summary($db, $object->fk_patient);
	$linkback = '<a href="'.dol_buildpath('/medrecord/list.php', 1).'?restore_lastsearch_values=1">'.$langs->trans("BackToList").'</a>';
	print '<div class="arearef heightref valignmiddle centpercent">';
	print '<div class="inline-block floatleft refid refidpadding">'.img_picto('', 'fa-notes-medical', 'class="pictofixedwidth"').'<strong>'.dol_escape_htmltag($object->ref).'</strong> '.$object->getLibStatut().'</div>';
	print '<div class="inline-block floatright">'.$linkback.'</div>';
	print '<div class="clearboth"></div></div>';
	print '<div class="underbanner clearboth"></div>';
	print patient_summary_banner($summary, array(array('label' => $langs->trans("MedRecordTab"), 'url' => dol_buildpath('/medrecord/patient_tab.php', 1).'?id='.((int) $object->fk_patient)), array('label' => $object->ref)), 'medrecord');

	if ($action == 'edit' && $object->canEdit($user)) {
		print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'?id='.$object->id.'" name="formmedrecord">';
		print '<input type="hidden" name="token" value="'.newToken().'">';
		print '<input type="hidden" name="action" value="update">';
		print '<input type="hidden" name="id" value="'.$object->id.'">';
		print '<table class="border centpercent tableforfieldedit">';
		medrecord_print_form_rows($object, false);
		print '</table>';
		print $form->buttonsSaveCancel();
		print '</form>';
	} else {
		if ($action == 'sign' && $object->canSign($user)) {
			print $form->formconfirm($_SERVER["PHP_SELF"].'?id='.$object->id, $langs->trans("MedRecordSign"), $langs->trans("MedRecordConfirmSign"), 'confirm_sign', '', 0, 1);
		}
		if ($action == 'void' && $object->canVoid($user)) {
			$q = array(array('type' => 'text', 'name' => 'void_reason', 'label' => $langs->trans("MedRecordVoidReason"), 'value' => '', 'size' => 60));
			print $form->formconfirm($_SERVER["PHP_SELF"].'?id='.$object->id, $langs->trans("MedRecordVoid"), $langs->trans("MedRecordConfirmVoid"), 'confirm_void', $q, 0, 1);
		}

		print '<div class="fichecenter"><div class="fichehalfleft"><table class="border tableforfield centpercent">';
		print '<tr><td class="titlefield">'.$langs->trans("MedRecordDoctor").'</td><td>'.dol_escape_htmltag(isset($doctors[$object->fk_doctor]) ? $doctors[$object->fk_doctor] : '#'.$object->fk_doctor).'</td></tr>';
		print '<tr><td>'.$langs->trans("MedRecordDepartment").'</td><td>'.dol_escape_htmltag(isset($departments[$object->fk_department]) ? $departments[$object->fk_department] : '').'</td></tr>';
		print '<tr><td>'.$langs->trans("MedRecordVisitDate").'</td><td>'.dol_print_date($object->visit_date, 'dayhour').'</td></tr>';
		print '<tr><td>'.$langs->trans("MedRecordVisitType").'</td><td>'.$langs->trans($object->visit_type == 2 ? "MedRecordVisitFollow" : "MedRecordVisitFirst");
		if ($object->fk_ref_medrecord) {
			$refRec = new MedicalRecord($db);
			if ($refRec->fetch($object->fk_ref_medrecord) > 0) {
				print ' <span class="opacitymedium">('.$langs->trans("MedRecordFollowFrom").' '.$refRec->getNomUrl(0).')</span>';
			}
		}
		print '</td></tr>';
		print '<tr><td class="tdtop">'.$langs->trans("MedRecordChiefComplaint").'</td><td>'.dol_nl2br(dol_escape_htmltag((string) $object->chief_complaint)).'</td></tr>';
		print '<tr><td class="tdtop">'.$langs->trans("MedRecordPresentIllness").'</td><td>'.dol_nl2br(dol_escape_htmltag((string) $object->present_illness)).'</td></tr>';
		print '<tr><td class="tdtop">'.$langs->trans("MedRecordPastHistory").'</td><td>'.dol_nl2br(dol_escape_htmltag((string) $object->past_history_snapshot)).'</td></tr>';
		print '<tr><td>'.$langs->trans("MedRecordTongue").'</td><td>'.dol_escape_htmltag((string) $object->tongue).'</td></tr>';
		print '<tr><td>'.$langs->trans("MedRecordPulse").'</td><td>'.dol_escape_htmltag((string) $object->pulse).'</td></tr>';
		print '<tr><td class="tdtop">'.$langs->trans("MedRecordExam").'</td><td>'.dol_nl2br(dol_escape_htmltag((string) $object->exam_note)).'</td></tr>';
		print '</table></div>';

		print '<div class="fichehalfright"><table class="border tableforfield centpercent">';
		print '<tr><td class="titlefield tdtop">'.$langs->trans("MedRecordDiagWm").'</td><td>';
		foreach ($object->diagnoses as $d) {
			print '<span class="badge '.($d['is_primary'] ? 'badge-status4' : 'badge-status0').'" style="margin:2px;">'.dol_escape_htmltag($d['code'].' '.$d['label']).'</span> ';
		}
		print '</td></tr>';
		print '<tr><td>'.$langs->trans("MedRecordDiagTcm").'</td><td>'.dol_escape_htmltag((string) $object->tcm_disease_label).'</td></tr>';
		print '<tr><td>'.$langs->trans("MedRecordSyndrome").'</td><td>'.dol_escape_htmltag((string) $object->tcm_syndrome_label).'</td></tr>';
		print '<tr><td class="tdtop">'.$langs->trans("MedRecordTreatment").'</td><td>'.dol_nl2br(dol_escape_htmltag((string) $object->treatment_principle)).'</td></tr>';
		print '<tr><td class="tdtop">'.$langs->trans("MedRecordAdvice").'</td><td>'.dol_nl2br(dol_escape_htmltag((string) $object->advice)).'</td></tr>';
		if ($object->status == MEDRECORD_STATUS_SIGNED || $object->status == MEDRECORD_STATUS_VOIDED) {
			print '<tr><td>'.$langs->trans("MedRecordSignedBy").'</td><td>'.($object->fk_user_sign ? dol_escape_htmltag(isset($doctors[$object->fk_user_sign]) ? $doctors[$object->fk_user_sign] : '#'.$object->fk_user_sign).' '.dol_print_date($object->date_signed, 'dayhour') : '').'</td></tr>';
		}
		if ($object->status == MEDRECORD_STATUS_VOIDED) {
			print '<tr><td>'.$langs->trans("MedRecordVoidReason").'</td><td class="error">'.dol_escape_htmltag((string) $object->void_reason).' <span class="opacitymedium">('.dol_print_date($object->date_void, 'dayhour').')</span></td></tr>';
		}
		print '<tr><td>'.$langs->trans("DateCreation").'</td><td>'.dol_print_date($object->date_creation, 'dayhour').'</td></tr>';
		print '</table>';
		print '</div></div><div class="clearboth"></div>';

		// Extension point for modPrescription and later modules (spec §3.5).
		// Printed FULL WIDTH below the two half columns - inside fichehalfright
		// the prescription table gets squeezed to half page width.
		$parameters = array('object' => $object);
		$reshook = $hookmanager->executeHooks('printMedRecordCard', $parameters, $object, $action);
		if ($reshook < 0) {
			setEventMessages($hookmanager->error, $hookmanager->errors, 'errors');
		}
		print $hookmanager->resPrint;
		if (trim((string) $hookmanager->resPrint) === '') {
			print '<div class="opacitymedium" style="margin-top:8px;">'.$langs->trans("MedRecordNoPrescriptionModule").'</div>';
		}

		// Patient timeline
		$timeline = medrecord_timeline($db, $object->fk_patient, 10);
		if (count($timeline) > 1) {
			print '<br><div class="div-table-responsive-no-min"><table class="noborder centpercent">';
			print '<tr class="liste_titre"><th colspan="4">'.$langs->trans("MedRecordTimeline").'</th></tr>';
			foreach ($timeline as $t) {
				if ((int) $t->rowid === (int) $object->id) {
					continue;
				}
				print '<tr class="oddeven"><td><a href="'.$_SERVER["PHP_SELF"].'?id='.$t->rowid.'">'.dol_escape_htmltag($t->ref).'</a></td>';
				print '<td>'.dol_print_date($db->jdate($t->visit_date), 'day').'</td>';
				print '<td>'.dol_escape_htmltag(trim(($t->wm_label ? $t->wm_label.' ' : '').$t->tcm_disease_label.' '.$t->tcm_syndrome_label)).'</td>';
				print '<td class="center">'.medrecord_status_badge($t->status).'</td></tr>';
			}
			print '</table></div>';
		}

		// ---- 本次诊疗关联 (visit thread): 收费 / 本次扣卡 (design §5.3, option A:
		// prescriptions + dispenses are shown by the prescription hook table above) ----
		$visitBills = clinicpay_bill_list_by_medrecord($db, $object->id);
		$billIds = array();
		foreach ($visitBills as $vb) {
			$billIds[] = (int) $vb->rowid;
		}
		$visitConsume = array();
		if (count($billIds) > 0) {
			$sqlC = "SELECT l.rowid, l.op, l.value_delta, l.fk_bill, l.date_creation, c.ref as card_ref";
			$sqlC .= " FROM ".$db->prefix()."clinicpay_card_log as l";
			$sqlC .= " LEFT JOIN ".$db->prefix()."clinicpay_card as c ON c.rowid = l.fk_card";
			$sqlC .= " WHERE l.fk_bill IN (".implode(',', $billIds).") AND l.op = 'CONSUME'";
			$sqlC .= $db->order('l.rowid', 'DESC');
			$resC = $db->query($sqlC);
			if ($resC) {
				while ($lc = $db->fetch_object($resC)) {
					$visitConsume[] = $lc;
				}
				$db->free($resC);
			}
		}

		print '<br><div class="ficheaddleft">';
		print load_fiche_titre($langs->trans("MedRecordVisitThread"), '', 'fa-link');
		// 收费
		print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
		print '<tr class="liste_titre"><th colspan="4">'.$langs->trans("MedRecordVisitThreadBill").'</th></tr>';
		if (empty($visitBills)) {
			print '<tr><td colspan="4" class="opacitymedium">'.$langs->trans("MedRecordVisitThreadNone").'</td></tr>';
		}
		foreach ($visitBills as $vb) {
			print '<tr class="oddeven">';
			print '<td><a href="'.dol_buildpath('/clinicpay/bill.php', 1).'?id='.((int) $vb->rowid).'">'.dol_escape_htmltag($vb->ref).'</a></td>';
			print '<td class="right">'.price($vb->amount_total).'</td>';
			print '<td>'.dol_print_date($db->jdate($vb->date_creation), 'dayhour').'</td>';
			print '<td>'.dol_escape_htmltag((string) $vb->channel).'</td>';
			print '</tr>';
		}
		print '</table></div>';

		// 本次扣卡
		print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
		print '<tr class="liste_titre"><th colspan="3">'.$langs->trans("MedRecordVisitThreadCardConsume").'</th></tr>';
		if (empty($visitConsume)) {
			print '<tr><td colspan="3" class="opacitymedium">'.$langs->trans("MedRecordVisitThreadNone").'</td></tr>';
		}
		foreach ($visitConsume as $vc) {
			print '<tr class="oddeven">';
			print '<td>'.dol_escape_htmltag((string) $vc->card_ref).'</td>';
			print '<td class="right">'.price($vc->value_delta).'</td>';
			print '<td>'.dol_print_date($db->jdate($vc->date_creation), 'dayhour').'</td>';
			print '</tr>';
		}
		print '</table></div>';

		print '</div>';

		print dol_get_fiche_end();

		print '<div class="tabsAction">';
		if ($object->canEdit($user)) {
			print dolGetButtonAction($langs->trans("Modify"), '', 'default', $_SERVER["PHP_SELF"].'?id='.$object->id.'&action=edit&token='.newToken(), '', 1);
		}
		if ($object->canSign($user)) {
			print dolGetButtonAction($langs->trans("MedRecordSign"), '', 'default', $_SERVER["PHP_SELF"].'?id='.$object->id.'&action=sign&token='.newToken(), '', 1);
		}
		if ($canWrite && $object->status == MEDRECORD_STATUS_SIGNED) {
			print dolGetButtonAction($langs->trans("MedRecordFollowUp"), '', 'default', $_SERVER["PHP_SELF"].'?id='.$object->id.'&action=followup&token='.newToken(), '', 1);
		}
		if ($object->canVoid($user)) {
			print dolGetButtonAction($langs->trans("MedRecordVoid"), '', 'delete', $_SERVER["PHP_SELF"].'?id='.$object->id.'&action=void&token='.newToken(), '', 1);
		}
		print dolGetButtonAction($langs->trans("MedRecordPrint"), '', 'default', dol_buildpath('/medrecord/print.php', 1).'?id='.$object->id, '', 1, array('attr' => array('target' => '_blank')));
		print '</div>';
	}
} else {
	print load_fiche_titre($langs->trans("MedRecordTab"), '', 'fa-notes-medical');
	print '<div class="opacitymedium">'.$langs->trans("ErrorRecordNotFound").'</div>';
}

llxFooter();
$db->close();
