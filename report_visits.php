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
 * \file    htdocs/custom/medrecord/report_visits.php
 * \ingroup medrecord
 * \brief   Visit and follow-up report: per patient the number of visits,
 *          first / last visit date, attending doctor, the split between
 *          first visit (初诊) and follow-up (复诊), and the gap analysis
 *          (average / min / max days between two consecutive visits, only
 *          for patients with at least 2 visits). Filters: date range on
 *          llx_medrecord.visit_date. Read only.
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
dol_include_once('/medrecord/lib/medrecord.lib.php');

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array("medrecord@medrecord", "patient@patient"));

if (!$user->hasRight('medrecord', 'read')) {
	accessforbidden();
}

// ---- Filters ------------------------------------------------------------------
$dateFrom = dol_mktime(0, 0, 0, GETPOSTINT('search_frommonth'), GETPOSTINT('search_fromday'), GETPOSTINT('search_fromyear'));
$dateTo = dol_mktime(23, 59, 59, GETPOSTINT('search_tomonth'), GETPOSTINT('search_today'), GETPOSTINT('search_toyear'));
$searchFkPatient = GETPOSTINT('search_fk_patient');
$searchVisitType = GETPOSTINT('search_visit_type');
if (GETPOST('button_removefilter_x', 'alpha') || GETPOST('button_removefilter', 'alpha')) {
	$dateFrom = '';
	$dateTo = '';
	$searchFkPatient = 0;
	$searchVisitType = 0;
}

$action = GETPOST('action', 'aZ09');

// ---- Where fragment -----------------------------------------------------------
$sqlWhere = " WHERE m.entity = ".((int) $conf->entity);
if ($dateFrom) {
	$sqlWhere .= " AND m.visit_date >= '".$db->idate($dateFrom)."'";
}
if ($dateTo) {
	$sqlWhere .= " AND m.visit_date <= '".$db->idate($dateTo)."'";
}
if ($searchFkPatient > 0) {
	$sqlWhere .= " AND m.fk_patient = ".((int) $searchFkPatient);
}
if ($searchVisitType > 0) {
	$sqlWhere .= " AND m.visit_type = ".((int) $searchVisitType);
}

// ---- Per-patient aggregate ----------------------------------------------------
$sql = "SELECT m.fk_patient";
$sql .= ", pp.card_no, s.nom AS patient_name";
$sql .= ", COUNT(*) AS nb_visit";
$sql .= ", SUM(CASE WHEN m.visit_type = 1 THEN 1 ELSE 0 END) AS nb_first";
$sql .= ", SUM(CASE WHEN m.visit_type = 2 THEN 1 ELSE 0 END) AS nb_follow";
$sql .= ", MIN(m.visit_date) AS first_visit, MAX(m.visit_date) AS last_visit";
$sql .= " FROM ".MAIN_DB_PREFIX."medrecord AS m";
$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."patient_profile AS pp ON pp.rowid = m.fk_patient";
$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."societe AS s ON s.rowid = pp.fk_soc";
$sql .= $sqlWhere;
$sql .= " GROUP BY m.fk_patient, pp.card_no, s.nom";
$sql .= " ORDER BY nb_visit DESC, s.nom";

$resql = $db->query($sql);
if (!$resql) {
	dol_print_error($db);
	exit;
}
$rows = array();
$totals = array('nb_visit' => 0, 'nb_first' => 0, 'nb_follow' => 0);
while ($o = $db->fetch_object($resql)) {
	$rows[] = $o;
	$totals['nb_visit'] += (int) $o->nb_visit;
	$totals['nb_first'] += (int) $o->nb_first;
	$totals['nb_follow'] += (int) $o->nb_follow;
}
$db->free($resql);

// ---- Per-patient extras: main doctor, then visit gaps --------------------------
// The attending doctor is resolved in its own grouped query so the per-patient
// aggregate above stays deterministic (MySQL would otherwise pick an arbitrary
// doctor for a group holding several).
$resql = $db->query("SELECT m.fk_patient, m.fk_doctor, COUNT(*) AS nb, CONCAT_WS(' ', u.lastname, u.firstname) AS doctor_label"
	." FROM ".MAIN_DB_PREFIX."medrecord AS m"
	." LEFT JOIN ".MAIN_DB_PREFIX."user AS u ON u.rowid = m.fk_doctor"
	.$sqlWhere." AND m.fk_doctor > 0"
	." GROUP BY m.fk_patient, m.fk_doctor");
