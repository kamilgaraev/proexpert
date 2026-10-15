<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Core\Reporting\Application\Access\ReportExecutionContextFactory;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\CurrentReportScopeAuthorizer;
use App\BusinessModules\Core\Reporting\Application\Execution\CurrentReportAuthorizationTarget;
use App\BusinessModules\Core\Reporting\Application\Queries\ProjectReportExportHistory;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportFilterSet;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportOperation;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\IdempotencyKey;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\EloquentReportRunStore;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\Models\ReportExportRecord;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\Models\ReportRunRecord;
use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantProjectExportHistoryReader;
use App\Models\Project;
use App\Services\Modules\PackageCatalogService;
use DateTimeZone;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

final class AssistantNonemptyExportHistoryTest extends TestCase
{
    public function test_nonempty_history_uses_native_authorization_and_retention_without_creating_downloads(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-10-15T10:00:00+00:00'));
        Queue::fake(); config(['cache.default' => 'array']);
        $fixture = Model::withoutEvents(fn () => AssistantRealAuthorizationFixture::create(array_column(app(PackageCatalogService::class)->allPackages(), 'slug')));
        $org = (int) $fixture->organization->id;
        $project = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $org]));
        $definition = app(ReportDefinitionRegistry::class)->published('quality_defect_flow')->definition;
        $authorization = app(CurrentReportScopeAuthorizer::class)->authorizeExact((int) $fixture->owner->id,
            new ReportScope($org, [$org], [(int) $project->id], [], new DateTimeZone('UTC')),
            new CurrentReportAuthorizationTarget($definition, ReportOperation::RUN, null));
        $context = app(ReportExecutionContextFactory::class)->fromCurrentAuthorization($authorization);
        $query = new ReportQuery($definition, $context->scope, new ReportFilterSet([]), [], now()->toDateTimeImmutable(), 'ru-RU');
        $runs = app()->makeWith(EloquentReportRunStore::class, ['runTtlSeconds' => 86400, 'pollAfterMs' => 1000]);
        $run = $runs->createOrReuse($context, $query, null, new IdempotencyKey('nonempty-assistant-history'));
        $record = ReportRunRecord::query()->findOrFail($run->id);
        $record->forceFill(['status' => 'ready', 'progress' => 100, 'row_count' => 2,
            'source_hash' => str_repeat('a', 64), 'result_hash' => str_repeat('b', 64),
            'result_metadata' => [], 'freshness' => 'fresh', 'quality' => [], 'provenance' => [], 'row_schema' => [], 'capabilities' => [],
            'snapshot_kind' => 'materialized', 'snapshot_id' => 'nonempty-history-fixture',
            'snapshot_generated_at' => now()->subHour(), 'snapshot_watermarks' => [],
            'snapshot_materialized_source_hash' => str_repeat('a', 64),
            'snapshot_classification' => $definition->snapshotClassification->value,
            'queued_at' => now()->subHours(2), 'started_at' => now()->subHour(), 'ready_at' => now()->subHour(),
        ])->saveQuietly();
        $record->refresh();
        $identity = $record->only(['organization_id', 'requester_actor_id', 'report_code', 'definition_hash', 'query_hash',
            'source_hash', 'result_hash', 'scope_holding_organization_ids', 'scope_project_ids', 'scope_resources', 'scope_timezone',
            'snapshot_kind', 'snapshot_id', 'snapshot_generated_at', 'snapshot_stale_at', 'snapshot_watermarks',
            'snapshot_materialized_source_hash', 'snapshot_classification', 'snapshot_seal_key_id', 'snapshot_seal_algorithm',
            'snapshot_sealed_payload_hash', 'snapshot_seal_signature', 'snapshot_sealed_at', 'data_classification',
            'sensitive_column_ids', 'audit_column_ids', 'contract_version', 'formula_version', 'source_schema_version', 'renderer_version']);
        $identity += array_intersect_key($record->definition_snapshot['output_classification'], array_flip(['totals_sensitive', 'totals_audit', 'provenance_audit']));
        foreach ([['ready', false], ['ready', true], ['queued', false], ['failed', false]] as [$status, $expired]) {
            $id = (string) Str::ulid();
            $ready = $status === 'ready';
            ReportExportRecord::query()->create($identity + ['id' => $id, 'run_id' => $run->id, 'status' => $status,
                'export_hash' => hash('sha256', $id), 'idempotency_key_hash' => hash('sha256', 'key-'.$id),
                'input_fingerprint' => hash('sha256', 'input-'.$id), 'format' => 'csv',
                'selected_columns' => [$definition->columns[0]['id']], 'sort_field' => $definition->sorts[0]['id'],
                'sort_direction' => 'asc', 'locale' => 'ru-RU', 'render_timezone' => 'UTC',
                'artifact_path' => $ready ? 'org-'.$org.'/private-assistant-history.csv' : null,
                'artifact_etag' => $ready ? 'private-etag' : null, 'artifact_mime' => $ready ? 'text/csv' : null,
                'artifact_checksum' => $ready ? str_repeat('c', 64) : null, 'artifact_size_bytes' => $ready ? 128 : null,
                'row_count' => $ready ? 2 : null, 'ready_at' => $ready ? now()->subHour() : null,
                'error_code' => $status === 'failed' ? 'REPORT_INTERNAL_ERROR' : null,
                'failed_at' => $status === 'failed' ? now()->subHour() : null,
                'queued_at' => now()->subHours(2), 'created_at' => now()->subHours(2), 'updated_at' => now()->subHour(),
                'expires_at' => $expired ? now()->subMinutes(10) : now()->addHour(),
            ]);
        }
        $before = DB::table('report_exports')->count();
        $beforeRows = ReportExportRecord::query()->orderBy('id')->get()->toArray();
        $dispatches = DB::table('report_dispatch_intents')->count();
        $native = app(ProjectReportExportHistory::class)->read((int) $fixture->owner->id, $org, (int) $project->id);
        $reader = app(AssistantProjectExportHistoryReader::class);
        $result = $reader->read($fixture->owner, $org, ['project_id' => (int) $project->id]);
        self::assertCount(4, $native['items']);
        self::assertSame($native['items'], $result['data']['items']);
        self::assertTrue($result['data']['complete']);
        self::assertStringContainsString('выборке: 4; готовы: 1; в очереди или обработке: 1; с ошибкой: 1; отменены: 0; срок хранения истёк: 1.', $result['server_formatted_answer']);
        self::assertStringNotContainsString('private-assistant-history', json_encode($result));
        self::assertStringNotContainsString('private-etag', json_encode($result));
        self::assertSame($before, DB::table('report_exports')->count());
        self::assertSame($beforeRows, ReportExportRecord::query()->orderBy('id')->get()->toArray());
        self::assertSame($dispatches, DB::table('report_dispatch_intents')->count());
        self::assertFalse($reader->matchesReference($fixture->foreignOwner, $org, $result['source_refs'][0]));
        self::assertTrue($reader->matchesReference($fixture->owner, $org, $result['source_refs'][0]));
        $this->travel(2)->hours();
        self::assertSame($result['data']['as_of_date'], today()->toDateString());
        self::assertFalse($reader->matchesReference($fixture->owner, $org, $result['source_refs'][0]));
        $fixture->ownerAssignment->updateQuietly(['is_active' => false]);
        self::assertFalse($reader->matchesReference($fixture->owner, $org, $result['source_refs'][0]));
    }
}
