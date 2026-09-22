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
 * \file    htdocs/custom/medrecord/tests/unit/MedRecordTest.php
 * \ingroup medrecord
 * \brief   modMedRecord structural tests, phase 1 (no DB needed).
 */

use PHPUnit\Framework\TestCase;

/**
 * Class MedRecordTest
 */
class MedRecordTest extends TestCase
{
	/**
	 * Descriptor: healthcare ID 501610, depends on modPatient, one-level
	 * permissions, left menu under clinic, patient tab, hook context, three
	 * dictionaries with non-empty tabhelp, constants, tables kept on remove.
	 */
	public function testDescriptor()
	{
		$content = file_get_contents(__DIR__.'/../../core/modules/modMedRecord.class.php');
		$this->assertStringContainsString('$this->numero = 501610;', $content);
		$this->assertStringContainsString("rights_class = 'medrecord'", $content);
		$this->assertStringContainsString("depends = array('modPatient')", $content);
		$this->assertStringContainsString("_load_tables('/medrecord/sql/')", $content);
		foreach (array("'read'", "'write'", "'sign'", "'void'", "'admin'") as $perm) {
			$this->assertStringContainsString('[4] = '.$perm, $content, 'one-level permission '.$perm);
		}
		$this->assertStringNotContainsString('[5] = ', $content);
		$this->assertStringContainsString("'fk_menu' => 'fk_mainmenu=clinic'", $content, 'hangs under the shared Clinic top menu');
		$this->assertStringNotContainsString("'type' => 'top'", $content, 'must not create a second top menu');
		$this->assertStringContainsString("patient:+medrecord:", $content, 'tab on the patient card via modPatient 0.1.1');
		$this->assertStringContainsString("'hooks' => array('medrecordcard')", $content, 'hook context for modPrescription');
		foreach (array('c_medrecord_icd10', 'c_medrecord_tcm_disease', 'c_medrecord_tcm_syndrome') as $dict) {
			$this->assertStringContainsString("'".$dict."'", $content);
		}
		$this->assertStringContainsString("'MEDRECORD_RETENTION_YEARS', 'chaine', '15'", $content, 'regulatory default 15 years');
		$this->assertStringContainsString("'MEDRECORD_SIGN_LOCK_HOURS', 'chaine', '0'", $content, 'locked at signature by default');
		$this->assertStringNotContainsString('DROP TABLE', $content);
	}