if ($resql) {
	while ($o = $db->fetch_object($resql)) {
		$fkPatient = (int) $o->fk_patient;
		if (!isset($patientDoctor[$fkPatient]) || (int) $o->nb > (int) $patientDoctor[$fkPatient]['nb']) {
			$patientDoctor[$fkPatient] = array('label' => (string) $o->doctor_label, 'nb' => (int) $o->nb);
		}
	}
	$db->free($resql);
}

// Follow-up analysis: only patients with at least two visits.
$gaps = array();
foreach ($rows as $r) {
	if ((int) $r->nb_visit >= 2) {
		$gaps[(int) $r->fk_patient] = medrecordVisitGaps($db, (int) $r->fk_patient, $dateFrom, $dateTo);
	}
}

// ---- CSV (must run before any output) -----------------------------------------
if ($action === 'export') {
	$csvName = 'medrecord_report_visits'.dol_now('%Y%m%d%H%M%S');
	// Module temp dir: always inside the open_basedir whitelist (d:/dolibarr/...).
	$csvDir = empty($conf->medrecord->dir_temp) ? DOL_DOCUMENT_ROOT.'/dolibarr_documents/temp/' : $conf->medrecord->dir_temp;
	$csvFile = $csvDir.'/'.$csvName.'.csv';
	$fh = fopen($csvFile, 'w');
	if ($fh) {
		// UTF-8 BOM so Excel reads the Chinese header/values correctly.
		fwrite($fh, "\xEF\xBB\xBF");
		fputcsv($fh, array(
			$langs->trans('ThirdPartyName'),
			$langs->trans('PatientCardNo'),
			$langs->trans('MedRecordVisitTotal'),
			$langs->trans('MedRecordVisitFirstVisit'),
			$langs->trans('MedRecordVisitFollow'),
			$langs->trans('MedRecordVisitDateFirst'),
			$langs->trans('MedRecordVisitDateLast'),
			$langs->trans('MedRecordDoctor'),
			$langs->trans('MedRecordVisitGapAvg'),
			$langs->trans('MedRecordVisitGapMin'),
			$langs->trans('MedRecordVisitGapMax'),
		));
		foreach ($rows as $r) {
			$g = isset($gaps[(int) $r->fk_patient]) ? $gaps[(int) $r->fk_patient] : null;
			fputcsv($fh, array(
				(string) $r->patient_name,
				(string) $r->card_no,
				(int) $r->nb_visit,
				(int) $r->nb_first,
				(int) $r->nb_follow,
				(string) $r->first_visit,
				(string) $r->last_visit,
				(string) $patientDoctor[(int) $r->fk_patient]['label'],
				$g ? number_format((float) $g['avg'], 1, '.', '') : '',
				$g ? (string) $g['min'] : '',
				$g ? (string) $g['max'] : '',
			));
		}
		fputcsv($fh, array(
			$langs->trans('MedRecordVisitTotal'),
			'',
			$totals['nb_visit'],
			$totals['nb_first'],
			$totals['nb_follow'],
			'',
			'',
			'',
			'',
			'',
			'',
		));
		fclose($fh);
		top_httphead('text/csv; charset=UTF-8');
		header('Content-Description: File Transfer');
		header('Content-Disposition: attachment; filename="'.$csvName.'.csv"');
		header('Cache-Control: Public, must-revalidate');
		header('Pragma: public');
		readfile($csvFile);
		exit;
	}
	setEventMessages($langs->trans('MedRecordReportExportFailed'), null, 'errors');
}

llxHeader('', $langs->trans("MedRecordReportVisits"));

print load_fiche_titre(
	$langs->trans("MedRecordReportVisits"),
	'<a class="butAction" href="'.dol_buildpath('/medrecord/list.php', 1).'"><span class="fa fa-list fa-fw valignmiddle"></span>'.$langs->trans("MedRecordReportBackToList").'</a>',
	'fa-calendar-check'
);

$form = new Form($db);

// ---- Patient filter options ---------------------------------------------------
$patients = array();
$resql = $db->query("SELECT DISTINCT m.fk_patient FROM ".MAIN_DB_PREFIX."medrecord AS m"
	." WHERE m.entity = ".((int) $conf->entity)." AND m.fk_patient > 0 ORDER BY m.fk_patient");
if ($resql) {
	while ($o = $db->fetch_object($resql)) {
		$patients[(int) $o->fk_patient] = (int) $o->fk_patient;
	}
	$db->free($resql);
}
foreach ($patients as $pid => $unused) {
	$patients[$pid] = medrecordPatientName($db, $pid);
}

