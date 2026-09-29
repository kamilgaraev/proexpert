<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\DomainMetadata\AssistantCoreBusinessMetadata;
use App\Models\EstimateLibraryItem;
use App\Models\EstimateLibraryUsage;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\PostgresConnection;
use PHPUnit\Framework\TestCase;

final class EstimateLibraryUsageTableContractTest extends TestCase
{
    public function test_model_and_indexed_catalog_use_the_canonical_migrated_table(): void
    {
        $migration = file_get_contents(dirname(__DIR__, 3).'/database/migrations/2025_11_01_000004_create_estimate_libraries_tables.php');
        self::assertIsString($migration);
        self::assertStringContainsString("Schema::create('estimate_library_usage'", $migration);
        self::assertStringContainsString('ON estimate_library_usage USING GIN(applied_parameters)', $migration);
        $record = AssistantCoreBusinessMetadata::inventory()['core_estimate_library_usage'];
        self::assertSame('estimate_library_usage', (new EstimateLibraryUsage)->getTable());
        self::assertSame('estimate_library_usage', $record['table']);
        self::assertTrue($record['indexed']);
        self::assertSame(['library_item_id', 'estimate_id'], array_keys($record['parents']));
        self::assertSame('estimate_library_item', $record['parents']['library_item_id']['type']);
        self::assertSame('estimate', $record['parents']['estimate_id']['type']);
    }

    public function test_existing_library_relation_and_usage_scopes_generate_singular_postgresql_sql(): void
    {
        $previous = Model::getConnectionResolver();
        $connection = new PostgresConnection(static fn () => throw new \LogicException('Contract test must not connect to PostgreSQL'));
        $resolver = $this->createMock(ConnectionResolverInterface::class);
        $resolver->method('connection')->willReturn($connection);
        Model::setConnectionResolver($resolver);
        try {
            $item = new EstimateLibraryItem;
            $item->id = 17;
            $relation = $item->usageHistory();
            self::assertStringContainsString('from "estimate_library_usage"', $relation->toSql());
            self::assertStringContainsString('"estimate_library_usage"."library_item_id" = ?', $relation->toSql());
            self::assertSame([17], $relation->getBindings());
            $query = EstimateLibraryUsage::query()->byLibraryItem(17)->byEstimate(23)->byUser(31);
            self::assertStringContainsString('from "estimate_library_usage"', $query->toSql());
            self::assertSame([17, 23, 31], $query->getBindings());
            self::assertStringNotContainsString('estimate_library_usages', $query->toSql());
        } finally {
            $previous === null ? Model::unsetConnectionResolver() : Model::setConnectionResolver($previous);
        }
    }
}
