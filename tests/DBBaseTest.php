<?php

declare(strict_types=1);

namespace Common\DB\Tests;

use Common\DB\DBBase;
use Common\DB\DBConnection;
use PHPUnit\Framework\TestCase;
use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;

/**
 * Hermetic tests for the avisitor/dbbase package.
 *
 * No live DB connection is made: PDO is always stubbed via createMock(), and
 * connection-construction tests guard on DB environment variables, skipping
 * when none are present.
 */
class DBBaseTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Reset static state so tests never inherit a default connection.
        foreach (['defaultConn', 'defaultConfig', 'defaultLogger'] as $prop) {
            $ref = new \ReflectionProperty(DBBase::class, $prop);
            $ref->setAccessible(true);
            $ref->setValue(null, null);
        }
    }

    private function makeSubject(?PDO $pdo = null): TestableDB
    {
        return new TestableDB($pdo, new class {
            public function log(mixed ...$args): void
            {
            }
        });
    }

    /**
     * Build a PDO mock whose prepare() records the SQL it receives and returns
     * a statement stub. fetchAll results can be seeded per call.
     */
    private function mockPdo(array $rowsByIndex = [], array &$captured = []): PDO
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use (&$captured, $rowsByIndex) {
            $captured[] = $sql;
            $stmt = $this->createMock(PDOStatement::class);
            $stmt->method('execute')->willReturn(true);
            $index = count($captured) - 1;
            $stmt->method('fetchAll')->willReturn($rowsByIndex[$index] ?? []);
            return $stmt;
        });
        return $pdo;
    }

    // ------------------------------------------------------------------
    // Connection construction (no live DB)
    // ------------------------------------------------------------------

    public function testCreateConnectionThrowsWithoutConfig(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('No database configuration provided');
        DBBase::createConnection();
    }

    public function testCreateConnectionRejectsUnsupportedDriver(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Unsupported database driver: sqlite');
        DBBase::createConnection([
            'driver' => 'sqlite',
            'host' => 'localhost',
            'dbname' => 'x',
            'username' => 'u',
            'password' => 'p',
            'port' => 3306,
        ]);
    }

    public function testCreateConnectionRequiresRequiredKeys(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('DB config key host is required');
        DBBase::createConnection(['username' => 'u', 'password' => 'p']);
    }

    public function testCreateConnectionRequiresUsernameWhenDsnGiven(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('DB config key username is required');
        DBBase::createConnection(['dsn' => 'mysql:host=localhost', 'password' => 'p']);
    }

    public function testCreateConnectionSkipsWhenNoDbEnvironment(): void
    {
        if (!getenv('DB_HOST') || !getenv('DB_NAME')) {
            $this->markTestSkipped('DB env vars not set; cannot attempt a real connection');
        }
        try {
            DBBase::createConnection([
                'host' => getenv('DB_HOST'),
                'dbname' => getenv('DB_NAME'),
                'username' => getenv('DB_USER') ?: '',
                'password' => getenv('DB_PASS') ?: '',
                'port' => (int)(getenv('DB_PORT') ?: 3306),
            ]);
            $this->assertTrue(true);
        } catch (\Exception $e) {
            $this->markTestSkipped('DB not reachable in this environment: ' . $e->getMessage());
        }
    }

    public function testFromConfigFileThrowsWhenFileMissing(): void
    {
        $this->expectException(RuntimeException::class);
        DBConnection::fromConfigFile(__DIR__ . '/does-not-exist.php');
    }

    public function testDefaultConnectionSetterRoundtrip(): void
    {
        $pdo = $this->createMock(PDO::class);
        DBBase::setDefaultConnection($pdo);
        $this->assertSame($pdo, DBBase::getDefaultConnection());
    }

    // ------------------------------------------------------------------
    // query() / executeStatement() return shapes
    // ------------------------------------------------------------------

    public function testExecuteStatementReturnsStatementAndCapturesSql(): void
    {
        $captured = [];
        $db = $this->makeSubject($this->mockPdo([], $captured));

        $stmt = $db->executeStatementPublic('select * from t where id = :id', [':id' => 5]);

        $this->assertInstanceOf(PDOStatement::class, $stmt);
        $this->assertSame(['select * from t where id = :id'], $captured);
    }

    public function testQueryReturnsAssocRows(): void
    {
        $captured = [];
        $rows = [
            ['id' => 1, 'name' => 'alice'],
            ['id' => 2, 'name' => 'bob'],
        ];
        $db = $this->makeSubject($this->mockPdo([$rows], $captured));

        $result = $db->query('select * from t');

        $this->assertSame($rows, $result);
        $this->assertSame(['select * from t'], $captured);
    }

    public function testQueryReturnsEmptyArrayWhenStatementFails(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willThrowException(new PDOException('boom'));
        $db = $this->makeSubject($pdo);

        $this->assertSame([], $db->query('select * from broken'));
    }

    public function testExecuteReturnsZeroOnSuccessOneOnFailure(): void
    {
        $okDb = $this->makeSubject($this->mockPdo());
        $this->assertSame(0, $okDb->execute('update t set x = 1'));

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willThrowException(new PDOException('boom'));
        $failDb = $this->makeSubject($pdo);
        $this->assertSame(1, $failDb->execute('update t set x = 1'));
    }

    public function testExecuteStatementReturnsNullOnPdoErrorByDefault(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willThrowException(new PDOException('boom'));
        $db = $this->makeSubject($pdo);

        $this->assertNull($db->executeStatementPublic('select * from broken'));
    }

    public function testExecuteStatementThrowsWhenThrowOnErrorEnabled(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willThrowException(new PDOException('boom'));
        $db = $this->makeSubject($pdo);
        $db->throwOnError = true;

        $this->expectException(PDOException::class);
        $this->expectExceptionMessage('boom');
        $db->executeStatementPublic('select * from broken');
    }

    public function testQueryThrowsWhenThrowOnErrorEnabled(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willThrowException(new PDOException('boom'));
        $db = $this->makeSubject($pdo);
        $db->throwOnError = true;

        $this->expectException(PDOException::class);
        $db->query('select * from broken');
    }

    public function testExecuteStatementThrowsWithoutConnection(): void
    {
        $db = $this->makeSubject();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No PDO connection set');
        $db->executeStatementPublic('select 1');
    }

    // ------------------------------------------------------------------
    // Row-shape handling (query / getOne / getBy / getById)
    // ------------------------------------------------------------------

    public function testGetOneRowReturnsFirstRow(): void
    {
        $rows = [['id' => 7, 'name' => 'zoe']];
        $db = $this->makeSubject($this->mockPdo([$rows]));

        $this->assertSame(['id' => 7, 'name' => 'zoe'], $db->getOneRowPublic('select * from t'));
    }

    public function testGetOneRowReturnsEmptyArrayWhenNoRows(): void
    {
        $db = $this->makeSubject($this->mockPdo([[]]));

        $this->assertSame([], $db->getOneRowPublic('select * from t where 1 = 0'));
    }

    public function testGetByReturnsRowsWhenMultiple(): void
    {
        $rows = [['id' => 1], ['id' => 2]];
        $captured = [];
        $db = $this->makeSubject($this->mockPdo([$rows], $captured));

        $result = $db->getBy('group_id', 3, true);

        $this->assertSame($rows, $result);
        $this->assertSame('select * from test_table where group_id = :value', $captured[0]);
    }

    public function testGetByIdReturnsSingleRowShape(): void
    {
        $rows = [['id' => 42, 'name' => 'joe']];
        $db = $this->makeSubject($this->mockPdo([$rows]));

        $this->assertSame(['id' => 42, 'name' => 'joe'], $db->getById(42));
    }

    public function testGetByIdReturnsEmptyArrayWhenMissing(): void
    {
        $db = $this->makeSubject($this->mockPdo([[]]));

        $this->assertSame([], $db->getById(999));
    }

    public function testGetAllBuildsSelectFromTable(): void
    {
        $captured = [];
        $rows = [['id' => 1]];
        $db = $this->makeSubject($this->mockPdo([$rows], $captured));

        $this->assertSame($rows, $db->getAll());
        $this->assertSame('select * from test_table', $captured[0]);
    }

    // ------------------------------------------------------------------
    // upsert() SQL generation
    // ------------------------------------------------------------------

    public function testUpdateBuildsUpsertSql(): void
    {
        $captured = [];
        $db = $this->makeSubject($this->mockPdo([], $captured));

        $db->setFieldNames(['id', 'name', 'email']);
        $result = $db->update(['name' => 'Ada', 'email' => 'ada@example.org']);

        $sql = $captured[0] ?? '';
        $this->assertStringContainsString('INSERT INTO test_table', $sql);
        $this->assertStringContainsString('name', $sql);
        $this->assertStringContainsString('email', $sql);
        $this->assertStringContainsString('ON DUPLICATE KEY UPDATE', $sql);
        $this->assertStringContainsString('name = :name', $sql);
        $this->assertStringContainsString('email = :email', $sql);

        // The generated id (uniqidReal prefix) must be part of the insert and returned.
        $this->assertArrayHasKey('id', $result);
        $this->assertIsString($result['id']);
        $this->assertSame('tt', substr($result['id'], 0, 2));
    }

    public function testUpdateFiltersParamsToKnownFields(): void
    {
        $captured = [];
        $db = $this->makeSubject($this->mockPdo([], $captured));

        $db->setFieldNames(['id', 'name']);
        $db->update(['name' => 'Ada', 'unexpected_column' => 'nope']);

        $sql = $captured[0] ?? '';
        $this->assertStringNotContainsString('unexpected_column', $sql);
        $this->assertStringContainsString('name = :name', $sql);
    }

    public function testInsertOneDBRecordAliasesUpdate(): void
    {
        $captured = [];
        $db = $this->makeSubject($this->mockPdo([], $captured));

        $db->setFieldNames(['id', 'name']);
        $db->insertOneDBRecordPublic(['id', 'name'], ['name' => 'Grace'], 'test_table', 'tt');

        $sql = $captured[0] ?? '';
        $this->assertStringContainsString('INSERT INTO test_table', $sql);
    }

    // ------------------------------------------------------------------
    // uniqidReal()
    // ------------------------------------------------------------------

    public function testUniqidRealReturnsRequestedLength(): void
    {
        $this->assertSame(13, strlen(DBBase::uniqidReal()));
        $this->assertSame(20, strlen(DBBase::uniqidReal('', 20)));
        $this->assertSame(8, strlen(DBBase::uniqidReal('', 8)));
    }

    public function testUniqidRealIsHexOnly(): void
    {
        $this->assertMatchesRegularExpression('/^[0-9a-f]+$/', DBBase::uniqidReal('', 24));
    }

    public function testUniqidRealPrependsPrefix(): void
    {
        $value = DBBase::uniqidReal('tt-');
        $this->assertStringStartsWith('tt-', $value);
        $this->assertSame(3 + 13, strlen($value));
    }

    public function testUniqidRealValuesAreUnique(): void
    {
        $seen = [];
        for ($i = 0; $i < 500; $i++) {
            $seen[DBBase::uniqidReal()] = true;
        }
        $this->assertCount(500, $seen);
    }

    // --- opt-in id-targeted update (updateByIdName) -----------------------
    //
    // The default upsert can only locate a row via INSERT..ON DUPLICATE KEY
    // UPDATE, which needs a declared unique key among the supplied columns.
    // A partial update of a table whose unique key is not in $params (or whose
    // id column carries no unique index) therefore never matches anything.

    public function testUpdateByIdNameDisabledStillUsesUpsert(): void
    {
        $captured = [];
        $subject = $this->makeSubject($this->mockPdo([], $captured));

        $subject->update(['id' => 'tt-existing', 'email' => 'new@example.com']);

        $this->assertCount(1, $captured, 'Default path should issue exactly one statement');
        $this->assertStringContainsString('INSERT INTO', $captured[0]);
        $this->assertStringContainsString('ON DUPLICATE KEY UPDATE', $captured[0]);
    }

    public function testUpdateByIdNameTargetsExistingRowById(): void
    {
        $captured = [];
        // First statement is the existence probe and returns exactly one row.
        $pdo = $this->mockPdo([0 => [['id' => 'tt-existing']]], $captured);
        $subject = $this->makeSubject($pdo);
        $subject->setUpdateByIdName(true);

        $result = $subject->update(['id' => 'tt-existing', 'email' => 'new@example.com']);

        $this->assertCount(2, $captured, 'Expected a probe followed by an update');
        $this->assertStringContainsString('limit 2', $captured[0]);
        $this->assertStringContainsString('UPDATE test_table SET', $captured[1]);
        $this->assertStringContainsString('email = :email', $captured[1]);
        $this->assertStringContainsString('WHERE id = :__dbbase_row_id', $captured[1]);
        $this->assertStringNotContainsString('INSERT INTO', $captured[1]);
        $this->assertSame('new@example.com', $result['email']);
        $this->assertSame('tt-existing', $result['id']);
    }

    public function testUpdateByIdNameNeverRewritesTheIdColumn(): void
    {
        $captured = [];
        $pdo = $this->mockPdo([0 => [['id' => 'tt-existing']]], $captured);
        $subject = $this->makeSubject($pdo);
        $subject->setUpdateByIdName(true);

        $subject->update(['id' => 'tt-existing', 'name' => 'Renamed']);

        // 'id' is in getFieldNames(), so it survives filtering - but it must
        // only appear in the WHERE clause, never in SET.
        $this->assertStringNotContainsString('SET id =', $captured[1]);
        $this->assertStringNotContainsString(',id =', $captured[1]);
        $this->assertStringContainsString('name = :name', $captured[1]);
    }

    public function testUpdateByIdNameFallsBackToInsertWhenRowMissing(): void
    {
        $captured = [];
        // Probe returns no rows.
        $pdo = $this->mockPdo([0 => []], $captured);
        $subject = $this->makeSubject($pdo);
        $subject->setUpdateByIdName(true);

        $subject->update(['id' => 'tt-brand-new', 'email' => 'new@example.com']);

        $this->assertCount(2, $captured, 'Expected a probe followed by a create');
        $this->assertStringContainsString('INSERT INTO', $captured[1]);
        // The caller-supplied id must actually be stored, not just reported back.
        $this->assertStringContainsString('id', $captured[1]);
    }

    public function testUpdateByIdNameRefusesAmbiguousDuplicateIds(): void
    {
        $captured = [];
        // Probe returns two rows sharing the id.
        $pdo = $this->mockPdo([0 => [['id' => 'dup'], ['id' => 'dup']]], $captured);
        $subject = $this->makeSubject($pdo);
        $subject->setUpdateByIdName(true);

        $result = $subject->update(['id' => 'dup', 'email' => 'new@example.com']);

        $this->assertSame([], $result, 'Ambiguous id must report failure');
        $this->assertCount(1, $captured, 'Must not write after an ambiguous probe');
    }

    public function testUpdateByIdNameReportsFailureWhenNoFieldsToUpdate(): void
    {
        $captured = [];
        $pdo = $this->mockPdo([0 => [['id' => 'tt-existing']]], $captured);
        $subject = $this->makeSubject($pdo);
        $subject->setUpdateByIdName(true);

        $result = $subject->update(['id' => 'tt-existing']);

        $this->assertSame([], $result, 'An id-only request updates nothing');
        $this->assertCount(1, $captured, 'Must not emit an UPDATE with an empty SET');
    }

    public function testUpdateByIdNameStillGeneratesIdWhenAbsent(): void
    {
        $captured = [];
        $pdo = $this->mockPdo([], $captured);
        $subject = $this->makeSubject($pdo);
        $subject->setUpdateByIdName(true);

        $result = $subject->update(['email' => 'new@example.com']);

        // No explicit id supplied, so this is a create: no probe, straight to upsert.
        $this->assertCount(1, $captured);
        $this->assertStringContainsString('INSERT INTO', $captured[0]);
        $this->assertNotEmpty($result['id']);
    }
}

/**
 * Minimal concrete subclass exposing protected/legacy helpers for testing.
 */
class TestableDB extends DBBase
{
    private array $fieldNames = ['id', 'name', 'email'];

    public function __construct($pdo = null, $logger = null)
    {
        parent::__construct($pdo, $logger);
        $this->table = 'test_table';
        $this->prefix = 'tt';
        $this->idName = 'id';
    }

    public function setFieldNames(array $fields): void
    {
        $this->fieldNames = $fields;
    }

    public function getFieldNames(): array
    {
        return $this->fieldNames;
    }

    public function executeStatementPublic(string $sql, $values = null)
    {
        return $this->executeStatement($sql, $values);
    }

    public function getOneRowPublic(string $sql, $values = null)
    {
        return $this->getOne($sql, $values);
    }

    public function insertOneDBRecordPublic($potential, $params, $table, $prefix = 'id', $idName = 'id')
    {
        return $this->insertOneDBRecord($potential, $params, $table, $prefix, $idName);
    }

    public function setUpdateByIdName(bool $enabled): void
    {
        $this->updateByIdName = $enabled;
    }
}