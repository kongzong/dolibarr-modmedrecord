<?php
/* Copyright (C) 2026 modMedRecord contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use PHPUnit\Framework\TestCase;

require_once dirname(dirname(__DIR__)).'/class/medrecordnumbering.class.php';

/** Scripted database: no concurrency here (tests/integration/numbering.php does that). */
class MedRecordNumberingTestDb
{
	public $type = 'mysqli';
	public $transaction_opened;
	public $queries = array();
	public $responses;
	public $rollbacks = 0;
	public $rollbackFails = false;
	public $closed = false;

	public function __construct($depth, $responses)
	{
		$this->transaction_opened = $depth;
		$this->responses = $responses;
	}
	public function prefix() { return 'isolated_'; }
	public function escape($value) { return addslashes($value); }
	public function query($sql)
	{
		$this->queries[] = $sql;
		if (!$this->responses) {
			throw new LogicException('Unexpected extra query');
		}
		$response = array_shift($this->responses);
		if ($response instanceof Throwable) {
			throw $response;
		}
		return is_array($response) ? (object) array('rows' => $response) : $response;
	}
	public function fetch_object($result) { return $result->rows ? (object) $result->rows[0] : false; }
	public function num_rows($result) { return count($result->rows); }
	public function free($result) {}
	public function rollback()
	{
		$this->rollbacks++;
		$this->transaction_opened--;
		return !$this->rollbackFails;
	}
	public function close() { $this->closed = true; $this->transaction_opened = 0; }
}

class MedRecordNumberingTest extends TestCase
{
	private function database($depth, $counter, $history)
	{
		$responses = array(
			$counter === null ? array() : array(array('last_value' => $counter)),
			array(array('maxseq' => $history)),
		);
		if ($depth > 0) {
			array_unshift($responses, true);
			$responses[] = true;
		}
		return new MedRecordNumberingTestDb($depth, $responses);
	}

	private function mustFail($db, $prefix = 'JZ-20260920-')
	{
		$thrown = null;
		try {
			(new MedRecordNumbering($db))->nextReference($prefix);
		} catch (RuntimeException $error) {
			$thrown = $error;
		}
		$this->assertTrue($thrown instanceof RuntimeException, 'failure must not return a reference');
		return $thrown;
	}

	public function testPrefixForDay()
	{
		$this->assertSame('JZ-20260920-', MedRecordNumbering::prefixFor(mktime(12, 0, 0, 9, 20, 2026)));
		$this->assertRegExp('/^JZ-[0-9]{8}-$/', MedRecordNumbering::prefixFor());
	}

	public function testFirstOfDayPreviewDoesNotReserve()
	{
		$db = $this->database(0, null, null);
		$this->assertSame('JZ-20260920-001', (new MedRecordNumbering($db))->nextReference('JZ-20260920-'));
		$this->assertCount(2, $db->queries);
		foreach ($db->queries as $sql) {
			$this->assertSame(0, strpos($sql, 'SELECT '));
			$this->assertStringNotContainsString('FOR UPDATE', $sql);
		}
	}

	public function testExistingHistorySeedsSequence()
	{
		$db = $this->database(1, '0', '41');
		$this->assertSame('JZ-20260920-042', (new MedRecordNumbering($db))->nextReference('JZ-20260920-'));
		$this->assertStringContainsString('last_value = 42', $db->queries[3]);
		$this->assertStringContainsString('isolated_medrecord WHERE ref LIKE', $db->queries[2], 'history from the record table');
	}

	public function testCommittedCounterPreventsReuse()
	{
		$db = $this->database(1, '57', '41');
		$this->assertSame('JZ-20260920-058', (new MedRecordNumbering($db))->nextReference('JZ-20260920-'));
	}

	public function testReservationKeepsOuterTransactionAndLocks()
	{
		$db = $this->database(3, '8', '8');
		$this->assertSame('JZ-20260920-009', (new MedRecordNumbering($db))->nextReference('JZ-20260920-'));
		$this->assertSame(3, $db->transaction_opened);
		$this->assertSame(0, $db->rollbacks);
		$this->assertStringContainsString('ON DUPLICATE KEY UPDATE', $db->queries[0]);
		$this->assertStringContainsString('FOR UPDATE', $db->queries[1]);
		$this->assertStringContainsString('FOR UPDATE', $db->queries[2]);
	}

	public function testSequenceBeyondThreeDigitsAndNewDay()
	{
		$db = $this->database(1, '999', '999');
		$this->assertSame('JZ-20260920-1000', (new MedRecordNumbering($db))->nextReference('JZ-20260920-'));
		$db = $this->database(1, '0', null);
		$this->assertSame('JZ-20260921-001', (new MedRecordNumbering($db))->nextReference('JZ-20260921-'));
	}

	public function testEverySqlFailureRollsBackAllLevels()
	{
		for ($stage = 0; $stage < 4; $stage++) {
			$db = $this->database(3, '0', null);
			$db->responses[$stage] = false;
			$this->mustFail($db);
			$this->assertSame(0, $db->transaction_opened);
			$this->assertSame(3, $db->rollbacks);
			$this->assertCount($stage + 1, $db->queries);
		}
	}

	public function testDriverExceptionDoesNotLeakSql()
	{
		$db = $this->database(1, '0', null);
		$db->responses[0] = new RuntimeException('private driver diagnostic');
		$error = $this->mustFail($db);
		$this->assertStringNotContainsString('private driver diagnostic', $error->getMessage());
	}

	public function testInvalidSequenceFailsClosed()
	{
		foreach (array('-1', '1.5', 'invalid', (string) PHP_INT_MAX) as $value) {
			$db = $this->database(1, '0', $value);
			$this->mustFail($db);
			$this->assertSame(0, $db->transaction_opened);
		}
	}

	public function testUnsupportedContextFailsBeforeQuerying()
	{
		$db = $this->database(0, null, null);
		$db->type = 'pgsql';
		$this->mustFail($db);
		$this->assertCount(0, $db->queries);
		$db = $this->database(0, null, null);
		$this->mustFail($db, 'HZ-202609-');
		$this->assertCount(0, $db->queries);
	}
}
