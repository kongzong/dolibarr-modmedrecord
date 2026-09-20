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
 * \file    htdocs/custom/medrecord/class/medrecorddictimport.class.php
 * \ingroup medrecord
 * \brief   CSV import into the three dictionaries. Idempotent: upsert on the
 *          unique code (spec §3.1 / §8). Column detection is by shape, not
 *          position, so both the national clinical edition export
 *          (",A01.001+,K77.0*,伤寒性肝炎") and a plain "code,label" file work.
 *          UTF-8 (with/without BOM) and GBK input accepted.
 */
class MedRecordDictImport
{
	/** Dictionary type => table without prefix */
	const TABLES = array(
		'icd10' => 'c_medrecord_icd10',
		'tcm_disease' => 'c_medrecord_tcm_disease',
		'tcm_syndrome' => 'c_medrecord_tcm_syndrome',
	);

	/** Main ICD-10 code (national clinical edition): A01.001, J00.x00, optional dagger '+' */
	const ICD_MAIN = '/^[A-Z][0-9]{2}\.[0-9x]{1,3}\+?$/';
	/** Asterisk / additional code: K77.0*, G01* */
	const ICD_EXTRA = '/^[A-Z][0-9]{2}(\.[0-9x]{1,3})?\*$/';
	/** Module / GB code for TCM tables: BM-001, ZH-012, A01.02, ZYXX000 ... */
	const TCM_CODE = '/^[A-Za-z0-9][A-Za-z0-9._\-\/]{1,15}$/';

	/** @var DoliDB */
	private $db;
	/** @var string */
	public $error = '';

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Interpret one CSV row. Pure function (no DB), unit-tested.
	 *
	 * @param	string[]	$cells	Raw cells
	 * @param	string		$type	icd10 | tcm_disease | tcm_syndrome
	 * @return	array{code:string,label:string,extra_code:?string,pos:?int}|null	null = skip (header, empty, unparseable)
	 */
	public static function parseRow(array $cells, $type)
	{
		$cells = array_map(function ($c) {
			return trim((string) $c);
		}, $cells);
		$cells = array_values(array_filter($cells, function ($c) {
			return $c !== '';
		}));
		if (count($cells) < 2) {
			return null;
		}

		if ($type === 'icd10') {
			$main = null;
			$extra = null;
			$label = null;
			foreach ($cells as $c) {
				if ($main === null && preg_match(self::ICD_MAIN, $c)) {
					$main = rtrim($c, '+');
				} elseif ($extra === null && preg_match(self::ICD_EXTRA, $c)) {
					$extra = $c;
				} elseif ($label === null && !preg_match(self::ICD_MAIN, $c) && !preg_match(self::ICD_EXTRA, $c)) {
					$label = $c;
				}
			}
			// Supplementary rows list the asterisk-style code alone in the "extra" column: use it as the code
			if ($main === null && $extra !== null && $label !== null) {
				$main = rtrim($extra, '*');
				$extra = null;
			}
			if ($main === null || $label === null) {
				return null;
			}
			return array('code' => $main, 'label' => mb_substr($label, 0, 255), 'extra_code' => $extra, 'pos' => null);
		}

		// TCM tables: code, label[, pos] in any order; first short token that looks like a code wins
		$code = null;
		$label = null;
		$pos = null;
		foreach ($cells as $c) {
			if ($code === null && preg_match(self::TCM_CODE, $c) && !preg_match('/^[0-9]+$/', $c) && preg_match('/[A-Za-z0-9]/', $c) && !preg_match('/[\x{4e00}-\x{9fff}]/u', $c)) {
				$code = $c;
			} elseif ($pos === null && preg_match('/^[0-9]{1,5}$/', $c)) {
				$pos = (int) $c;
			} elseif ($label === null) {
				$label = $c;
			}
		}
		if ($code === null || $label === null) {
			return null;
		}
		return array('code' => mb_substr($code, 0, 16), 'label' => mb_substr($label, 0, 128), 'extra_code' => null, 'pos' => $pos);
	}

	/**
	 * Decode file content to UTF-8 (BOM stripped, GBK converted).
	 *
	 * @param	string	$raw	File bytes
	 * @return	string			UTF-8 text
	 */
	public static function toUtf8($raw)
	{
		if (substr($raw, 0, 3) === "\xEF\xBB\xBF") {
			$raw = substr($raw, 3);
		}
		if (mb_check_encoding($raw, 'UTF-8')) {
			return $raw;
		}
		$converted = @mb_convert_encoding($raw, 'UTF-8', 'GB18030');
		return $converted !== false ? $converted : $raw;
	}

