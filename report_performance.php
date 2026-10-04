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
 * \file    htdocs/custom/medrecord/report_performance.php
 * \ingroup medrecord
 * \brief   Doctor performance: visits, patients, revenue and prescriptions per
 *          doctor, with a drill-down into that doctor's visit list.
 *
 * Revenue and prescription counts come from per-visit subqueries rather than a
 * plain join: a visit can carry several bills, so joining the bill table
 * directly would multiply the visit count.
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
dol_include_once('/clinicpay/lib/clinicpay.lib.php');

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array("medrecord@medrecord"));

if (!$user->hasRight('medrecord', 'read')) {
	accessforbidden();
}

$dateFrom = substr(GETPOST('search_from', 'alpha'), 0, 10);
$dateTo = substr(GETPOST('search_to', 'alpha'), 0, 10);
if ($dateFrom === '' && $dateTo === '') {
	$dateTo = date('Y-m-d');
	$dateFrom = date('Y-m-d', strtotime('-89 days'));
}
$action = GETPOST('action', 'aZ09');

$P = $db->prefix();
$form = new Form($db);

// ---- Query --------------------------------------------------------------------
$where = " WHERE m.entity = ".((int) $conf->entity)." AND m.fk_doctor > 0";
if ($dateFrom) {
	$where .= " AND m.visit_date >= '".$db->escape($dateFrom)."'";
}
if ($dateTo) {
	$where .= " AND m.visit_date <= '".$db->escape($dateTo)."'";
}

$sql = "SELECT u.rowid AS fk_doctor, CONCAT_WS(' ', u.lastname, u.firstname) AS doctor_label";
$sql .= ", COUNT(DISTINCT m.rowid) AS nb_visit, COUNT(DISTINCT m.fk_patient) AS nb_patient";
$sql .= ", COALESCE(SUM(b.amount), 0) AS amount";
$sql .= ", COALESCE(SUM(p.nb), 0) AS nb_prescription";
$sql .= " FROM ".$P."medrecord AS m";
$sql .= " INNER JOIN ".$P."user AS u ON u.rowid = m.fk_doctor";
$sql .= " LEFT JOIN (SELECT fk_medrecord, SUM(amount_total) AS amount FROM ".$P."clinicpay_bill";
$sql .= " WHERE status = ".CLINICPAY_BILL_PAID." GROUP BY fk_medrecord) AS b ON b.fk_medrecord = m.rowid";
$sql .= " LEFT JOIN (SELECT fk_medrecord, COUNT(*) AS nb FROM ".$P."prescription";
$sql .= " GROUP BY fk_medrecord) AS p ON p.fk_medrecord = m.rowid";
$sql .= $where." GROUP BY u.rowid, u.lastname, u.firstname ORDER BY nb_visit DESC";

$resql = $db->query($sql);
$rows = array();
if ($resql) {
	while ($o = $db->fetch_object($resql)) {
		$rows[] = $o;
	}
	$db->free($resql);
} else {
	dol_print_error($db);
	exit;
}

$totVisit = 0;
$totAmount = 0.0;
$totPresc = 0;
foreach ($rows as $r) {
	$totVisit += (int) $r->nb_visit;
	$totAmount += (float) $r->amount;
	$totPresc += (int) $r->nb_prescription;
}

// ---- CSV (must run before any output) -----------------------------------------
if ($action === 'export') {
	$csvName = 'medrecord_performance_'.dol_now('%Y%m%d%H%M%S');
	$csvDir = empty($conf->medrecord->dir_temp) ? DOL_DOCUMENT_ROOT.'/dolibarr_documents/temp/' : $conf->medrecord->dir_temp;
	$csvFile = $csvDir.'/'.$csvName.'.csv';
	$fh = fopen($csvFile, 'w');
	if ($fh) {
		fwrite($fh, "\xEF\xBB\xBF");
		fputcsv($fh, array(
			$langs->trans("MedRecordDoctor"),
			$langs->trans("MedRecordPerfVisits"),
			$langs->trans("MedRecordPerfPatients"),
			$langs->trans("MedRecordPerfPerPatient"),
			$langs->trans("MedRecordPerfAmount"),
			$langs->trans("MedRecordPerfPrescriptions"),
		));
		foreach ($rows as $r) {
			fputcsv($fh, array(
				(string) $r->doctor_label,
				(int) $r->nb_visit,
				(int) $r->nb_patient,
				number_format((int) $r->nb_patient > 0 ? (float) $r->nb_visit / (int) $r->nb_patient : 0, 2, '.', ''),
				number_format((float) $r->amount, 2, '.', ''),
				(int) $r->nb_prescription,
			));
		}
		fclose($fh);
		top_httphead('text/csv; charset=UTF-8');
		header('Content-Description: File Transfer');
		header('Content-Disposition: attachment; filename="'.$csvName.'.csv"');
		header('Cache-Control: Public, must-revalidate');
		header('Pragma: public');
		readfile($csvFile);
		exit;
	}
	setEventMessages($langs->trans("PharmacyReportExportFailed"), null, 'errors');
}