// ---- Filter form ---------------------------------------------------------------
print '<form method="GET" action="'.$_SERVER["PHP_SELF"].'" name="formvisitsfilter">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="4">'.$langs->trans("MedRecordReportFilter").'</td></tr>';
print '<tr>';
print '<td class="nowrap">'.$langs->trans("MedRecordVisitDate").'</td><td class="nowrap">';
print $form->selectDate($dateFrom, 'search_from', 0, 0, 1, '', 1, 0).' - ';
print $form->selectDate($dateTo, 'search_to', 0, 0, 1, '', 1, 0);
print '</td>';
print '<td class="nowrap">'.$langs->trans("ThirdPartyName").'</td><td class="nowrap">';
print '<select name="search_fk_patient" class="minwidth200" onchange="this.form.submit()">';
print '<option value="0">'.$langs->trans("MedRecordReportFilterAll").'</option>';
foreach ($patients as $pid => $plabel) {
	print '<option value="'.(int) $pid.'"'.($searchFkPatient == $pid ? ' selected' : '').'>'.dol_escape_htmltag($plabel).'</option>';
}
print '</select></td>';
print '</tr><tr>';
print '<td>'.$langs->trans("MedRecordVisitType").'</td><td><select name="search_visit_type" class="minwidth150" onchange="this.form.submit()">';
print '<option value="0">'.$langs->trans("MedRecordReportFilterAll").'</option>';
print '<option value="1"'.($searchVisitType == 1 ? ' selected' : '').'>'.$langs->trans("MedRecordVisitFirst").'</option>';
print '<option value="2"'.($searchVisitType == 2 ? ' selected' : '').'>'.$langs->trans("MedRecordVisitFollow").'</option>';
print '</select></td>';
print '<td colspan="2" class="center">';
print '<button type="submit" class="button" name="submitfilter" value="1">'.$langs->trans("Refresh").'</button>';
print ' <button type="submit" class="button" name="action" value="export">'.$langs->trans("MedRecordReportExport").'</button>';
print ' <button type="submit" class="button" name="button_removefilter" value="1">'.$langs->trans("ClearFilter").'</button>';
print '</td></tr>';
print '</table>';
print '</form>';

// ---- Stat pills ----------------------------------------------------------------
print '<div class="fichecenter marginbottomonly">';
print '<span class="badge badge-status4">'.$langs->trans("MedRecordVisitTotal").' '.$totals['nb_visit'].'</span> ';
print '<span class="badge badge-status1">'.$langs->trans("MedRecordVisitFirstVisit").' '.$totals['nb_first'].'</span> ';
print '<span class="badge badge-status0">'.$langs->trans("MedRecordVisitFollow").' '.$totals['nb_follow'].'</span> ';
print '<span class="badge badge-status4">'.$langs->trans("MedRecordReportFollowUpPatients").' '.count($gaps).'</span>';
print '</div>';

// ---- Table ---------------------------------------------------------------------
print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<th class="liste_titre">'.$langs->trans("ThirdPartyName").'</th>';
print '<th class="liste_titre">'.$langs->trans("PatientCardNo").'</th>';
print '<th class="liste_titre right">'.$langs->trans("MedRecordVisitTotal").'</th>';
print '<th class="liste_titre center">'.$langs->trans("MedRecordVisitFirstVisit").'</th>';
print '<th class="liste_titre center">'.$langs->trans("MedRecordVisitFollow").'</th>';
print '<th class="liste_titre center nowrap">'.$langs->trans("MedRecordVisitDateFirst").'</th>';
print '<th class="liste_titre center nowrap">'.$langs->trans("MedRecordVisitDateLast").'</th>';
print '<th class="liste_titre">'.$langs->trans("MedRecordDoctor").'</th>';
print '<th class="liste_titre right">'.$langs->trans("MedRecordVisitGapAvg").'</th>';
print '<th class="liste_titre right">'.$langs->trans("MedRecordVisitGapMin").'</th>';
print '<th class="liste_titre right">'.$langs->trans("MedRecordVisitGapMax").'</th>';
print '</tr>';