	/**
	 * SQL: record table has status machine + void/sign trail columns, no
	 * plaintext patient identity, diagnosis snapshot label, sequence table,
	 * dictionaries without auto increment, file names accepted by _load_tables.
	 */
	public function testSqlSchema()
	{
		$sqlDir = __DIR__.'/../../sql/';
		$rec = file_get_contents($sqlDir.'llx_medrecord.sql');
		foreach (array('fk_patient', 'fk_doctor', 'visit_date', 'chief_complaint', 'tongue', 'pulse', 'tcm_disease_code', 'tcm_syndrome_code',
			'fk_ref_medrecord', 'status', 'date_signed', 'fk_user_sign', 'void_reason', 'date_void', 'fk_user_void', 'entity') as $col) {
			$this->assertStringContainsString($col, $rec, 'column '.$col);
		}
		$this->assertStringNotContainsString('id_number', $rec, 'no patient identity copied into records');
		$this->assertStringContainsString('uk_medrecord_ref', file_get_contents($sqlDir.'llx_medrecord.key.sql'));

		$diag = file_get_contents($sqlDir.'llx_medrecord_diagnosis.sql');
		$this->assertStringContainsString('label', $diag, 'diagnosis label snapshot');
		$this->assertStringContainsString('diag_type', $diag);

		$seq = file_get_contents($sqlDir.'llx_medrecord_sequence.sql');
		$this->assertStringContainsString('CREATE TABLE IF NOT EXISTS', $seq);
		$this->assertStringContainsString('ENGINE=innodb', $seq);

		$dict = file_get_contents($sqlDir.'llx_c_medrecord_dictionaries.sql');
		$this->assertStringNotContainsString('AUTO_INCREMENT', $dict);
		$this->assertStringContainsString('extra_code', $dict, 'ICD-10 clinical edition asterisk code');
		$keys = file_get_contents($sqlDir.'llx_c_medrecord_dictionaries.key.sql');
		foreach (array('uk_c_medrecord_icd10_code', 'uk_c_medrecord_tcm_disease_code', 'uk_c_medrecord_tcm_syndrome_code') as $k) {
			$this->assertStringContainsString($k, $keys, 'unique code keeps CSV import idempotent');
		}

		$icd = file_get_contents($sqlDir.'data_medrecord_icd10.sql');
		$this->assertStringContainsString("'J06.900'", $icd);
		$this->assertRegExp("/'[A-Z][0-9]{2}\\.[0-9x]{3}'/", $icd, 'clinical edition code shape');
		$this->assertGreaterThan(35, substr_count($icd, "\n("), 'about 40 seed diagnoses');
		$tcm = file_get_contents($sqlDir.'data_medrecord_tcm.sql');
		$this->assertStringContainsString("'BM-001'", $tcm);
		$this->assertStringContainsString("'ZH-001'", $tcm);
		$this->assertGreaterThan(80, substr_count($tcm, "\n("), 'about 40 diseases + 40 syndromes');

		foreach (glob($sqlDir.'*.sql') as $file) {
			$base = basename($file);
			$this->assertTrue(strpos($base, 'llx_') === 0 || strpos($base, 'data') === 0, $base.' would be ignored by _load_tables');
		}
	}

	/**
	 * Every page checks a medrecord permission; the patient tab also needs
	 * patient read; setup requires admin.
	 */
	public function testPagePermissions()
	{
		$root = __DIR__.'/../../';
		$pages = array(
			'list.php' => "hasRight('medrecord', 'read')",
			'card.php' => "hasRight('medrecord', 'read')",
			'patient_tab.php' => "hasRight('medrecord', 'read')",
			'admin/setup.php' => "hasRight('medrecord', 'admin')",
		);
		foreach ($pages as $page => $needle) {
			$content = file_get_contents($root.$page);
			$this->assertStringContainsString($needle, $content, $page);
			$this->assertStringContainsString('accessforbidden(', $content, $page);
		}
		$tab = file_get_contents($root.'patient_tab.php');
		$this->assertStringContainsString("hasRight('patient', 'read')", $tab);
		$this->assertStringContainsString('patient_prepare_head(', $tab, 'reuses modPatient tabs');
		$this->assertStringContainsString('patient_summary_banner(', $tab, 'reuses modPatient summary');
		$setup = file_get_contents($root.'admin/setup.php');
		$this->assertStringContainsString('newToken()', $setup);
	}

	/**
	 * Lib: status constants, no delete path, dictionary helpers validate the
	 * table name before SQL, audit goes through modPatient.
	 */
	public function testLib()
	{
		$lib = file_get_contents(__DIR__.'/../../lib/medrecord.lib.php');
		$this->assertStringContainsString("define('MEDRECORD_STATUS_VOIDED', 9)", $lib);
		$this->assertStringContainsString("dol_include_once('/patient/lib/patient.lib.php')", $lib, 'audit + patient helpers come from modPatient');
		$this->assertStringNotContainsString('DELETE FROM', $lib);
		$this->assertStringContainsString("preg_match('/^c_medrecord_[a-z0-9_]+$/', \$table)", $lib, 'table name allowlist before SQL');
		foreach (array('function medrecord_status_label', 'function medrecord_dict_search', 'function medrecord_timeline', 'function medrecord_dict_label') as $fn) {
			$this->assertStringContainsString($fn, $lib);
		}
	}