llxHeader('', $langs->trans("MedRecordPerformance"));

print load_fiche_titre(
	$langs->trans("MedRecordPerformance"),
	'<a class="butAction" href="'.dol_buildpath('/medrecord/report_visits.php', 1).'"><span class="fa fa-chart-line fa-fw valignmiddle"></span>'.$langs->trans("MedRecordReportVisits").'</a>',
	'fa-user-doctor'
);

// ---- Filter -------------------------------------------------------------------
print '<form method="GET" action="'.$_SERVER["PHP_SELF"].'" name="formperf">';
print '<table class="noborder centpercent">';
print '<tr>';
print '<td class="nowrap">'.$langs->trans("PharmacyLedgerDate").'</td><td class="nowrap">';
print '<input name="search_from" class="minwidth120" value="'.dol_escape_htmltag($dateFrom).'"> - ';
print '<input name="search_to" class="minwidth120" value="'.dol_escape_htmltag($dateTo).'">';
print '</td>';
print '<td class="center">';
print '<button type="submit" class="button" name="submitfilter" value="1">'.$langs->trans("Refresh").'</button>';
print ' <button type="submit" class="button" name="action" value="export">'.$langs->trans("PharmacyReportExport").'</button>';
print ' <button type="submit" class="button" name="button_removefilter" value="1">'.$langs->trans("ClearFilter").'</button>';
print '</td></tr>';
print '</table>';
print '</form>';

print '<div class="fichecenter marginbottomonly">';
print '<span class="badge badge-status4">'.$langs->trans("MedRecordPerfVisits").' '.$totVisit.'</span> ';
print '<span class="badge badge-status1">'.$langs->trans("MedRecordPerfAmount").' '.price($totAmount).'</span> ';
print '<span class="badge badge-status2">'.$langs->trans("MedRecordPerfPrescriptions").' '.$totPresc.'</span>';
print '</div>';

print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<th class="liste_titre">'.$langs->trans("MedRecordDoctor").'</th>';
print '<th class="liste_titre right">'.$langs->trans("MedRecordPerfVisits").'</th>';
print '<th class="liste_titre right">'.$langs->trans("MedRecordPerfPatients").'</th>';
print '<th class="liste_titre right">'.$langs->trans("MedRecordPerfPerPatient").'</th>';
print '<th class="liste_titre right">'.$langs->trans("MedRecordPerfAmount").'</th>';
print '<th class="liste_titre right">'.$langs->trans("MedRecordPerfPrescriptions").'</th>';
print '<th class="liste_titre"></th>';
print '</tr>';

if (empty($rows)) {
	print '<tr><td colspan="7"><span class="opacitymedium">'.$langs->trans("MedRecordReportNoData").'</span></td></tr>';
}
foreach ($rows as $r) {
	$perPatient = ((int) $r->nb_patient > 0) ? ((float) $r->nb_visit / (int) $r->nb_patient) : 0;
	$drill = '/medrecord/list.php?search_doctor='.(int) $r->fk_doctor;
	if ($dateFrom) {
		$drill .= '&search_fromyear='.substr($dateFrom, 0, 4).'&search_frommonth='.substr($dateFrom, 5, 2).'&search_fromday='.substr($dateFrom, 8, 2);
	}
	if ($dateTo) {
		$drill .= '&search_toyear='.substr($dateTo, 0, 4).'&search_tomonth='.substr($dateTo, 5, 2).'&search_today='.substr($dateTo, 8, 2);
	}
	print '<tr class="oddeven">';
	// The doctor name drills into that doctor's visit list.
	print '<td><a href="'.dol_buildpath($drill, 1).'">'.dol_escape_htmltag((string) $r->doctor_label).'</a></td>';
	print '<td class="right">'.(int) $r->nb_visit.'</td>';
	print '<td class="right">'.(int) $r->nb_patient.'</td>';
	print '<td class="right">'.number_format($perPatient, 2, '.', '').'</td>';
	print '<td class="right">'.price((float) $r->amount).'</td>';
	print '<td class="right">'.(int) $r->nb_prescription.'</td>';
	print '<td class="right opacitymedium">'.img_picto('', 'fa-list', 'class="pictofixedwidth"').'</td>';
	print '</tr>';
}
print '</table></div>';

llxFooter();
$db->close();
