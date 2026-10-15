<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Database\Connection;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

final class AssistantRagCoverageCompactionPrivilegeTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function test_postgres_16_does_not_query_unsupported_maintain_privileges(): void
    {
        $connection = Mockery::mock(Connection::class);
        $connection->shouldNotReceive('selectOne');
        $connection->shouldNotReceive('statement');
        $this->ensurePrivilege($connection, false);
        self::assertTrue(true);
    }

    public function test_existing_maintenance_privilege_does_not_issue_a_grant(): void
    {
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('selectOne')->once()->andReturn((object) ['allowed' => 1, 'can_grant' => 0, 'owns_table' => 0]);
        $connection->shouldNotReceive('statement');
        $this->ensurePrivilege($connection);
        self::assertTrue(true);
    }

    public function test_owner_with_grant_authority_restores_only_its_maintenance_privilege(): void
    {
        $table = '"public"."ai_rag_status_sources"';
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('selectOne')->with(Mockery::on(static fn (string $sql): bool =>
            str_contains($sql, 'MAINTAIN WITH GRANT OPTION') && str_contains($sql, 'pg_has_role')), [$table, $table, $table])
            ->twice()->andReturn((object) ['allowed' => 0, 'can_grant' => 1, 'owns_table' => 1], (object) ['allowed' => 1]);
        $connection->shouldReceive('statement')->once()->with('GRANT MAINTAIN ON TABLE '.$table.' TO CURRENT_USER')->andReturn(true);
        $this->ensurePrivilege($connection);
        self::assertTrue(true);
    }

    public function test_missing_owner_or_grant_authority_does_not_issue_a_grant(): void
    {
        foreach ([[0, 1], [1, 0], [0, 0]] as [$owner, $grant]) {
            $connection = Mockery::mock(Connection::class);
            $connection->shouldReceive('selectOne')->once()->andReturn((object) ['allowed' => 0, 'can_grant' => $grant, 'owns_table' => $owner]);
            $connection->shouldNotReceive('statement');
            try {
                $this->ensurePrivilege($connection);
                self::fail('Missing owner authority must stop compaction.');
            } catch (RuntimeException $exception) {
                self::assertSame('RAG coverage index compaction requires owner maintenance grant authority', $exception->getMessage());
            }
        }
    }

    public function test_unsuccessful_grant_does_not_allow_compaction(): void
    {
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('selectOne')->twice()->andReturn((object) ['allowed' => 0, 'can_grant' => 1, 'owns_table' => 1], (object) ['allowed' => 0]);
        $connection->shouldReceive('statement')->once()->with('GRANT MAINTAIN ON TABLE "public"."ai_rag_status_sources" TO CURRENT_USER')->andReturn(true);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('RAG coverage index compaction maintenance privilege is unavailable');
        $this->ensurePrivilege($connection);
    }

    private function ensurePrivilege(Connection $connection, bool $supportsMaintain = true): void
    {
        $migration = require dirname(__DIR__, 2).'/database/migrations/2026_10_15_225000_compact_rag_status_coverage_index.php';
        (new ReflectionMethod($migration, 'ensureMaintenancePrivilege'))->invoke($migration, $connection, '"public"."ai_rag_status_sources"', $supportsMaintain);
    }
}
