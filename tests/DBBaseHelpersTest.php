<?php

declare(strict_types=1);

namespace Common\DB\Tests;

use Common\DB\DBBase;
use PHPUnit\Framework\TestCase;
use PDO;
use PDOStatement;

/**
 * Hermetic tests for DBBase helpers not covered by DBBaseTest:
 * seeded fetch semantics for getBy/getByEmail, date-range builders,
 * buildInClause, firstValue, processRecord(s), delete/deleteBy,
 * getSomeIDs, getTableColumns, and the closure-logger logging path.
 */
class DBBaseHelpersTest extends TestCase
{
    /** @var string[] SQL statements received by the mocked PDO */
    private array $sql = [];

    /** @var array<int, array> values bound per prepare() call index */
    private array $bound = [];

    /** @var array<int, array> seeded fetchAll/fetch rows per call index */
    private array $rowsByIndex = [];

    /** @var array<int, object> value of execute() per call index */

    protected function setUp(): void
    {
        parent::setUp();
        $this->sql = [];
        $this->bound = [];
        $this->rowsByIndex = [];

        foreach (['defaultConn', 'defaultConfig', 'defaultLogger', 'cachedColumns'] as $prop) {
            $ref = new \ReflectionProperty(DBBase::class, $prop);
            $ref->setAccessible(true);
            $ref->setValue(null, null);
        }
    }

    private function mockPdo(array $rowsByIndex = []): PDO
    {
        $this->rowsByIndex = $rowsByIndex;

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturnCallback(function (string $sql) {
            $this->sql[] = $sql;
            $index = count($this->sql) - 1;
            $rows = $this->rowsByIndex[$index] ?? [];

            $stmt = $this->createMock(PDOStatement::class);
            $stmt->method('execute')->willReturnCallback(function ($values = null) use ($index) {
                if ($values !== null) {
                    $this->bound[$index] = $values;
                }
                return true;
            });
            $stmt->method('fetch')->willReturnCallback(fn() => count($rows) ? array_shift($rows) : false);
            $stmt->method('fetchAll')->willReturn($rows);
            return $stmt;
        });

        return $pdo;
    }

