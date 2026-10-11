<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\Rag;

use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Log;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class RagStorageCompactionMigrationTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private mixed $previousApplication;

    private const TABLES = ['public.ai_rag_expected_sources', 'public.ai_assistant_status_snapshot_changes', 'public.ai_assistant_status_snapshot_scoped_changes'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousApplication = Facade::getFacadeApplication();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(new Container);
        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('info');
        Log::swap($logger);
    }

    protected function tearDown(): void
    {
        try {
            Mockery::close();
        } finally {
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication($this->previousApplication);
            parent::tearDown();
        }
    }

    public function test_missing_maintain_is_restored_before_reindex_only_for_the_known_tables(): void
    {
        $connection = $this->connection();
        foreach (self::TABLES as $position => $table) {
            $permission = $connection->shouldReceive('selectOne')->with(Mockery::on(static fn (string $query): bool => str_contains($query, 'MAINTAIN WITH GRANT OPTION')), [$table, $table]);
            if ($position === 0) {
                $permission->twice()->andReturn((object) ['allowed' => 0, 'can_grant' => 1], (object) ['allowed' => 1, 'can_grant' => 1]);
                $connection->shouldReceive('statement')->with('GRANT MAINTAIN ON TABLE '.$table.' TO CURRENT_USER')->once()->ordered()->andReturnTrue();
            } else {
                $permission->once()->andReturn((object) ['allowed' => 1, 'can_grant' => 0]);
            }
            $connection->shouldReceive('statement')->with('REINDEX TABLE '.$table)->once()->ordered()->andReturnTrue();
        }
        $this->migration()->up();
    }

    public function test_missing_grant_option_blocks_compaction_and_restores_timeouts(): void
    {
        $connection = $this->connection();
        $connection->shouldReceive('selectOne')->with(Mockery::on(static fn (string $query): bool => str_contains($query, 'MAINTAIN WITH GRANT OPTION')), [self::TABLES[0], self::TABLES[0]])
            ->once()->andReturn((object) ['allowed' => 0, 'can_grant' => 0]);
        $connection->shouldNotReceive('statement');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('rag_storage_compaction_requires_maintain');
        $this->migration()->up();
    }

    public function test_ineffective_grant_does_not_attempt_reindex(): void
    {
        $connection = $this->connection();
        $table = self::TABLES[0];
        $connection->shouldReceive('selectOne')->with(Mockery::on(static fn (string $query): bool => str_contains($query, 'MAINTAIN WITH GRANT OPTION')), [$table, $table])
            ->twice()->andReturn((object) ['allowed' => 0, 'can_grant' => 1]);
        $connection->shouldReceive('statement')->with('GRANT MAINTAIN ON TABLE '.$table.' TO CURRENT_USER')->once()->andReturnTrue();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('rag_storage_compaction_requires_maintain');
        $this->migration()->up();
    }

    public function test_reindex_failure_is_not_silently_deferred_and_timeouts_are_restored(): void
    {
        $connection = $this->connection();
        $table = self::TABLES[0];
        $connection->shouldReceive('selectOne')->with(Mockery::on(static fn (string $query): bool => str_contains($query, 'MAINTAIN WITH GRANT OPTION')), [$table, $table])
            ->once()->andReturn((object) ['allowed' => 1, 'can_grant' => 0]);
        $connection->shouldReceive('statement')->with('REINDEX TABLE '.$table)->once()->andThrow(new RuntimeException('compaction_timeout'));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('compaction_timeout');
        $this->migration()->up();
    }

    private function migration(): object
    {
        return require dirname(__DIR__, 4).'/database/migrations/2026_10_11_120000_restore_rag_maintenance_and_compact_indexes.php';
    }

    private function connection(): Connection
    {
        $connection = Mockery::mock(Connection::class);
        $manager = Mockery::mock(DatabaseManager::class);
        $manager->shouldReceive('connection')->andReturn($connection);
        DB::swap($manager);
        $connection->shouldReceive('selectOne')->with(Mockery::on(static fn (string $query): bool => str_contains($query, "current_setting('server_version_num')")))
            ->once()->andReturn((object) ['statement_timeout' => '17s', 'lock_timeout' => '3s', 'version' => '170004']);
        $connection->shouldReceive('selectOne')->with("SELECT set_config('statement_timeout', '180s', false), set_config('lock_timeout', '5s', false)")->once()->andReturn((object) []);
        $connection->shouldReceive('selectOne')->with("SELECT set_config('statement_timeout', ?, false), set_config('lock_timeout', ?, false)", ['17s', '3s'])->once()->andReturn((object) []);
        $connection->shouldReceive('select')->andReturn([(object) ['name' => 'test_index', 'definition' => 'original_definition', 'bytes' => 8192, 'healthy' => 1]]);

        return $connection;
    }
}
