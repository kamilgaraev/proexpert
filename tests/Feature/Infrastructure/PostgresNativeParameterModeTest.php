<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\StatementPrepared;
use Illuminate\Database\PostgresConnection;
use Illuminate\Events\Dispatcher;
use PDO;
use PDOException;
use PDOStatement;
use Pdo\Pgsql;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PostgresNativeParameterModeTest extends TestCase
{
    public static function parameterModes(): iterable
    {
        yield 'named prepare' => [false, false];
        yield 'connection one shot' => [true, false];
        yield 'statement one shot' => [false, true];
    }

    public static function connectionModes(): iterable
    {
        yield 'named prepare' => [false];
        yield 'connection one shot' => [true];
    }

    #[DataProvider('connectionModes')]
    public function test_laravel_read_write_callbacks_cursor_and_results_are_preserved(bool $disablePrepares): void
    {
        $writePdo = $this->connection($disablePrepares);
        $readPdo = $this->connection($disablePrepares);
        $writePid = $writePdo->query('SELECT pg_backend_pid()')->fetchColumn();
        $readPid = $readPdo->query('SELECT pg_backend_pid()')->fetchColumn();
        self::assertNotSame($writePid, $readPid);
        $writePdo->exec('CREATE TEMPORARY TABLE laravel_parameter_rows (id integer PRIMARY KEY, enabled boolean NOT NULL)');
        $connection = new PostgresConnection($writePdo, (string) getenv('DB_DATABASE'), '', ['name' => 'native_parameter_mode', 'driver' => 'pgsql']);
        $connection->setReadPdo($readPdo);
        $dispatcher = new Dispatcher;
        $connection->setEventDispatcher($dispatcher);
        $before = [];
        $executed = [];
        $prepared = 0;
        $connection->beforeExecuting(static function (string $sql, array $bindings, PostgresConnection $actual) use (&$before, $connection): void {
            self::assertSame($connection, $actual);
            $before[] = [$sql, $bindings];
        });
        $dispatcher->listen(StatementPrepared::class, static function (StatementPrepared $event) use (&$prepared, $connection): void {
            self::assertSame($connection, $event->connection);
            self::assertInstanceOf(PDOStatement::class, $event->statement);
            $prepared++;
        });
        $dispatcher->listen(QueryExecuted::class, static function (QueryExecuted $event) use (&$executed, $connection): void {
            self::assertSame($connection, $event->connection);
            $executed[] = [$event->sql, $event->bindings];
        });

        self::assertSame($readPid, $connection->selectOne('SELECT pg_backend_pid() AS pid')->pid);
        self::assertSame($writePid, $connection->selectOne('SELECT pg_backend_pid() AS pid', [], false)->pid);
        $typed = $connection->selectOne('SELECT CAST(? AS boolean) AS enabled, CAST(? AS text) AS missing', [true, null]);
        self::assertTrue($typed->enabled);
        self::assertNull($typed->missing);
        self::assertTrue($connection->statement('INSERT INTO laravel_parameter_rows (id, enabled) VALUES (?, ?)', [1, true]));
        self::assertSame(1, $connection->affectingStatement('UPDATE laravel_parameter_rows SET enabled = ? WHERE id = ?', [false, 1]));
        self::assertFalse($connection->selectOne('SELECT enabled FROM laravel_parameter_rows WHERE id = ?', [1], false)->enabled);

        $cursor = $connection->cursor('SELECT generate_series(CAST(? AS integer), CAST(? AS integer)) AS value', [2, 5]);
        foreach ($cursor as $row) {
            self::assertSame(2, $row->value);
            break;
        }
        self::assertSame(11, $connection->selectOne('SELECT CAST(? AS integer) AS value', [11])->value);
        unset($cursor);
        self::assertSame($before, $executed);
        self::assertCount(8, $executed);
        self::assertSame(6, $prepared);
        self::assertFalse($readPdo->getAttribute(PDO::ATTR_EMULATE_PREPARES));
        self::assertFalse($writePdo->getAttribute(PDO::ATTR_EMULATE_PREPARES));
    }

    #[DataProvider('parameterModes')]
    public function test_typed_parameters_and_postgres_literals_keep_their_values(bool $connectionMode, bool $statementMode): void
    {
        $connection = $this->connection($connectionMode);
        $statement = $this->prepare($connection, <<<'SQL'
SELECT CAST(:number AS integer) AS number,
       CAST(:enabled AS boolean) AS enabled,
       CAST(:disabled AS boolean) AS disabled,
       CAST(:missing AS text) AS missing,
       CAST(:text AS text) AS text,
       CAST(:json AS jsonb)->>'key' AS json_value,
       encode(CAST(:binary AS bytea), 'hex') AS binary_value
SQL, $statementMode);
        $text = "Тест ' ? :number $1";
        $binary = "\x00\x01\xff'\\";
        $statement->bindValue(':number', 42, PDO::PARAM_INT);
        $statement->bindValue(':enabled', true, PDO::PARAM_BOOL);
        $statement->bindValue(':disabled', false, PDO::PARAM_BOOL);
        $statement->bindValue(':missing', null, PDO::PARAM_NULL);
        $statement->bindValue(':text', $text, PDO::PARAM_STR);
        $statement->bindValue(':json', '{"key":"native"}', PDO::PARAM_STR);
        $statement->bindValue(':binary', $binary, PDO::PARAM_LOB);
        self::assertTrue($statement->execute());
        self::assertSame([
            'number' => 42,
            'enabled' => true,
            'disabled' => false,
            'missing' => null,
            'text' => $text,
            'json_value' => 'native',
            'binary_value' => bin2hex($binary),
        ], $statement->fetch(PDO::FETCH_ASSOC));
        self::assertFalse($statement->fetch(PDO::FETCH_ASSOC));

        $operators = $this->prepare($connection, <<<'SQL'
SELECT CAST(? AS jsonb) ?? 'key' AS has_key,
       'literal ? :name' AS quoted,
       $$dollar ? :name$$ AS dollar_quoted
SQL, $statementMode);
        self::assertTrue($operators->execute(['{"key":1}']));
        self::assertSame([
            'has_key' => true,
            'quoted' => 'literal ? :name',
            'dollar_quoted' => 'dollar ? :name',
        ], $operators->fetch(PDO::FETCH_ASSOC));

        $repeated = $this->prepare($connection, 'SELECT CAST(:value AS integer) + CAST(:value AS integer)', $statementMode);
        self::assertTrue($repeated->execute(['value' => 9]));
        self::assertSame(18, $repeated->fetchColumn());
    }

    #[DataProvider('parameterModes')]
    public function test_repeated_execute_returning_fetch_modes_and_interleaving_work(bool $connectionMode, bool $statementMode): void
    {
        $connection = $this->connection($connectionMode);
        $connection->exec('CREATE TEMPORARY TABLE native_parameter_rows (id integer PRIMARY KEY, label text NOT NULL)');
        $insert = $this->prepare($connection, 'INSERT INTO native_parameter_rows (id, label) VALUES (?, ?) RETURNING id, label', $statementMode);
        foreach ([1 => 'first', 2 => 'second', 3 => 'third'] as $id => $label) {
            self::assertTrue($insert->execute([$id, $label]));
            self::assertSame(1, $insert->rowCount());
            self::assertSame(['id' => $id, 'label' => $label], $insert->fetch(PDO::FETCH_ASSOC));
            self::assertTrue($insert->closeCursor());
        }

        $select = $this->prepare($connection, 'SELECT id, label FROM native_parameter_rows WHERE id >= ? ORDER BY id', $statementMode);
        self::assertTrue($select->execute([1]));
        self::assertSame([1, 'first'], $select->fetch(PDO::FETCH_NUM));
        self::assertSame(3, $connection->query('SELECT count(*) FROM native_parameter_rows')->fetchColumn());
        self::assertSame(['id' => 2, 'label' => 'second'], $select->fetch(PDO::FETCH_ASSOC));
        self::assertTrue($select->closeCursor());
        self::assertTrue($select->execute([3]));
        $row = $select->fetch(PDO::FETCH_OBJ);
        self::assertIsObject($row);
        self::assertSame(3, $row->id);
        self::assertSame('third', $row->label);

        $update = $this->prepare($connection, 'UPDATE native_parameter_rows SET label = ? WHERE id >= ?', $statementMode);
        self::assertTrue($update->execute(['updated', 2]));
        self::assertSame(2, $update->rowCount());
        self::assertSame([1 => 'first', 2 => 'updated', 3 => 'updated'], $connection->query('SELECT id, label FROM native_parameter_rows ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR));
    }

    #[DataProvider('parameterModes')]
    public function test_server_errors_preserve_savepoint_recovery_and_statement_reuse(bool $connectionMode, bool $statementMode): void
    {
        $connection = $this->connection($connectionMode);
        $connection->beginTransaction();
        try {
            $connection->exec('SAVEPOINT parameter_error');
            $statement = $this->prepare($connection, 'SELECT 12 / CAST(? AS integer)', $statementMode);
            try {
                $statement->execute([0]);
                self::fail('Division by zero must fail');
            } catch (PDOException $exception) {
                self::assertSame('22012', $exception->getCode());
            }
            $connection->exec('ROLLBACK TO SAVEPOINT parameter_error');
            self::assertTrue($statement->execute([3]));
            self::assertSame(4, $statement->fetchColumn());
            self::assertTrue($connection->inTransaction());
        } finally {
            $connection->rollBack();
        }
        self::assertSame(1, $connection->query('SELECT 1')->fetchColumn());
    }

    #[DataProvider('parameterModes')]
    public function test_statement_timeout_remains_enforced_and_transaction_local(bool $connectionMode, bool $statementMode): void
    {
        $connection = $this->connection($connectionMode);
        $original = $connection->query('SHOW statement_timeout')->fetchColumn();
        $connection->beginTransaction();
        try {
            $connection->exec("SET LOCAL statement_timeout = '25ms'");
            $connection->exec('SAVEPOINT timeout_error');
            $statement = $this->prepare($connection, 'SELECT pg_sleep(CAST(? AS double precision))', $statementMode);
            try {
                $statement->execute([0.1]);
                self::fail('Statement timeout must remain enforced');
            } catch (PDOException $exception) {
                self::assertSame('57014', $exception->getCode());
            }
            $connection->exec('ROLLBACK TO SAVEPOINT timeout_error');
            self::assertSame('25ms', $connection->query('SHOW statement_timeout')->fetchColumn());
            self::assertTrue($statement->execute([0]));
            self::assertTrue($connection->inTransaction());
        } finally {
            $connection->rollBack();
        }
        self::assertSame($original, $connection->query('SHOW statement_timeout')->fetchColumn());
    }

    #[DataProvider('parameterModes')]
    public function test_only_named_mode_registers_a_server_prepared_statement(bool $connectionMode, bool $statementMode): void
    {
        $connection = $this->connection($connectionMode);
        $statement = $this->prepare($connection, 'SELECT CAST(? AS integer) AS native_parameter_marker', $statementMode);
        self::assertTrue($statement->execute([37]));
        self::assertSame(37, $statement->fetchColumn());
        $count = (int) $connection->query(<<<'SQL'
SELECT count(*) FROM pg_prepared_statements
WHERE statement = 'SELECT CAST($1 AS integer) AS native_parameter_marker'
SQL)->fetchColumn();
        self::assertSame($connectionMode || $statementMode ? 0 : 1, $count);
        self::assertFalse($connection->getAttribute(PDO::ATTR_EMULATE_PREPARES));
    }

    private function connection(bool $disablePrepares): PDO
    {
        self::assertSame('127.0.0.1', getenv('DB_HOST'));
        self::assertMatchesRegularExpression('/^most_phpunit_[a-f0-9]{24}_testing$/D', (string) getenv('DB_DATABASE'));

        return new PDO(
            sprintf('pgsql:host=%s;port=%s;dbname=%s', getenv('DB_HOST'), getenv('DB_PORT'), getenv('DB_DATABASE')),
            (string) getenv('DB_USERNAME'),
            (string) getenv('DB_PASSWORD'),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_STRINGIFY_FETCHES => false,
                PDO::ATTR_EMULATE_PREPARES => false,
                Pgsql::ATTR_DISABLE_PREPARES => $disablePrepares,
            ],
        );
    }

    private function prepare(PDO $connection, string $sql, bool $disablePrepares): PDOStatement
    {
        $statement = $connection->prepare($sql, $disablePrepares ? [Pgsql::ATTR_DISABLE_PREPARES => true] : []);
        self::assertInstanceOf(PDOStatement::class, $statement);

        return $statement;
    }
}
