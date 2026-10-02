<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\Documents;

use Illuminate\Container\Container;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder as SchemaBuilder;
use Illuminate\Database\Schema\Grammars\PostgresGrammar;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class NativeDocumentSchemaMigrationTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private ?Container $originalFacadeApplication = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalFacadeApplication = Facade::getFacadeApplication();
        Facade::setFacadeApplication(new Container);
        Schema::clearResolvedInstance('db.schema');
        DB::clearResolvedInstance('db');
    }

    protected function tearDown(): void
    {
        Schema::clearResolvedInstance('db.schema');
        DB::clearResolvedInstance('db');
        Facade::setFacadeApplication($this->originalFacadeApplication);
        parent::tearDown();
    }

    public function test_upgrade_changes_only_file_column_nullability_without_dropping_integrity_constraints(): void
    {
        $schema = Mockery::mock(SchemaBuilder::class);
        $schema->shouldReceive('table')->once()->with('ai_assistant_documents', Mockery::on(function (mixed $callback): bool {
            self::assertIsCallable($callback);
            $blueprint = new Blueprint('ai_assistant_documents');
            $callback($blueprint);
            $sql = $blueprint->toSql(new PostgresConnection(null), new PostgresGrammar);
            self::assertCount(2, $sql);
            self::assertSame('comment on column "ai_assistant_documents"."file_id" is NULL', $sql[1]);
            self::assertStringContainsString('alter column "file_id" drop not null', $sql[0]);
            self::assertStringContainsString('alter column "file_id" type bigint', $sql[0]);
            self::assertStringNotContainsString('drop constraint', $sql[0]);
            self::assertStringNotContainsString('drop index', $sql[0]);
            self::assertCount(1, $blueprint->getColumns());
            self::assertSame('file_id', $blueprint->getColumns()[0]->name);

            return true;
        }));
        Schema::swap($schema);
        $this->migration()->up();
    }

    public function test_rollback_with_native_documents_refuses_before_any_schema_or_data_change(): void
    {
        $query = Mockery::mock(QueryBuilder::class);
        $query->shouldReceive('whereNull')->once()->with('file_id')->andReturnSelf();
        $query->shouldReceive('exists')->once()->andReturn(true);
        $database = Mockery::mock();
        $database->shouldReceive('table')->once()->with('ai_assistant_documents')->andReturn($query);
        DB::swap($database);
        $schema = Mockery::mock(SchemaBuilder::class);
        $schema->shouldNotReceive('table');
        Schema::swap($schema);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('native_ai_documents_prevent_file_id_rollback');
        $this->migration()->down();
    }

    public function test_rollback_with_only_file_backed_documents_restores_not_null(): void
    {
        $query = Mockery::mock(QueryBuilder::class);
        $query->shouldReceive('whereNull')->once()->with('file_id')->andReturnSelf();
        $query->shouldReceive('exists')->once()->andReturn(false);
        $database = Mockery::mock();
        $database->shouldReceive('table')->once()->with('ai_assistant_documents')->andReturn($query);
        DB::swap($database);
        $schema = Mockery::mock(SchemaBuilder::class);
        $schema->shouldReceive('table')->once()->with('ai_assistant_documents', Mockery::on(function (mixed $callback): bool {
            self::assertIsCallable($callback);
            $blueprint = new Blueprint('ai_assistant_documents');
            $callback($blueprint);
            $sql = $blueprint->toSql(new PostgresConnection(null), new PostgresGrammar);
            self::assertCount(2, $sql);
            self::assertSame('comment on column "ai_assistant_documents"."file_id" is NULL', $sql[1]);
            self::assertStringContainsString('alter column "file_id" set not null', $sql[0]);
            self::assertStringNotContainsString('drop constraint', $sql[0]);
            self::assertStringNotContainsString('drop index', $sql[0]);

            return true;
        }));
        Schema::swap($schema);
        $this->migration()->down();
    }

    private function migration(): Migration
    {
        return require __DIR__.'/../../../../app/BusinessModules/Features/AIAssistant/migrations/2026_09_29_000015_allow_native_ai_document_sources.php';
    }
}
