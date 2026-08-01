<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting\Persistence;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\DTO\PublishedReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\EloquentReportExportAsyncContextSeedReader;
use App\Models\Organization;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\Reporting\ReportDefinitionBuilder;
use Tests\TestCase;

#[Group('postgresql')]
final class EloquentReportExportAsyncContextSeedReaderTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        self::assertSame('pgsql', DB::connection()->getDriverName());
    }

    public function test_it_reads_only_the_closed_export_authorization_seed(): void
    {
        Organization::factory()->create(['id' => 7]);
        $this->insertRunAndExport('queued');
        $definition = (new ReportDefinitionBuilder)->code('cost_control')->published();
        $registry = new class($definition) implements ReportDefinitionRegistry
        {
            public function __construct(private PublishedReportDefinition $definition) {}

            public function published(string $code): PublishedReportDefinition
            {
                return $this->definition;
            }

            public function publishedCodes(): array
            {
                return [$this->definition->code];
            }

            public function manifestSha256(): Sha256Hash
            {
                return new Sha256Hash(str_repeat('f', 64));
            }
        };

        $seed = (new EloquentReportExportAsyncContextSeedReader($registry))
            ->forExport('01J00000000000000000000001');

        self::assertSame('run', $seed->aggregateKind);
        self::assertSame('01J00000000000000000000000', $seed->aggregateId);
        self::assertSame(17, $seed->requesterActorId);
        self::assertSame([7], $seed->requestedScope->holdingOrganizationIds);
        self::assertSame('lineage-1', $seed->correlationLineageId);
        self::assertSame(
            [
                'aggregateKind',
                'aggregateId',
                'organizationId',
                'requesterActorId',
                'requestedScope',
                'definition',
                'correlationLineageId',
            ],
            array_keys(get_object_vars($seed)),
        );
    }

    private function insertRunAndExport(string $status): void
    {
        $now = new DateTimeImmutable('2026-07-26T10:00:00Z');
        DB::table('report_runs')->insert([
            'id' => '01J00000000000000000000000',
            'organization_id' => 7,
            'requester_actor_id' => 17,
            'report_code' => 'cost_control',
            'status' => 'queued',
            'definition_hash' => str_repeat('a', 64),
            'definition_snapshot_hash' => str_repeat('b', 64),
            'query_hash' => str_repeat('c', 64),
            'idempotency_key_hash' => str_repeat('d', 64),
            'input_fingerprint' => str_repeat('e', 64),
            'contract_version' => '1',
            'formula_version' => '1',
            'source_schema_version' => '1',
            'renderer_version' => '1',
            'definition_snapshot' => '{}',
            'canonical_query_json' => '{}',
            'scope_holding_organization_ids' => '[7]',
            'scope_project_ids' => '[]',
            'scope_resources' => '[]',
            'scope_timezone' => 'UTC',
            'filters' => '[]',
            'comparison' => '[]',
            'as_of' => $now,
            'locale' => 'ru',
            'snapshot_classification' => 'operational',
            'data_classification' => 'standard',
            'sensitive_column_ids' => '[]',
            'audit_column_ids' => '[]',
            'progress' => 0,
            'totals' => '[]',
            'queued_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
            'expires_at' => $now->modify('+2 hours'),
        ]);
        DB::table('report_exports')->insert([
            'id' => '01J00000000000000000000001',
            'run_id' => '01J00000000000000000000000',
            'organization_id' => 7,
            'requester_actor_id' => 17,
            'correlation_lineage_id' => 'lineage-1',
            'report_code' => 'cost_control',
            'status' => $status,
            'definition_hash' => str_repeat('a', 64),
            'query_hash' => str_repeat('c', 64),
            'source_hash' => str_repeat('1', 64),
            'result_hash' => str_repeat('2', 64),
            'export_hash' => str_repeat('3', 64),
            'idempotency_key_hash' => str_repeat('4', 64),
            'input_fingerprint' => str_repeat('5', 64),
            'scope_holding_organization_ids' => '[7]',
            'scope_project_ids' => '[]',
            'scope_resources' => '[]',
            'scope_timezone' => 'UTC',
            'snapshot_kind' => 'report',
            'snapshot_id' => 'snapshot-1',
            'snapshot_generated_at' => $now,
            'snapshot_watermarks' => '[]',
            'snapshot_classification' => 'operational',
            'data_classification' => 'standard',
            'sensitive_column_ids' => '[]',
            'audit_column_ids' => '[]',
            'totals_sensitive' => false,
            'totals_audit' => false,
            'provenance_audit' => false,
            'contract_version' => '1',
            'formula_version' => '1',
            'source_schema_version' => '1',
            'renderer_version' => '1',
            'format' => 'csv',
            'selected_columns' => '["name"]',
            'sort_field' => 'name',
            'sort_direction' => 'asc',
            'locale' => 'ru',
            'render_timezone' => 'UTC',
            'queued_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
            'expires_at' => $now->modify('+1 hour'),
        ]);
    }
}