	/**
	 * Import a CSV file into a dictionary. Idempotent upsert on code.
	 *
	 * @param	string	$path	Local file path (already validated as an upload)
	 * @param	string	$type	icd10 | tcm_disease | tcm_syndrome
	 * @return	array{read:int,inserted:int,updated:int,skipped:int}|null	null on error (this->error)
	 */
	public function importFile($path, $type)
	{
		if (!isset(self::TABLES[$type])) {
			$this->error = 'MedRecordImportBadType';
			return null;
		}
		$raw = @file_get_contents($path);
		if ($raw === false) {
			$this->error = 'MedRecordImportReadFailed';
			return null;
		}
		$text = self::toUtf8($raw);
		$lines = preg_split('/\r\n|\r|\n/', $text);
		$rows = array();
		foreach ($lines as $line) {
			if (trim($line) === '') {
				continue;
			}
			$parsed = self::parseRow(str_getcsv($line), $type);
			if ($parsed !== null) {
				$rows[$parsed['code']] = $parsed; // last occurrence wins, keeps one row per code
			}
		}
		return $this->upsertRows($rows, $type, count($lines));
	}

	/**
	 * Upsert parsed rows in batches. rowid has no auto increment (dict.php
	 * convention) so ids are allocated from MAX(rowid) in PHP.
	 *
	 * @param	array<string,array>	$rows	code => parsed row
	 * @param	string				$type	Dictionary type
	 * @param	int					$read	Lines read (for stats)
	 * @return	array{read:int,inserted:int,updated:int,skipped:int}|null
	 */
	public function upsertRows(array $rows, $type, $read = 0)
	{
		$table = $this->db->prefix().self::TABLES[$type];
		$isIcd = ($type === 'icd10');

		$resql = $this->db->query("SELECT MAX(rowid) as m FROM ".$table);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return null;
		}
		$nextId = (int) $this->db->fetch_object($resql)->m + 1;
		$this->db->free($resql);

		$existing = array();
		$resql = $this->db->query("SELECT code FROM ".$table);
		if ($resql) {
			while ($o = $this->db->fetch_object($resql)) {
				$existing[$o->code] = true;
			}
			$this->db->free($resql);
		}

		$inserted = 0;
		$updated = 0;
		$batch = 0;
		$this->db->begin();
		foreach ($rows as $code => $r) {
			$code = $this->db->escape($r['code']);
			$label = $this->db->escape($r['label']);
			if ($isIcd) {
				$extra = $r['extra_code'] !== null ? "'".$this->db->escape($r['extra_code'])."'" : 'NULL';
				$chapter = "'".$this->db->escape(substr($r['code'], 0, 1))."'";
				$sql = "INSERT INTO ".$table." (rowid, code, label, extra_code, chapter, active) VALUES (".$nextId.", '".$code."', '".$label."', ".$extra.", ".$chapter.", 1)";
				$sql .= " ON DUPLICATE KEY UPDATE label = VALUES(label), extra_code = VALUES(extra_code), chapter = VALUES(chapter), active = 1";
			} else {
				$pos = $r['pos'] !== null ? (int) $r['pos'] : 0;
				$sql = "INSERT INTO ".$table." (rowid, pos, code, label, active) VALUES (".$nextId.", ".$pos.", '".$code."', '".$label."', 1)";
				$sql .= " ON DUPLICATE KEY UPDATE label = VALUES(label), ".($r['pos'] !== null ? "pos = VALUES(pos), " : "")."active = 1";
			}
			if (!$this->db->query($sql)) {
				$this->error = $this->db->lasterror();
				$this->db->rollback();
				return null;
			}
			if (isset($existing[$r['code']])) {
				$updated++;
			} else {
				$inserted++;
				$existing[$r['code']] = true;
				$nextId++;
			}
			if (++$batch >= 500) {
				$this->db->commit();
				$this->db->begin();
				$batch = 0;
			}
		}
		$this->db->commit();
		return array('read' => (int) $read, 'inserted' => $inserted, 'updated' => $updated, 'skipped' => max(0, (int) $read - count($rows)));
	}
}