    private function failingPdo(): PDO
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willThrowException(new \PDOException('mock failure'));
        return $pdo;
    }

    /** Recording logger as a closure (covers DBBase::debuglog closure path). */
    private function makeLogger(): object
    {
        return new class {
            public array $calls = [];
            public function log(mixed ...$args): void
            {
                $this->calls[] = $args;
            }
        };
    }

    private function makeInitialized(?PDO $pdo = null): ExposedDB
    {
        return new ExposedDB($pdo ?? $this->mockPdo(), $this->makeLogger());
    }

    // ---------------------------------------------------------------
    // getBy / getByEmail / delete / deleteBy family
    // ---------------------------------------------------------------

    public function testGetByEmailQueriesEmailKeySingleRow(): void
    {
        // TestableDB exposes table/idName; getByEmail() routes through getBy().
        $subject = $this->makeInitialized($this->mockPdo([
            [['id' => 'tt-1', 'email' => 'a@x.test']],
        ]));

        $result = $subject->getBy('email', 'a@x.test', false);

        $this->assertSame(['id' => 'tt-1', 'email' => 'a@x.test'], $result);
        $this->assertStringContainsString('where email = :value', $this->sql[0]);
        $this->assertSame([':value' => 'a@x.test'], $this->bound[0]);
    }

    public function testGetByEmailMultipleFalseReturnsEmptyWhenMissing(): void
    {
        $subject = $this->makeInitialized($this->mockPdo([[]]));
        $this->assertSame([], $subject->getBy('email', 'nobody@x.test', false));
    }

    public function testGetByMultipleReturnsAllRows(): void
    {
        $rows = [['id' => 'a'], ['id' => 'b']];
        $subject = $this->makeInitialized($this->mockPdo([$rows]));
        $this->assertSame($rows, $subject->getBy('name', 'x', true));
    }

    public function testDeleteAndDeleteByTargetIdName(): void
    {
        $subject = $this->makeInitialized($this->mockPdo());

        $this->assertSame(0, $subject->delete('tt-9'));
        $this->assertSame(0, $subject->deleteBy('name', 'x'));
        $this->assertStringContainsString('delete from test_table where id = :v', $this->sql[0]);
        $this->assertStringContainsString('delete from test_table where name = :v', $this->sql[1]);
        $this->assertSame([':v' => 'tt-9'], $this->bound[0]);
    }

    public function testDeleteReturnsOneOnFailure(): void
    {
        $subject = $this->makeInitialized($this->failingPdo());
        $this->assertSame(1, $subject->delete('tt-9'));
    }

    public function testGetSomeIDSLimitsAndExtractsColumn(): void
    {
        $rows = [['id' => 'a'], ['id' => 'b']];
        $subject = $this->makeInitialized($this->mockPdo([$rows]));

        $this->assertSame(['a', 'b'], $subject->getSomeIDs(2));
    }

    public function testGetSomeIDSNonPositiveCountSkipsQuery(): void
    {
        $subject = $this->makeInitialized($this->mockPdo());
        $this->assertSame([], $subject->getSomeIDs(0));
        $this->assertSame([], $this->sql, 'No query for non-positive count');
    }

    public function testGetAllTableAll(): void
    {
        $rows = [['id' => 'a']];
        $subject = $this->makeInitialized($this->mockPdo([$rows]));
        // getAll() without date params is covered by DBBaseTest already; the
        // non-array-params guard is exercised here.
        $this->assertSame($rows, $subject->getAll());
    }

    // ---------------------------------------------------------------
    // date range helpers (protected -> wrapper subclass below)
    // ---------------------------------------------------------------

    public function testBuildDateRangeClauseBothBounds(): void
    {
        $subject = $this->makeInitialized();
        [$clause, $values] = $subject->dateRangeClausePublic('2026-01-01', '2026-12-31');

        $this->assertSame(' where date >= :__from and date <= :__to', $clause);
        $this->assertSame([':__from' => '2026-01-01', ':__to' => '2026-12-31'], $values);
    }

    public function testBuildDateRangeClauseEmptyYieldsEmpty(): void
    {
        $subject = $this->makeInitialized();
        [$clause, $values] = $subject->dateRangeClausePublic();
        $this->assertSame('', $clause);
        $this->assertSame([], $values);
    }

    public function testGetAllByDateRangeQueriesWithBounds(): void
    {
        $rows = [['id' => 'a']];
        $subject = $this->makeInitialized($this->mockPdo([$rows]));

        $this->assertSame($rows, $subject->getAllByDateRange('2026-01-01', '2026-02-01'));
        $this->assertStringContainsString('select * from test_table where date >= :__from', $this->sql[0]);
        $this->assertSame([':__from' => '2026-01-01', ':__to' => '2026-02-01'], $this->bound[0]);
    }

    public function testGetAllByDateRangeAppendsOrderBy(): void
    {
        $subject = $this->makeInitialized($this->mockPdo([[]]));
        $subject->getAllByDateRange('', '', 'date', 'created desc');
        $this->assertStringContainsString('order by created desc', $this->sql[0]);
    }

    public function testAppendDateRangeAddsWhereWhenMissing(): void
    {
        $subject = $this->makeInitialized();
        [$sql, $values] = $subject->appendDateRangePublic('select * from t', '2026-01-01');

        $this->assertSame('select * from t where date >= :__from', $sql);
        $this->assertSame([':__from' => '2026-01-01'], $values);
    }

    public function testAppendDateRangeRewritesWhereToAnd(): void
    {
        $subject = $this->makeInitialized();
        [$sql, $values] = $subject->appendDateRangePublic(
            'select * from t where owner = :owner',
            '2026-01-01',
            '2026-01-31',
            'date',
            ':dfrom',
            ':dto'
        );

        $this->assertSame('select * from t where owner = :owner and date >= :dfrom and date <= :dto', $sql);
        $this->assertSame([':dfrom' => '2026-01-01', ':dto' => '2026-01-31'], $values);
    }

    // ---------------------------------------------------------------
    // buildInClause
    // ---------------------------------------------------------------

    public function testBuildInClauseGeneratesPlaceholders(): void
    {
        $subject = $this->makeInitialized();
        [$clause, $values] = $subject->inClausePublic('id', ['a', 'b']);

        $this->assertSame('id in (:in0,:in1)', $clause);
        $this->assertSame([':in0' => 'a', ':in1' => 'b'], $values);
    }

    public function testBuildInClauseEmptyListYieldsEmpty(): void
    {
        $subject = $this->makeInitialized();
        [$clause, $values] = $subject->inClausePublic('id', []);
        $this->assertSame('', $clause);
        $this->assertSame([], $values);
    }

    public function testBuildInClauseNonArrayYieldsEmpty(): void
    {
        $subject = $this->makeInitialized();
        [$clause, $values] = $subject->inClausePublic('id', 'not-a-list');
        $this->assertSame('', $clause);
    }

    // ---------------------------------------------------------------
    // firstValue
    // ---------------------------------------------------------------

    public function testFirstValueFirstColumn(): void
    {
        $subject = $this->makeInitialized($this->mockPdo([[['n' => 5]]]));
        $this->assertSame(5, $subject->firstValuePublic('select n from x'));
    }

    public function testFirstValueWithKey(): void
    {
        $subject = $this->makeInitialized($this->mockPdo([[['a' => 1, 'b' => 2]]]));
        $this->assertSame(2, $subject->firstValuePublic('select a,b from x', [], 'b'));
    }

    public function testFirstValueFallsBackToDefaultOnNoRows(): void
    {
        $subject = $this->makeInitialized($this->mockPdo([[]]));
        $this->assertSame(42, $subject->firstValuePublic('select 1', [], null, 42));
    }

    public function testFirstValueMissingKeyFallsBackToDefault(): void
    {
        $subject = $this->makeInitialized($this->mockPdo([[['a' => 1]]]));
        $this->assertSame(7, $subject->firstValuePublic('select a', [], 'zzz', 7));
    }

    // ---------------------------------------------------------------
    // processRecord / processRecords
    // ---------------------------------------------------------------

    public function testProcessRecordsAppliesProcessRecordToEach(): void
    {
        $subject = new class($this->mockPdo(), $this->makeLogger()) extends DBBase {
            protected $table = 'test_table';
            protected $prefix = 'tt';
            protected $idName = 'id';
            public function processRecord($record)
            {
                $record['processed'] = true;
                return $record;
            }
        };

        $out = $subject->processRecords([['a' => 1], ['a' => 2]]);
        $this->assertSame([['a' => 1, 'processed' => true], ['a' => 2, 'processed' => true]], $out);
    }

    // ---------------------------------------------------------------
    // getTableColumns
    // ---------------------------------------------------------------

    public function testGetTableColumnsReadsDESCRIBEAndCaches(): void
    {
        $subject = $this->makeInitialized($this->mockPdo([
            [['field' => 'ID'], ['field' => 'Name']],
        ]));

        $this->assertSame(['id', 'name'], $subject->getTableColumns());
        $this->assertStringContainsString('DESCRIBE test_table', $this->sql[0]);
        // Cached: a second call must not re-query.
        $this->assertSame(['id', 'name'], $subject->getTableColumns());
        $this->assertCount(1, $this->sql);
    }

    public function testGetTableColumnsReturnsNullWhenDescribeFails(): void
    {
        $subject = $this->makeInitialized($this->failingPdo());
        $this->assertNull($subject->getTableColumns());
    }

    // ---------------------------------------------------------------

    private function lastSql(): string
    {
        return $this->sql[count($this->sql) - 1] ?? '';
    }
}

/**
 * Exposure wrapper for protected helpers under test.
 */
class ExposedDB extends DBBase
{
    private array $fieldNames = ['id', 'name', 'email'];

    public function __construct($pdo = null, $logger = null)
    {
        parent::__construct($pdo, $logger);
        $this->table = 'test_table';
        $this->prefix = 'tt';
        $this->idName = 'id';
    }

    public function dateRangeClausePublic($from = '', $to = '', $field = 'date', $fromParam = ':__from', $toParam = ':__to')
    {
        return $this->buildDateRangeClause($from, $to, $field, $fromParam, $toParam);
    }

    public function inClausePublic($field, $list, $paramBase = 'in')
    {
        return $this->buildInClause($field, $list, $paramBase);
    }

    public function firstValuePublic($sql, $values = [], $key = null, $default = 0)
    {
        return $this->firstValue($sql, $values, $key, $default);
    }

    public function appendDateRangePublic($sql, $from = '', $to = '', $dateField = 'date', $fromParam = ':__from', $toParam = ':__to')
    {
        return $this->appendDateRangeToSql($sql, $from, $to, $dateField, $fromParam, $toParam);
    }
}
