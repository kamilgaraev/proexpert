<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Http\Resources\ConversationResource;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\AIPermissionChecker;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDomainCatalog;
use App\BusinessModules\Features\AIAssistant\Services\ConversationManager;
use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantCustomReportBuilder;
use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantCustomReportReference;
use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantCustomReportSource;
use App\Models\Project;
use App\Services\Modules\PackageCatalogService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

final class AssistantConversationReportFreshnessTest extends TestCase
{
    use RefreshDatabase;

    public static function reportMetadataFailures(): array
    {
        return ['normal' => ['none'], 'one transient metadata failure' => ['once'], 'persistent metadata failure' => ['persistent'], 'deadline' => ['deadline']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('reportMetadataFailures')]
    public function test_mixed_report_previews_prefetch_schema_without_skipping_current_proofs(string $faultMode): void
    {
        Queue::fake();
        config(['cache.default' => 'array']);
        $fixture = Model::withoutEvents(fn () => AssistantRealAuthorizationFixture::create(
            array_column(app(PackageCatalogService::class)->allPackages(), 'slug')));
        $fixture->memberRole->update(['module_permissions' => [
            'ai-assistant' => ['ai_assistant.chat', 'ai_assistant.reports.generate'],
            'project-management' => ['projects.view'],
            'contract-management' => ['contracts.view'],
        ]]);
        $project = Project::withoutEvents(fn () => Project::factory()->create([
            'organization_id' => $fixture->organization->id, 'is_archived' => false,
        ]));
        $fixture->member->assignedProjects()->attach($project->id, ['is_active' => true, 'role' => 'member']);
        $contractor = Model::withoutEvents(fn () => \App\Models\Contractor::query()->create([
            'organization_id' => $fixture->organization->id, 'name' => 'Synthetic contractor',
        ]));
        $contract = Model::withoutEvents(fn () => \App\Models\Contract::query()->create([
            'organization_id' => $fixture->organization->id, 'project_id' => $project->id, 'contractor_id' => $contractor->id,
            'number' => 'SCHEMA-1', 'date' => '2026-10-01', 'total_amount' => '100.00', 'currency' => 'RUB',
        ]));
        $policy = app(AssistantDataAccessPolicy::class);
        $sources = new AssistantCustomReportSource(new AssistantDomainCatalog(AssistantDomainCatalog::defaults()), $policy);
        $references = new AssistantCustomReportReference($sources, $policy);
        $builder = new AssistantCustomReportBuilder($sources, $references, $policy);
        $proofs = [];
        foreach ([['projects', 'project', ['id', 'name'], $project->id], ['contracts', 'contract', ['number'], $contract->id]] as [$domain, $type, $columns, $id]) {
            $report = $builder->build(['title' => 'Synthetic mixed report', 'format' => 'xlsx', 'sections' => [[
                'title' => 'Rows', 'domain' => $domain, 'entity_type' => $type, 'project_id' => null,
                'columns' => $columns, 'filters' => [], 'group_by' => [], 'metrics' => [], 'order_by' => [],
            ]]], $fixture->member, $fixture->organization->id);
            self::assertCount(1, $report['source_refs']);
            self::assertSame([(string) $id], $report['source_refs'][0]['_report_ids']);
            $proofs[] = [$report['source_refs'][0]];
        }
        $cold = new AssistantDataAccessPolicy(
            app(\App\Domain\Authorization\Services\AuthorizationService::class),
            app(\App\Services\Project\UserProjectAccessService::class),
            app(\App\Services\Entitlements\OrganizationEntitlementService::class),
        );
        $coldSources = new AssistantCustomReportSource(new AssistantDomainCatalog(AssistantDomainCatalog::defaults()), $cold);
        $this->app->instance(AssistantCustomReportReference::class, new AssistantCustomReportReference($coldSources, $cold));
        $watch = false;
        $schemaReads = 0;
        $proofReads = 0;
        $faults = 0;
        DB::listen(static function (QueryExecuted $query) use (&$watch, &$schemaReads, &$proofReads, &$faults, $faultMode): void {
            if (! $watch) { return; }
            if (str_contains($query->sql, 'pg_attribute') && str_contains($query->sql, 'pg_class')) {
                $schemaReads++;
                if ($faultMode === 'persistent' || ($faultMode === 'once' && $faults === 0)) {
                    $faults++;
                    throw new \Illuminate\Database\QueryException('pgsql', $query->sql, $query->bindings, new \RuntimeException('synthetic_metadata_unavailable'));
                }
            }
            if (preg_match('/\bselect\s+count\(/i', $query->sql) !== 1) { return; }
            foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 96) as $frame) {
                if (basename($frame['file'] ?? '') === 'AssistantCustomReportReference.php') { $proofReads++; break; }
            }
        });
        $deadline = new \RuntimeException('synthetic_schema_deadline');
        $checkpoint = static function () use ($faultMode, $deadline): void {
            if ($faultMode !== 'deadline') { return; }
            foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 16) as $frame) {
                if (basename($frame['file'] ?? '') === 'AssistantEntitySchemaMetadata.php') { throw $deadline; }
            }
        };
        $failure = null;
        $decisions = [];
        $watch = true;
        try {
            $decisions = $cold->withCurrentChecks($fixture->member, $fixture->organization->id,
                fn () => $cold->canReadReferenceSets($fixture->member, $fixture->organization->id, $proofs), fresh: true, checkpoint: $checkpoint);
        } catch (\RuntimeException $exception) {
            if ($faultMode !== 'deadline') { throw $exception; }
            $failure = $exception;
        } finally {
            $watch = false;
        }
        if ($faultMode === 'deadline') {
            self::assertSame($deadline, $failure);
            self::assertSame(0, $schemaReads);
            self::assertSame(0, $proofReads);
            return;
        }
        self::assertNull($failure);
        self::assertSame($faultMode === 'persistent' ? [false, false] : [true, true], array_values($decisions));
        self::assertSame($faultMode === 'persistent' ? 0 : 2, $proofReads, 'Each permitted signed report must keep its current native row-access proof.');
        self::assertSame($faultMode === 'none' ? 1 : 3, $schemaReads, 'Cold metadata must be read once, or use the existing per-proof fallback after a metadata error.');
        self::assertSame(match ($faultMode) { 'once' => 1, 'persistent' => 3, default => 0 }, $faults);
    }

    public function test_signed_report_previews_recheck_project_access_and_each_report_proof(): void
    {
        Queue::fake();
        config(['cache.default' => 'array']);
        $fixture = Model::withoutEvents(fn () => AssistantRealAuthorizationFixture::create(
            array_column(app(PackageCatalogService::class)->allPackages(), 'slug')));
        $fixture->memberRole->update(['module_permissions' => [
            'ai-assistant' => ['ai_assistant.chat', 'ai_assistant.reports.generate'],
            'project-management' => ['projects.view'],
        ]]);
        $project = Project::withoutEvents(fn () => Project::factory()->create([
            'organization_id' => $fixture->organization->id, 'is_archived' => false,
        ]));
        $fixture->member->assignedProjects()->attach($project->id, ['is_active' => true, 'role' => 'member']);
        $policy = app(AssistantDataAccessPolicy::class);
        $sources = new AssistantCustomReportSource(new AssistantDomainCatalog(AssistantDomainCatalog::defaults()), $policy);
        $references = new AssistantCustomReportReference($sources, $policy);
        $this->app->instance(AssistantCustomReportReference::class, $references);
        $builder = new AssistantCustomReportBuilder($sources, $references, $policy);
        $plan = ['title' => 'Synthetic project report', 'format' => 'xlsx', 'sections' => [[
            'title' => 'Projects', 'domain' => 'projects', 'entity_type' => 'project', 'project_id' => null,
            'columns' => ['id', 'name'], 'filters' => [], 'group_by' => [], 'metrics' => [], 'order_by' => [],
        ]]];
        $manager = app(ConversationManager::class);
        $ids = [];
        $messages = [];
        $proofs = [];
        foreach (range(1, 8) as $index) {
            $report = $builder->build($plan, $fixture->member, $fixture->organization->id);
            $reference = $report['source_refs'][0];
            self::assertSame(AssistantCustomReportReference::TYPE, $reference['entity_type']);
            self::assertSame([(string) $project->id], $reference['_report_ids']);
            self::assertNotEmpty($reference['_report_proof']);
            $proofs[] = $reference;
            $conversation = $manager->createConversation($fixture->organization->id, $fixture->member, 'Synthetic '.$index);
            $messages[] = $manager->addMessage($conversation, 'assistant', 'Synthetic report preview', metadata: [
                'validation_status' => 'verified', 'source_refs' => [$reference],
            ]);
            $ids[] = $conversation->id;
        }
        self::assertCount(8, array_unique(array_column($proofs, 'entity_id')));
        foreach ($proofs as $reference) {
            self::assertSame($proofs[0]['_report_scope'], $reference['_report_scope']);
            self::assertSame($proofs[0]['checked_fields'], $reference['checked_fields']);
        }

        $request = Request::create('/api/v1/admin/ai-assistant/conversations');
        $request->setUserResolver(fn () => $fixture->member);
        $watch = false;
        $count = ['all' => 0, 'bank' => 0, 'entitlement' => 0, 'report_schema' => 0, 'proof' => 0];
        DB::listen(static function (QueryExecuted $query) use (&$watch, &$count): void {
            if (! $watch) { return; }
            $count['all']++;
            $families = [];
            foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 96) as $frame) {
                $file = basename($frame['file'] ?? '');
                if ($file === 'BankPaymentProofAccess.php') { $families['bank'] = true; }
                if ($file === 'OrganizationEntitlementService.php') { $families['entitlement'] = true; }
                if ($file === 'AssistantCustomReportSource.php' && str_contains($query->sql, 'format_type(')) { $families['report_schema'] = true; }
                if ($file === 'AssistantCustomReportReference.php' && preg_match('/\bselect\s+count\(/i', $query->sql) === 1
                    && str_contains($query->sql, '"projects"')) { $families['proof'] = true; }
            }
            foreach (array_keys($families) as $family) { $count[$family]++; }
        });
        $observations = [];
        foreach ([1, 8] as $size) {
            $page = $manager->queryVisibleConversations($fixture->member, $fixture->organization->id)
                ->whereKey(array_slice($ids, 0, $size))->paginate(20);
            $count = ['all' => 0, 'bank' => 0, 'entitlement' => 0, 'report_schema' => 0, 'proof' => 0];
            $watch = true;
            try {
                $rows = ConversationResource::collection($page)->resolve($request);
            } finally {
                $watch = false;
            }
            self::assertCount($size, $rows);
            self::assertSame(array_fill(0, $size, 'Synthetic report preview'), array_column($rows, 'last_message_preview'));
            $observations[$size] = $count;
        }
        self::assertSame(1, $observations[1]['proof']);
        self::assertSame(8, $observations[8]['proof'], 'Every signed report must retain its own current row-access check.');
        self::assertLessThanOrEqual(1, $observations[8]['report_schema'], 'Repeated report proofs must reuse column types within the request.');
        $fixture->member->assignedProjects()->updateExistingPivot($project->id, ['is_active' => false]);
        self::assertTrue(app(AIPermissionChecker::class)->canUseAssistant($fixture->member, $fixture->organization->id));
        $revokedRows = ConversationResource::collection($page)->resolve($request);
        self::assertSame(array_fill(0, 8, null), array_column($revokedRows, 'last_message_preview'));
        $fixture->member->assignedProjects()->updateExistingPivot($project->id, ['is_active' => true]);
        $restoredRows = ConversationResource::collection($page)->resolve($request);
        self::assertSame(array_fill(0, 8, 'Synthetic report preview'), array_column($restoredRows, 'last_message_preview'));
        $revokeAfterReportRead = true;
        $revocations = 0;
        DB::listen(static function (QueryExecuted $query) use (&$revokeAfterReportRead, &$revocations, $fixture): void {
            if (! $revokeAfterReportRead || preg_match('/\bselect\s+count\(/i', $query->sql) !== 1
                || ! str_contains($query->sql, '"projects"')) { return; }
            $fromReport = false;
            foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 96) as $frame) {
                if (basename($frame['file'] ?? '') === 'AssistantCustomReportReference.php') { $fromReport = true; break; }
            }
            if (! $fromReport) { return; }
            $revokeAfterReportRead = false;
            $revocations++;
            $fixture->memberRole->update(['module_permissions' => [
                'ai-assistant' => ['ai_assistant.chat', 'ai_assistant.reports.generate'],
                'project-management' => [],
            ]]);
        });
        $midReadRows = ConversationResource::collection($page)->resolve($request);
        self::assertSame(1, $revocations);
        self::assertTrue(app(AIPermissionChecker::class)->canUseAssistant($fixture->member, $fixture->organization->id));
        self::assertSame(['Synthetic report preview', ...array_fill(0, 7, null)], array_column($midReadRows, 'last_message_preview'));
        $fixture->memberRole->update(['module_permissions' => [
            'ai-assistant' => ['ai_assistant.chat', 'ai_assistant.reports.generate'],
            'project-management' => ['projects.view'],
        ]]);
        $afterRoleRestore = ConversationResource::collection($page)->resolve($request);
        self::assertSame(array_fill(0, 8, 'Synthetic report preview'), array_column($afterRoleRestore, 'last_message_preview'));
        $badProof = $proofs[0];
        $badProof['_report_proof'] = str_repeat('0', 64);
        $unsigned = $proofs[1];
        unset($unsigned['_report_proof']);
        foreach ([[$badProof], [$unsigned], [$proofs[2], $badProof]] as $index => $sourceRefs) {
            $messages[$index]->update(['metadata' => ['validation_status' => 'verified', 'source_refs' => $sourceRefs]]);
        }
        $mixedPage = $manager->queryVisibleConversations($fixture->member, $fixture->organization->id)->whereKey($ids)->paginate(20);
        $mixedRows = ConversationResource::collection($mixedPage)->resolve($request);
        $previews = array_column($mixedRows, 'last_message_preview', 'id');
        foreach ($ids as $index => $id) {
            self::assertSame($index < 3 ? null : 'Synthetic report preview', $previews[$id]);
        }
        $fixture->memberAssignment->update(['is_active' => false]);
        $deniedRows = ConversationResource::collection($mixedPage)->resolve($request);
        self::assertSame(array_fill(0, 8, null), array_column($deniedRows, 'last_message_preview'));
        fwrite(STDERR, 'conversation_report_batch_synthetic='.json_encode($observations, JSON_THROW_ON_ERROR).PHP_EOL);
        self::assertGreaterThan(0, $observations[1]['bank'], 'The real entitlement path must be exercised');
    }
}