if (empty($rows)) {
	print '<tr><td colspan="11"><span class="opacitymedium">'.$langs->trans("MedRecordReportNoData").'</span></td></tr>';
}
foreach ($rows as $r) {
	$fkPatient = (int) $r->fk_patient;
	$g = isset($gaps[$fkPatient]) ? $gaps[$fkPatient] : null;
	print '<tr class="oddeven">';
	// The patient name opens the visit list filtered on that patient, so a
	// follow-up figure can be traced back to the visits behind it.
	$drill = '/medrecord/list.php?search_fk_patient='.(int) $r->fk_patient;
	if (GETPOSTINT('search_fromyear')) {
		$drill .= '&search_fromyear='.GETPOSTINT('search_fromyear').'&search_frommonth='.GETPOSTINT('search_frommonth').'&search_fromday='.GETPOSTINT('search_fromday');
	}
	if (GETPOSTINT('search_toyear')) {
		$drill .= '&search_toyear='.GETPOSTINT('search_toyear').'&search_tomonth='.GETPOSTINT('search_tomonth').'&search_today='.GETPOSTINT('search_today');
	}
	print '<td><a href="'.dol_buildpath($drill, 1).'">'.dol_escape_htmltag((string) $r->patient_name).'</a></td>';
	print '<td>'.dol_escape_htmltag((string) $r->card_no).'</td>';
	print '<td class="right">'.(int) $r->nb_visit.'</td>';
	print '<td class="center">'.(int) $r->nb_first.'</td>';
	print '<td class="center">'.(int) $r->nb_follow.'</td>';
	print '<td class="center nowrap">'.dol_print_date($db->jdate($r->first_visit), 'day').'</td>';
	print '<td class="center nowrap">'.dol_print_date($db->jdate($r->last_visit), 'day').'</td>';
	print '<td class="nowrap">'.dol_escape_htmltag($patientDoctor[$fkPatient]['label']).'</td>';
	// Gap columns stay empty for single-visit patients: they are not follow-ups.
	print '<td class="right">'.($g ? number_format((float) $g['avg'], 1) : '').'</td>';
	print '<td class="right">'.($g ? (int) $g['min'] : '').'</td>';
	print '<td class="right">'.($g ? (int) $g['max'] : '').'</td>';
	print '</tr>';
}
print '<tr class="liste_titre">';
print '<th>'.$langs->trans("MedRecordReportTotalPatients").'</th>';
print '<th></th>';
print '<th class="right">'.$totals['nb_visit'].'</th>';
print '<th class="right">'.$totals['nb_first'].'</th>';
print '<th class="right">'.$totals['nb_follow'].'</th>';
print '<th colspan="6"></th>';
print '</tr>';
print '</table></div>';

llxFooter();
$db->close();

/**
 * Days between two consecutive visits of one patient.
 *
 * Only patients with at least two visits are follow-up patients, so an
 * empty result means "single visit, nothing to analyse".
 *
 * @param DoliDB $db         Database handler
 * @param int    $fkPatient  Patient profile rowid
 * @param int    $dateFrom   Lower bound (unix ts) or 0 for no bound
 * @param int    $dateTo     Upper bound (unix ts) or 0 for no bound
 * @return array{avg:float,min:int,max:int}|null
 */
function medrecordVisitGaps($db, $fkPatient, $dateFrom, $dateTo)
{
	global $db, $conf;
	$sql = "SELECT m.visit_date FROM ".MAIN_DB_PREFIX."medrecord AS m";
	$sql .= " WHERE m.fk_patient = ".((int) $fkPatient)." AND m.entity = ".((int) $conf->entity);
	if ($dateFrom) {
		$sql .= " AND m.visit_date >= '".$db->idate($dateFrom)."'";
	}
	if ($dateTo) {
		$sql .= " AND m.visit_date <= '".$db->idate($dateTo)."'";
	}
	$sql .= " ORDER BY m.visit_date";
	$resql = $db->query($sql);
	if (!$resql) {
		return null;
	}
	$dates = array();
	while ($o = $db->fetch_object($resql)) {
		$dates[] = (string) $o->visit_date;
	}
	$db->free($resql);
	if (count($dates) < 2) {
		return null;
	}
	$diffs = array();
	for ($i = 1; $i < count($dates); $i++) {
		$t1 = dol_stringtotime($dates[$i - 1]);
		$t2 = dol_stringtotime($dates[$i]);
		if ($t1 && $t2) {
			$diffs[] = (int) round(($t2 - $t1) / 86400);
		}
	}
	if (empty($diffs)) {
		return null;
	}
	return array(
		'avg' => array_sum($diffs) / count($diffs),
		'min' => min($diffs),
		'max' => max($diffs),
	);
}

/**
 * Patient display name: profile card number plus the third-party name.
 *
 * @param DoliDB $db    Database handler
 * @param int    $pid   Patient profile rowid
 * @return string       Label
 */
function medrecordPatientName($db, $pid)
{
	global $conf;
	$sql = "SELECT pp.card_no, s.nom FROM ".MAIN_DB_PREFIX."patient_profile AS pp";
	$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."societe AS s ON s.rowid = pp.fk_soc";
	$sql .= " WHERE pp.rowid = ".((int) $pid);
	$resql = $db->query($sql);
	if (!$resql) {
		return '#'.$pid;
	}
	$o = $db->fetch_object($resql);
	$db->free($resql);
	if (!$o) {
		return '#'.$pid;
	}
	return trim(($o->nom ? $o->nom : '').($o->card_no ? ' ('.$o->card_no.')' : ''));
}
