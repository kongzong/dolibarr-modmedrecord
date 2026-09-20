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
 * \file    htdocs/custom/medrecord/admin/setup.php
 * \ingroup medrecord
 * \brief   Module setup: retention / sign-lock constants (phase 1),
 *          dictionary CSV import (phase 3).
 */

$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res && file_exists("../../../../main.inc.php")) {
	$res = @include "../../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
dol_include_once('/medrecord/lib/medrecord.lib.php');
dol_include_once('/medrecord/core/modules/modMedRecord.class.php');
dol_include_once('/medrecord/class/medrecorddictimport.class.php');

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array("admin", "errors", "medrecord@medrecord"));

if (!$user->admin && !$user->hasRight('medrecord', 'admin')) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$module = new modMedRecord($db);
$form = new Form($db);
$importResult = null;

if ($action == 'save') {
	$retention = GETPOSTINT('MEDRECORD_RETENTION_YEARS');
	$lock = GETPOSTINT('MEDRECORD_SIGN_LOCK_HOURS');
	$ok = true;
	if ($retention < 1) {
		$retention = 1;
	}
	if ($lock < 0) {
		$lock = 0;
	}
	$ok = $ok && dolibarr_set_const($db, 'MEDRECORD_RETENTION_YEARS', (string) $retention, 'chaine', 0, '', $conf->entity) > 0;
	$ok = $ok && dolibarr_set_const($db, 'MEDRECORD_SIGN_LOCK_HOURS', (string) $lock, 'chaine', 0, '', $conf->entity) > 0;
	setEventMessages($langs->trans($ok ? "SetupSaved" : "Error"), null, $ok ? 'mesgs' : 'errors');
	$action = '';
}

// Dictionary CSV import (spec §3.1): idempotent upsert on code
if ($action == 'importdict') {
	$type = GETPOST('dict_type', 'aZ09');
	if (!isset(MedRecordDictImport::TABLES[$type])) {
		setEventMessages($langs->trans("MedRecordImportBadType"), null, 'errors');
	} elseif (empty($_FILES['dictfile']['tmp_name']) || !is_uploaded_file($_FILES['dictfile']['tmp_name'])) {
		setEventMessages($langs->trans("ErrorFieldRequired", $langs->transnoentitiesnoconv("File")), null, 'errors');
	} elseif ($_FILES['dictfile']['size'] > 20 * 1024 * 1024) {
		setEventMessages($langs->trans("ErrorFileSizeTooLarge"), null, 'errors');
	} else {
		$importer = new MedRecordDictImport($db);
		$importResult = $importer->importFile($_FILES['dictfile']['tmp_name'], $type);
		if ($importResult === null) {
			setEventMessages($langs->trans("MedRecordImportFailed").': '.$langs->trans($importer->error), null, 'errors');
		} else {
			setEventMessages($langs->trans("MedRecordImportOk", $importResult['read'], $importResult['inserted'], $importResult['updated'], $importResult['skipped']), null, 'mesgs');
		}
	}
	$action = '';
}

llxHeader('', $langs->trans("MedRecordSetup"));

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans("BackToModuleList").'</a>';
print load_fiche_titre($langs->trans("MedRecordSetup"), $linkback, 'title_setup');

$head = medrecord_admin_prepare_head();
print dol_get_fiche_head($head, 'settings', $langs->trans("ModuleMedRecordName"), -1, 'fa-notes-medical');

print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="save">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans("Parameter").'</td><td>'.$langs->trans("Value").'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans("MedRecordRetentionYears").'</td>';
print '<td><input type="number" min="1" name="MEDRECORD_RETENTION_YEARS" class="maxwidth75" value="'.getDolGlobalInt('MEDRECORD_RETENTION_YEARS', 15).'"></td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans("MedRecordSignLockHours").'</td>';
print '<td><input type="number" min="0" name="MEDRECORD_SIGN_LOCK_HOURS" class="maxwidth75" value="'.getDolGlobalInt('MEDRECORD_SIGN_LOCK_HOURS', 0).'"></td></tr>';
print '</table>';
print '<div class="center"><input type="submit" class="button button-save" value="'.$langs->trans("Save").'"></div>';
print '</form>';

print '<br><p class="opacitymedium">'.$langs->trans("ModuleMedRecordName").' '.$module->version.' &middot; ';
print '<a href="'.DOL_URL_ROOT.'/admin/dict.php">'.$langs->trans("Dictionaries").'</a>: ';
print $langs->trans("MedRecordDictIcd10").' / '.$langs->trans("MedRecordDictTcmDisease").' / '.$langs->trans("MedRecordDictTcmSyndrome").'</p>';

// Import
$counts = array();
foreach (MedRecordDictImport::TABLES as $t => $table) {
	$resql = $db->query("SELECT COUNT(*) as c, SUM(active) as a FROM ".$db->prefix().$table);
	$o = $resql ? $db->fetch_object($resql) : null;
	$counts[$t] = $o ? array('total' => (int) $o->c, 'active' => (int) $o->a) : array('total' => 0, 'active' => 0);
}
print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'" enctype="multipart/form-data">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="importdict">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="2">'.$langs->trans("MedRecordImportTitle").'</td></tr>';
print '<tr class="oddeven"><td class="titlefield">'.$langs->trans("Dictionary").'</td><td>';
$typeOptions = array(
	'icd10' => $langs->trans("MedRecordDictIcd10").' ('.$counts['icd10']['active'].'/'.$counts['icd10']['total'].')',
	'tcm_disease' => $langs->trans("MedRecordDictTcmDisease").' ('.$counts['tcm_disease']['active'].'/'.$counts['tcm_disease']['total'].')',
	'tcm_syndrome' => $langs->trans("MedRecordDictTcmSyndrome").' ('.$counts['tcm_syndrome']['active'].'/'.$counts['tcm_syndrome']['total'].')',
);
print $form->selectarray('dict_type', $typeOptions, GETPOST('dict_type', 'aZ09') ?: 'icd10', 0, 0, 0, '', 0, 0, 0, '', 'minwidth300');
print '</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans("File").'</td><td><input type="file" name="dictfile" accept=".csv,.txt"></td></tr>';
print '<tr class="oddeven"><td></td><td class="opacitymedium">'.$langs->trans("MedRecordImportHelp").'</td></tr>';
print '</table>';
print '<div class="center"><input type="submit" class="button" value="'.$langs->trans("Import").'"></div>';
print '</form>';

print dol_get_fiche_end();

llxFooter();
$db->close();
