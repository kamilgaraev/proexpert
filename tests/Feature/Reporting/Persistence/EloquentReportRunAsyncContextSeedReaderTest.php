<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting\Persistence;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\DTO\PublishedReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\EloquentReportRunAsyncContextSeedReader;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\Models\ReportRunRecord;
use App\Models\Organization;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\Reporting\ReportDefinitionBuilder;
use Tests\TestCase;

#[Group('postgresql')]
final class EloquentReportRunAsyncContextSeedReaderTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        self::assertSame('pgsql', DB::connection()->getDriverName());
    }

    public function test_it_reads_only_the_closed_current_authorization_seed(): void
    {
        Organization::factory()->create(['id' => 7]);
        $this->insertRun();

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
        $seed = (new EloquentReportRunAsyncContextSeedReader($registry))->forRun('01J00000000000000000000001');

        self::assertSame('run', $seed->aggregateKind);
        self::assertSame(17, $seed->requesterActorId);
        self::assertSame([7], $seed->requestedScope->holdingOrganizationIds);
        self::assertSame('lineage-1', $seed->correlationLineageId);
        self::assertSame(
            ['aggregateKind', 'aggregateId', 'organizationId', 'requesterActorId', 'requestedScope', 'definition', 'correlationLineageId'],
            array_keys(get_object_vars($seed)),
        );
    }

    private function insertRun(): void
    {
        $now = new DateTimeImmutable('2026-07-26T10:00:00Z');
        ReportRunRecord::query()->create([
            'id' => '01J00000000000000000000001',
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
            'definition_snapshot' => [],
            'canonical_query_json' => '{}',
            'scope_holding_organization_ids' => [7],
            'scope_project_ids' => [],
            'scope_resources' => [],
            'scope_timezone' => 'UTC',
            'filters' => [],
            'comparison' => [],
            'as_of' => $now,
            'locale' => 'ru',
            'snapshot_classification' => 'operational',
            'data_classification' => 'standard',
            'sensitive_column_ids' => [],
            'audit_column_ids' => [],
            'progress' => 0,
            'totals' => [],
            'correlation_lineage_id' => 'lineage-1',
            'queued_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
            'expires_at' => $now->modify('+1 hour'),
        ]);
    }
}