	/**
	 * Phase 2: MedicalRecord class state machine, transaction shape, audit
	 * actions, no delete of the record row; pages and AJAX gated.
	 */
	public function testRecordClassAndPages()
	{
		$cls = file_get_contents(__DIR__.'/../../class/medicalrecord.class.php');
		$this->assertStringContainsString('class MedicalRecord extends CommonObject', $cls);
		$this->assertStringContainsString('new MedRecordNumbering($this->db)', $cls, 'number reserved on the same transaction');
		$this->assertStringContainsString('$this->db->begin();', $cls);
		foreach (array("'MEDRECORD_CREATE'", "'MEDRECORD_MODIFY'", "'MEDRECORD_MODIFY_AFTER_SIGN'", "'MEDRECORD_SIGN'", "'MEDRECORD_VOID'") as $a) {
			$this->assertStringContainsString($a, $cls, 'audit action '.$a);
		}
		$this->assertStringContainsString('patient_audit($this->db', $cls, 'audit through modPatient');
		$this->assertStringNotContainsString('DELETE FROM '.'"'.'.$this->db->prefix()."medrecord WHERE', $cls, 'record row is never deleted');
		$this->assertSame(1, substr_count($cls, 'DELETE FROM'), 'only the diagnosis rows of an editable draft are replaced');
		$this->assertStringContainsString('WHERE fk_medrecord = ', $cls);
		foreach (array('function canEdit', 'function canSign', 'function canVoid', 'function sign', 'function void', 'function newFollowUp', 'function inSignLockWindow') as $fn) {
			$this->assertStringContainsString($fn, $cls);
		}
		$this->assertStringContainsString("'MedRecordErrVoidReasonRequired'", $cls, 'void needs a reason');
		$this->assertStringContainsString("'MedRecordErrDiagnosisRequired'", $cls, 'sign needs a diagnosis');
		$this->assertStringContainsString('AND status = '.'".MEDRECORD_STATUS_DRAFT', $cls, 'sign is a guarded state transition');
		$this->assertStringContainsString("getDolGlobalInt('MEDRECORD_SIGN_LOCK_HOURS', 0)", $cls);
		$this->assertStringContainsString('public function diffAgainst(', $cls, 'audit carries a field-level diff');
		$this->assertStringContainsString("'changes' => \$changes", $cls, 'diff stored in MODIFY / MODIFY_AFTER_SIGN audit rows');

		$card = file_get_contents(__DIR__.'/../../card.php');
		$this->assertStringContainsString("'MEDRECORD_READ'", $card, 'opening a record is audited');
		$this->assertStringContainsString('$object->canEdit($user)', $card);
		$this->assertStringContainsString('$object->canSign($user)', $card);
		$this->assertStringContainsString('$object->canVoid($user)', $card);
		$this->assertStringContainsString("executeHooks('printMedRecordCard'", $card, 'extension point for modPrescription');
		$this->assertStringContainsString("initHooks(array('medrecordcard'))", $card);
		$this->assertStringContainsString("trim((string) \$hookmanager->resPrint) === ''", $card, '0.1.1: placeholder only when no module printed anything');
		$this->assertStringContainsString("'medrecord');", $card, '0.1.2: context bar highlights the medical records tab and carries a breadcrumb');
		$this->assertStringContainsString('patient_select_html(', $card, 'patient picker from modPatient 0.1.1');
		$this->assertStringContainsString('newToken()', $card);

		$list = file_get_contents(__DIR__.'/../../list.php');
		$this->assertTrue(strpos($list, '<form method="GET"') < strpos($list, 'print_barre_liste('), 'form before print_barre_liste');
		$this->assertStringContainsString("(int) GETPOST('page', 'int')", $list);
		$this->assertStringContainsString("'&limit='.(int) \$limit", $list);

		$ajax = file_get_contents(__DIR__.'/../../ajax/dict.php');
		$this->assertStringContainsString("hasRight('medrecord', 'read')", $ajax);
		$this->assertStringContainsString('403 Forbidden', $ajax);
		$this->assertStringContainsString("'icd10' => 'c_medrecord_icd10'", $ajax, 'table allowlist');

		// Phase 3: print view is audited and never shows the ID number; import is admin-only, upload-validated
		$print = file_get_contents(__DIR__.'/../../print.php');
		$this->assertStringContainsString("hasRight('medrecord', 'read')", $print);
		$this->assertStringContainsString("'MEDRECORD_PRINT'", $print, 'printing is audited');
		$this->assertStringContainsString('MedRecordPrintedBy', $print, 'operator watermark in footer');
		foreach (array('id_number', 'getIdNumberPlain', 'getIdNumberMasked') as $forbidden) {
			$this->assertStringNotContainsString($forbidden, $print);
		}
		$setup = file_get_contents(__DIR__.'/../../admin/setup.php');
		$this->assertStringContainsString('is_uploaded_file(', $setup);
		$this->assertStringContainsString('MedRecordDictImport::TABLES[$type]', $setup, 'dictionary type allowlist');
		$this->assertStringContainsString("hasRight('medrecord', 'admin')", $setup);
		$this->assertStringContainsString('enctype="multipart/form-data"', $setup);
	}

