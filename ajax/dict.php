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
 * \file    htdocs/custom/medrecord/ajax/dict.php
 * \ingroup medrecord
 * \brief   Autocomplete data source for the three dictionaries:
 *          GET table=icd10|tcm_disease|tcm_syndrome&term=... -> JSON
 *          [{code,label,extra_code,value}]. Session-authenticated, 'medrecord read'.
 */

if (!defined('NOTOKENRENEWAL')) {
	define('NOTOKENRENEWAL', '1');
}
if (!defined('NOREQUIREMENU')) {
	define('NOREQUIREMENU', '1');
}
if (!defined('NOREQUIREHTML')) {
	define('NOREQUIREHTML', '1');
}
if (!defined('NOREQUIREAJAX')) {
	define('NOREQUIREAJAX', '1');
}

$res = 0;
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res && file_exists("../../../../main.inc.php")) {
	$res = @include "../../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

dol_include_once('/medrecord/lib/medrecord.lib.php');

/**
 * @var DoliDB $db
 * @var User $user
 */

if (empty($user->id) || !$user->hasRight('medrecord', 'read')) {
	header('HTTP/1.1 403 Forbidden');
	exit;
}

header('Content-Type: application/json; charset=utf-8');

$tables = array('icd10' => 'c_medrecord_icd10', 'tcm_disease' => 'c_medrecord_tcm_disease', 'tcm_syndrome' => 'c_medrecord_tcm_syndrome');
$table = GETPOST('table', 'aZ09');
$term = trim(GETPOST('term', 'alphanohtml'));
if (!isset($tables[$table]) || $term === '') {
	echo json_encode(array());
	$db->close();
	exit;
}

$out = array();
foreach (medrecord_dict_search($db, $tables[$table], $term, 20) as $row) {
	$label = $row['code'].' '.$row['label'];
	if (!empty($row['extra_code'])) {
		$label .= ' ('.$row['extra_code'].')';
	}
	$out[] = array('code' => $row['code'], 'label' => $label, 'name' => $row['label'], 'extra_code' => $row['extra_code'], 'value' => $label);
}
echo json_encode($out);
$db->close();
