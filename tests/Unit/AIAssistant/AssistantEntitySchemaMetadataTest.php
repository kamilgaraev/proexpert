<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AssistantEntitySchemaMetadata;
use Illuminate\Database\PostgresConnection;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class AssistantEntitySchemaMetadataTest extends TestCase
{
    public function test_unqualified_tables_resolve_the_current_schema_and_keep_the_prefix(): void
    {
        $connection = new PostgresConnection(null, 'testing', 'tenant_', ['search_path' => 'tenant_schema']);
        $builder = $connection->getSchemaBuilder();
        $metadata = new AssistantEntitySchemaMetadata;
        $method = (new ReflectionClass($metadata))->getMethod('schemaMetadataTableReference');

        self::assertSame(['tenant_schema', 'tenant_projects'], $method->invoke($metadata, $builder, $connection, 'projects'));
        self::assertSame(['reports', 'tenant_monthly'], $method->invoke($metadata, $builder, $connection, 'reports.monthly'));
    }
}