	/**
	 * Phase 4: REST API. One permission gate per endpoint, medical read
	 * audited, state changes go through the class (same rules as UI), no
	 * patient identity fields beyond card no / name.
	 */
	public function testApi()
	{
		$api = file_get_contents(__DIR__.'/../../class/api_medrecord.class.php');
		$this->assertStringContainsString('class Medrecord extends DolibarrApi', $api, 'api_medrecord.class.php => class Medrecord');
		$this->assertStringContainsString('DolibarrApiAccess {@requires user,external}', $api);
		preg_match_all('/@url\s+(GET|POST|DELETE|PUT)\s+(\S+)/', $api, $urls);
		$endpoints = count($urls[0]) - 1; // constructor's "GET /"
		$this->assertSame(7, $endpoints, 'spec §3.5 lists 7 endpoints');
		$this->assertSame($endpoints, substr_count($api, "DolibarrApiAccess::\$user->hasRight('medrecord'"), 'one permission gate per endpoint');
		foreach (array("'MEDRECORD_READ'", '->sign(DolibarrApiAccess::$user)', '->void(DolibarrApiAccess::$user', '->canEdit(DolibarrApiAccess::$user)') as $needle) {
			$this->assertStringContainsString($needle, $api);
		}
		foreach (array('id_number', 'getIdNumberPlain', 'getIdNumberMasked', 'DELETE FROM') as $forbidden) {
			$this->assertStringNotContainsString($forbidden, $api);
		}
		$this->assertStringContainsString("'icd10' => 'c_medrecord_icd10'", $api, 'dictionary allowlist');
	}

	/**
	 * Language files: both locales define the same keys.
	 */
	public function testLangFilesInSync()
	{
		$zh = $this->langKeys(__DIR__.'/../../langs/zh_CN/medrecord.lang');
		$en = $this->langKeys(__DIR__.'/../../langs/en_US/medrecord.lang');
		$this->assertSame(array(), array_values(array_diff($zh, $en)), 'keys missing in en_US');
		$this->assertSame(array(), array_values(array_diff($en, $zh)), 'keys missing in zh_CN');
		foreach (array('ModuleMedRecordName', 'MedRecordTab', 'MedRecordDictIcd10', 'MedRecordPermSign', 'MedRecordStatusVoided') as $key) {
			$this->assertTrue(in_array($key, $zh, true), 'lang key '.$key);
		}
	}

	/**
	 * @param string $file lang file path
	 * @return string[] keys
	 */
	private function langKeys($file)
	{
		$keys = array();
		foreach (file($file) as $line) {
			$line = trim($line);
			if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
				continue;
			}
			$keys[] = trim(substr($line, 0, strpos($line, '=')));
		}
		return $keys;
	}
}
