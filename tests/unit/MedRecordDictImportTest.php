<?php
/* Copyright (C) 2026 modMedRecord contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use PHPUnit\Framework\TestCase;

require_once dirname(dirname(__DIR__)).'/class/medrecorddictimport.class.php';

/** Pure parsing tests: no database. */
class MedRecordDictImportTest extends TestCase
{
	public function testIcd10NationalExportRow()
	{
		// Leading empty column, dagger main code, asterisk extra code (6位扩展代码表 shape)
		$r = MedRecordDictImport::parseRow(array('', 'A01.001+', 'K77.0*', '伤寒性肝炎'), 'icd10');
		$this->assertSame('A01.001', $r['code'], 'dagger stripped from the stored code');
		$this->assertSame('K77.0*', $r['extra_code']);
		$this->assertSame('伤寒性肝炎', $r['label']);

		$r = MedRecordDictImport::parseRow(array('', 'J00.x00', '', '急性鼻咽炎[感冒]'), 'icd10');
		$this->assertSame('J00.x00', $r['code']);
		$this->assertSame(null, $r['extra_code']);
	}

	public function testIcd10SupplementaryRowUsesExtraColumnAsCode()
	{
		// B95.000 appears only in the "extra" column in the national file
		$r = MedRecordDictImport::parseRow(array('', '', 'B95.000', 'A族链球菌感染'), 'icd10');
		$this->assertSame('B95.000', $r['code']);
		$this->assertSame(null, $r['extra_code']);
		$this->assertSame('A族链球菌感染', $r['label']);
	}

	public function testIcd10PlainCodeLabelAndHeaderSkipped()
	{
		$r = MedRecordDictImport::parseRow(array('J06.900', '急性上呼吸道感染'), 'icd10');
		$this->assertSame('J06.900', $r['code']);
		$this->assertSame(null, MedRecordDictImport::parseRow(array('主要编码', '附加编码', '疾病名称'), 'icd10'), 'header skipped');
		$this->assertSame(null, MedRecordDictImport::parseRow(array('', '6位扩展代码表', '', ''), 'icd10'), 'title row skipped');
		$this->assertSame(null, MedRecordDictImport::parseRow(array('', '', ''), 'icd10'), 'empty row skipped');
		$this->assertSame(null, MedRecordDictImport::parseRow(array('A01.001'), 'icd10'), 'code without label skipped');
	}

	public function testTcmRows()
	{
		$r = MedRecordDictImport::parseRow(array('ZH-001', '风寒袭表证'), 'tcm_syndrome');
		$this->assertSame('ZH-001', $r['code']);
		$this->assertSame('风寒袭表证', $r['label']);
		$this->assertSame(null, $r['pos']);

		$r = MedRecordDictImport::parseRow(array('感冒', 'BM-001', '10'), 'tcm_disease');
		$this->assertSame('BM-001', $r['code'], 'code found regardless of column order');
		$this->assertSame('感冒', $r['label']);
		$this->assertSame(10, $r['pos']);

		// GB-style code with dots
		$r = MedRecordDictImport::parseRow(array('A01.02.01', '风寒感冒'), 'tcm_disease');
		$this->assertSame('A01.02.01', $r['code']);
		$this->assertSame(null, MedRecordDictImport::parseRow(array('编码', '名称'), 'tcm_disease'), 'chinese header has no code');
	}

	public function testUtf8AndGbkDecoding()
	{
		$this->assertSame('a,b', MedRecordDictImport::toUtf8("\xEF\xBB\xBFa,b"), 'BOM stripped');
		$gbk = mb_convert_encoding('感冒', 'GB18030', 'UTF-8');
		$this->assertSame('感冒', MedRecordDictImport::toUtf8($gbk), 'GBK converted');
		$this->assertSame('感冒', MedRecordDictImport::toUtf8('感冒'), 'UTF-8 untouched');
	}

	public function testImporterIsUpsertOnly()
	{
		$src = file_get_contents(__DIR__.'/../../class/medrecorddictimport.class.php');
		$this->assertStringContainsString('ON DUPLICATE KEY UPDATE', $src, 'idempotent on the unique code');
		$this->assertStringNotContainsString('DELETE', $src, 'import never deletes dictionary rows');
		$this->assertStringContainsString('active = 1', $src, 're-import re-enables');
	}
}
