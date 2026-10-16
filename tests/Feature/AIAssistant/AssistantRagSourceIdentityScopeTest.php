<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Models\RagExpectedSource;
use App\BusinessModules\Features\AIAssistant\Models\RagSource;
use App\BusinessModules\Features\AIAssistant\Models\ProjectPulseReport;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Estimate;
use App\Models\EstimateSection;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Entitlements\OrganizationEntitlementService;
use App\Services\Project\UserProjectAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

final class AssistantRagSourceIdentityScopeTest extends TestCase
{
    use RefreshDatabase;

    private AssistantDataAccessPolicy $policy;
    private Organization $organization;
    private User $actor;
    private Project $visible;
    private Project $hidden;
    private array $deniedModules = [];
    private array $deniedPermissions = [];
    private array $additionalModules = [];
    private bool $duplicateProjectRows = false;

    protected function setUp(): void
    {
        parent::setUp();
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->shouldReceive('canCurrent')->andReturnUsing(
            fn (User $actor, string $permission): bool => ! in_array($permission, $this->deniedPermissions, true)
        );
        $authorization->shouldReceive('forCurrentChecks')->andReturnSelf();
        $authorization->shouldReceive('getUserRoles')->andReturn(collect());
        $modules = Mockery::mock(OrganizationEntitlementService::class);
        $modules->shouldReceive('getEffectiveModules')->andReturnUsing(fn () => collect([
            'ai-assistant', 'project-management', 'payments', 'budget-estimates', 'reports', ...$this->additionalModules,
        ])->reject(fn (string $slug): bool => in_array($slug, $this->deniedModules, true))
            ->map(static fn (string $slug): object => (object) ['slug' => $slug]));
        $projectAccess = Mockery::mock(UserProjectAccessService::class);
        $projectAccess->shouldReceive('queryAccessibleProjects')->andReturnUsing(function (User $actor, int $organizationId) {
            $query = (new UserProjectAccessService)->queryAccessibleProjects($actor, $organizationId);
            if ($this->duplicateProjectRows) {
                $query->crossJoinSub(DB::query()->selectRaw('1 AS duplicate_row')
                    ->unionAll(DB::query()->selectRaw('2 AS duplicate_row')), 'duplicate_project_rows');
            }

            return $query;
        });
        $this->policy = new AssistantDataAccessPolicy($authorization, $projectAccess, $modules);
        $this->organization = Organization::withoutEvents(fn () => Organization::factory()->create());
        $this->actor = User::withoutEvents(fn () => User::factory()->create([
            'current_organization_id' => $this->organization->id, 'is_active' => true,
        ]));
        $this->actor->organizations()->attach($this->organization->id, ['is_active' => true, 'project_access_mode' => 'assigned_projects']);
        $this->visible = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $this->organization->id, 'is_archived' => false]));
        $this->hidden = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $this->organization->id, 'is_archived' => false]));
        $this->actor->assignedProjects()->attach($this->visible->id, ['is_active' => true, 'role' => 'member']);
    }

    public function test_source_scope_compiles_present_identities_and_rechecks_new_type_and_module_revocation(): void
    {
        $allowed = $this->source($this->organization->id, $this->visible->id, 'project', 'project', $this->visible->id);
        $this->source($this->organization->id, $this->hidden->id, 'project', 'project', $this->hidden->id);
        $this->source($this->organization->id, $this->visible->id, 'project', 'project', 999999);
        $this->source($this->organization->id, $this->visible->id, 'project', 'unknown_type', $this->visible->id);
        $this->source($this->organization->id, $this->visible->id, 'project', 'estimate_section', $this->visible->id);
        $foreign = Organization::withoutEvents(fn () => Organization::factory()->create());
        $foreignProject = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $foreign->id, 'is_archived' => false]));
        $this->source($foreign->id, $foreignProject->id, 'project', 'project', $foreignProject->id);

        $query = $this->policy->applyToSources(RagSource::query(), $this->actor, $this->organization->id);
        $this->assertNotContains('assistant_document', $query->getBindings());
        $this->assertSame([$allowed->id], $query->orderByDesc('id')->limit(1)->pluck('id')->all());

        $estimate = Estimate::create(['organization_id' => $this->organization->id, 'project_id' => $this->visible->id,
            'number' => 'identity-scope-'.$this->organization->id, 'name' => 'Estimate', 'estimate_date' => today()]);
        $section = EstimateSection::create(['estimate_id' => $estimate->id, 'name' => 'Section', 'sort_order' => 1]);
        $alias = $this->source($this->organization->id, $this->visible->id, 'estimate', 'estimate_section', $section->id);
        $this->assertEqualsCanonicalizing([$allowed->id, $alias->id],
            $this->policy->applyToSources(RagSource::query(), $this->actor, $this->organization->id)->pluck('id')->all());

        $this->deniedModules = ['budget-estimates'];
        $this->assertSame([$allowed->id],
            $this->policy->applyToSources(RagSource::query(), $this->actor, $this->organization->id)->pluck('id')->all());
    }

    public function test_expected_scope_uses_present_projection_types_even_without_indexed_sources(): void
    {
        $allowed = $this->expected($this->organization->id, $this->visible->id, 'project', 'project', $this->visible->id);
        $this->expected($this->organization->id, $this->hidden->id, 'project', 'project', $this->hidden->id);
        $this->expected($this->organization->id, $this->visible->id, 'project', 'unknown_type', $this->visible->id);
        $this->assertSame([$allowed->id], $this->policy->applyToExpectedSources(RagExpectedSource::query(), $this->actor, $this->organization->id)
            ->pluck('id')->all());
    }

    public function test_legacy_index_aliases_keep_current_entity_organization_project_and_permission_checks(): void
    {
        $this->actor->assignedProjects()->attach($this->hidden->id, ['is_active' => true, 'role' => 'member']);
        $aliases = $this->legacyAliasFixtures();
        $this->assertEqualsCanonicalizing(['estimate_reference', 'construction_journal'],
            $this->policy->allowedSourceTypes($this->actor, $this->organization->id, ['estimate_reference', 'construction_journal']));
        $allowed = [];
        foreach ($aliases as $type => [$sourceType, $id]) {
            $source = $this->source($this->organization->id, $this->visible->id, $sourceType, $type, $id);
            $allowed[$type] = $source->id;
            $this->assertTrue($this->policy->canReadSource($this->actor, $this->organization->id, $source->toArray()), $type);
            $unknown = $this->source($this->organization->id, $this->visible->id, 'unknown_legacy_source', $type, $id);
            $this->assertFalse($this->policy->canReadSource($this->actor, $this->organization->id, $unknown->toArray()), $type);
        }
        $foreign = Organization::withoutEvents(fn () => Organization::factory()->create());
        $foreignTemplate = DB::table('estimate_templates')->insertGetId([
            'organization_id' => $foreign->id, 'name' => 'Foreign private template', 'template_structure' => '{}',
            'created_by_user_id' => $this->actor->id, 'is_public' => false,
        ]);
        $foreignEntity = $this->source($this->organization->id, $this->visible->id, 'estimate_reference', 'estimate_template', $foreignTemplate);
        $foreignSource = $this->source($foreign->id, $this->visible->id, 'estimate_reference', 'estimate_template', $aliases['estimate_template'][1]);
        $this->assertFalse($this->policy->canReadSource($this->actor, $this->organization->id, $foreignEntity->toArray()));
        $this->assertFalse($this->policy->canReadSource($this->actor, $this->organization->id, $foreignSource->toArray()));
        $read = fn (): array => $this->policy->applyToSources(RagSource::query(), $this->actor, $this->organization->id)->pluck('id')->all();
        $this->assertEqualsCanonicalizing(array_values($allowed), $read());

        $this->deniedPermissions = ['finance.view'];
        $this->assertEqualsCanonicalizing(array_values(array_diff_key($allowed, ['normative_rate' => true])), $read());
        $normative = RagSource::findOrFail($allowed['normative_rate']);
        $this->assertFalse($this->policy->canReadSource($this->actor, $this->organization->id, $normative->toArray()));

        $this->deniedPermissions = ['estimates.view', 'construction-journal.view'];
        $this->assertSame([], $read());
        foreach ($allowed as $id) {
            $this->assertFalse($this->policy->canReadSource($this->actor, $this->organization->id, RagSource::findOrFail($id)->toArray()));
        }
        $this->deniedPermissions = [];
        $this->actor->assignedProjects()->updateExistingPivot($this->hidden->id, ['is_active' => false]);
        foreach ($aliases as $type => [$sourceType, $id]) {
            $hidden = $this->source($this->organization->id, $this->hidden->id, $sourceType, $type, $id);
            $this->assertFalse($this->policy->canReadSource($this->actor, $this->organization->id, $hidden->toArray()), $type);
            $this->assertSame($type !== 'estimate_template',
                $this->policy->canReadSource($this->actor, $this->organization->id, RagSource::findOrFail($allowed[$type])->toArray()), $type);
        }
        $canonicalTemplate = $this->source($this->organization->id, $this->visible->id, 'core_business', 'estimate_template', $aliases['estimate_template'][1]);
        $this->assertFalse($this->policy->canReadSource($this->actor, $this->organization->id, $canonicalTemplate->toArray()));
        $this->assertEqualsCanonicalizing(array_values(array_diff_key($allowed, ['estimate_template' => true])), $read());
        $this->actor->assignedProjects()->updateExistingPivot($this->visible->id, ['is_active' => false]);
        $this->assertSame([], $read());
        foreach ($allowed as $id) {
            $this->assertFalse($this->policy->canReadSource($this->actor, $this->organization->id, RagSource::findOrFail($id)->toArray()));
        }
    }

    public function test_legacy_aliases_remain_visible_in_expected_and_split_aggregate_scopes(): void
    {
        $this->actor->assignedProjects()->attach($this->hidden->id, ['is_active' => true, 'role' => 'member']);
        $aliases = $this->legacyAliasFixtures();
        $sourceIds = [];
        $expectedIds = [];
        foreach ($aliases as $type => [$sourceType, $id]) {
            $sourceIds[$type] = $this->source($this->organization->id, $this->visible->id, $sourceType, $type, $id)->id;
            $expectedIds[$type] = $this->expected($this->organization->id, $this->visible->id, $sourceType, $type, $id)->id;
            $this->expected($this->organization->id, $this->visible->id, 'unknown_legacy_source', $type, $id);
        }
        $readSources = function (): array {
            $batches = $this->policy->aggregateSourceIdentityBatches(RagSource::query(), $this->actor, $this->organization->id,
                ['ai_rag_sources.id'], static fn ($visible) => DB::query()->fromSub($visible, 'visible')->select('visible.id'));

            return array_merge(...array_map(static fn ($batch): array => $batch->pluck('id')->all(), $batches));
        };
        $readExpected = function (): array {
            $batches = $this->policy->aggregateExpectedSourceIdentityBatches(RagExpectedSource::query(), $this->actor, $this->organization->id,
                ['ai_rag_expected_sources.id'], static fn ($visible) => DB::query()->fromSub($visible, 'visible')->select('visible.id'));

            return array_merge(...array_map(static fn ($batch): array => $batch->pluck('id')->all(), $batches));
        };
        $this->assertEqualsCanonicalizing(array_values($sourceIds), $readSources());
        $this->assertEqualsCanonicalizing(array_values($expectedIds), $readExpected());
        $this->assertEqualsCanonicalizing(array_values($expectedIds),
            $this->policy->applyToExpectedSources(RagExpectedSource::query(), $this->actor, $this->organization->id)->pluck('id')->all());
        $this->deniedPermissions = ['estimates.view', 'construction-journal.view'];
        $this->assertSame([], $readSources());
        $this->assertSame([], $readExpected());
        $this->deniedPermissions = [];
        $this->actor->assignedProjects()->updateExistingPivot($this->hidden->id, ['is_active' => false]);
        foreach ($aliases as $type => [$sourceType, $id]) {
            $this->source($this->organization->id, $this->hidden->id, $sourceType, $type, $id);
            $this->expected($this->organization->id, $this->hidden->id, $sourceType, $type, $id);
        }
        $this->source($this->organization->id, $this->visible->id, 'core_business', 'estimate_template', $aliases['estimate_template'][1]);
        $this->expected($this->organization->id, $this->visible->id, 'core_business', 'estimate_template', $aliases['estimate_template'][1]);
        $this->assertEqualsCanonicalizing(array_values(array_diff_key($sourceIds, ['estimate_template' => true])), $readSources());
        $this->assertEqualsCanonicalizing(array_values(array_diff_key($expectedIds, ['estimate_template' => true])), $readExpected());
        $this->assertEqualsCanonicalizing(array_values(array_diff_key($expectedIds, ['estimate_template' => true])),
            $this->policy->applyToExpectedSources(RagExpectedSource::query(), $this->actor, $this->organization->id)->pluck('id')->all());
        $this->actor->assignedProjects()->updateExistingPivot($this->visible->id, ['is_active' => false]);
        $this->assertSame([], $readSources());
        $this->assertSame([], $readExpected());
        $this->actor->assignedProjects()->updateExistingPivot($this->visible->id, ['is_active' => true]);
        $this->assertEqualsCanonicalizing(array_values(array_diff_key($sourceIds, ['estimate_template' => true])), $readSources());
        $this->assertEqualsCanonicalizing(array_values(array_diff_key($expectedIds, ['estimate_template' => true])), $readExpected());
        $this->actor->organizations()->updateExistingPivot($this->organization->id, ['is_active' => false]);
        $this->assertSame([], $readSources());
        $this->assertSame([], $readExpected());
    }

    public function test_source_id_aggregate_preserves_parts_exact_ids_and_current_scope(): void
    {
        $this->duplicateProjectRows = true;
        $allowed = $this->source($this->organization->id, $this->visible->id, 'project', 'project', $this->visible->id);
        $part = $allowed->replicate()->forceFill(['identity_part_key' => 'second-part']);
        $part->save();
        $shadow = $allowed->replicate()->forceFill(['project_id' => $this->hidden->id, 'identity_part_key' => 'hidden-project']);
        $shadow->save();
        $this->source($this->organization->id, $this->visible->id, 'project', 'project', 999999);
        $invalid = $allowed->replicate()->forceFill(['entity_id' => '0'.$this->visible->id]);
        $invalid->save();
        $this->source($this->organization->id, $this->visible->id, 'project', 'unknown_type', $this->visible->id);
        for ($index = 0; $index < 9; $index++) {
            $this->source($this->organization->id, $this->visible->id, 'unmapped-index-'.$index, 'project', $this->visible->id);
        }
        $read = fn (bool $join) => $this->policy->aggregateSourceIdentities(
            RagSource::query()->from('ai_rag_status_sources as ai_rag_sources'), $this->actor, $this->organization->id,
            ['ai_rag_sources.id'], static fn ($visible) => DB::query()->fromSub($visible, 'visible')->select('visible.id'),
            joinSourceIds: $join,
        );
        $original = $read(false);
        $optimized = $read(true);
        $batches = $this->policy->aggregateSourceIdentityBatches(
            RagSource::query()->from('ai_rag_status_sources as ai_rag_sources'), $this->actor, $this->organization->id,
            ['ai_rag_sources.id'], static fn ($visible) => DB::query()->fromSub($visible, 'visible')->select('visible.id'),
        );
        $this->assertCount(2, $batches);
        $batchIds = static fn (): array => array_merge(...array_map(static fn ($batch): array => $batch->get()->pluck('id')->all(), $batches));
        $this->assertStringContainsString('"assistant_visible_source_ids"', $optimized->toSql());
        $this->assertCount(2, $optimized->get()->pluck('id')->all());
        $this->assertCount(2, $batchIds());
        $this->assertEqualsCanonicalizing([$allowed->id, $part->id], $original->get()->pluck('id')->all());
        $this->assertEqualsCanonicalizing($original->get()->pluck('id')->all(), $optimized->get()->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$allowed->id, $part->id], $batchIds());
        $this->actor->assignedProjects()->updateExistingPivot($this->visible->id, ['is_active' => false]);
        $this->assertSame([], $optimized->get()->pluck('id')->all());
        $this->assertSame([], $batchIds());
        $this->actor->assignedProjects()->updateExistingPivot($this->visible->id, ['is_active' => true]);
        $this->actor->organizations()->updateExistingPivot($this->organization->id, ['is_active' => false]);
        $this->assertSame([], $optimized->get()->pluck('id')->all());
        $this->assertSame([], $batchIds());
    }

    public function test_counter_batches_carry_scoped_rows_without_reading_sources_twice(): void
    {
        $this->duplicateProjectRows = true;
        $this->additionalModules = ['budgeting'];
        $source = $this->source($this->organization->id, $this->visible->id, 'project', 'project', $this->visible->id);
        DB::statement('INSERT INTO ai_rag_sources (organization_id, project_id, identity_project_id, source_type, entity_type, entity_id, identity_part_key, title, checksum, indexed_at) '
            ."SELECT ?, ?, ?, 'project', 'project', ?::text, 'count-part-' || n, 'Counter source', md5('counter'), NOW() FROM generate_series(1, 1000) n",
            [$this->organization->id, $this->visible->id, $this->visible->id, $this->visible->id]);
        $estimate = Estimate::create(['organization_id' => $this->organization->id, 'project_id' => $this->visible->id,
            'number' => 'counter-scope-'.$this->organization->id, 'name' => 'Estimate', 'estimate_date' => today()]);
        $this->source($this->organization->id, $this->visible->id, 'estimate', 'estimate', $estimate->id);
        $source->replicate()->forceFill(['entity_id' => '0'.$this->visible->id])->saveQuietly();
        $source->replicate()->forceFill(['project_id' => $this->hidden->id, 'identity_part_key' => 'hidden'])->saveQuietly();
        $foreign = Organization::withoutEvents(fn () => Organization::factory()->create());
        $source->replicate()->forceFill(['organization_id' => $foreign->id])->saveQuietly();
        $snapshot = DB::table('epm_data_mart_snapshots')->insertGetId([
            'uuid' => (string) \Illuminate\Support\Str::uuid(), 'organization_id' => $this->organization->id,
            'project_id' => $this->visible->id, 'report_scope' => 'project', 'scope_hash' => hash('sha256', 'scope'),
            'status' => 'ready', 'formula_version' => 'v1', 'source_hash' => hash('sha256', 'source'),
            'filters' => '{}', 'payload' => '{}', 'freshness' => '{}', 'source_refs' => '[]', 'generated_at' => now(),
        ]);
        $legacy = $this->source($this->organization->id, $this->visible->id, 'budgeting', 'epm_data_mart_snapshot', $snapshot);
        $legacy->replicate()->forceFill(['identity_part_key' => 'current-revision', 'metadata' => [
            \App\BusinessModules\Features\AIAssistant\Services\DomainMetadata\AssistantFinanceTenderSourceSchema::FIELD => \App\BusinessModules\Features\AIAssistant\Services\DomainMetadata\AssistantFinanceTenderSourceSchema::revision('epm_data_mart_snapshot'),
        ]])->saveQuietly();
        $file = \App\Models\File::withoutEvents(fn () => \App\Models\File::create([
            'organization_id' => $this->organization->id, 'user_id' => $this->actor->id,
            'fileable_type' => Project::class, 'fileable_id' => $this->visible->id,
            'name' => 'Counter.pdf', 'original_name' => 'Counter.pdf', 'path' => 'org-'.$this->organization->id.'/counter.pdf',
            'disk' => 's3', 'mime_type' => 'application/pdf', 'size' => 100,
        ]));
        $document = \App\BusinessModules\Features\AIAssistant\Models\AIAssistantDocument::withoutEvents(fn () => \App\BusinessModules\Features\AIAssistant\Models\AIAssistantDocument::create([
            'organization_id' => $this->organization->id, 'project_id' => $this->visible->id, 'file_id' => $file->id,
            'parent_entity_type' => 'project', 'parent_entity_id' => (string) $this->visible->id,
            'storage_path' => $file->path, 'filename' => 'Counter.pdf', 'mime_type' => 'application/pdf',
            'checksum' => hash('sha256', 'document'), 'size_bytes' => 100, 'status' => 'ready',
        ]));
        $this->source($this->organization->id, $this->visible->id, 'file_document', 'assistant_document', $document->id);
        DB::table('ai_rag_chunks')->insert(['organization_id' => $this->organization->id, 'project_id' => $this->visible->id,
            'source_id' => $source->id, 'chunk_index' => 0, 'content' => 'Counter chunk', 'content_hash' => hash('sha256', 'Counter chunk')]);
        DB::statement('ANALYZE ai_rag_status_sources');
        $aggregate = static fn ($visible) => DB::query()->fromSub($visible, 'accessible_sources')->groupBy('accessible_sources.source_type')
            ->selectRaw('accessible_sources.source_type, COUNT(*) AS stored_count, COALESCE(pg_catalog.SUM(accessible_sources.chunk_count), 0) AS chunk_count, COUNT(CASE WHEN accessible_sources.indexed_chunk_count > 0 THEN 1 END) AS indexed_count');
        $columns = ['ai_rag_sources.id', 'ai_rag_sources.source_type', 'ai_rag_sources.project_id', 'ai_rag_sources.chunk_count', 'ai_rag_sources.indexed_chunk_count'];
        $batches = $this->policy->aggregateSourceIdentityBatches(RagSource::query()->from('ai_rag_status_sources as ai_rag_sources'),
            $this->actor, $this->organization->id, $columns, $aggregate);
        self::assertCount(2, $batches);
        $read = static function () use ($batches): array {
            $result = [];
            foreach ($batches as $batch) {
                foreach ($batch->get() as $row) {
                    $result[$row->source_type] = [(int) $row->stored_count, (int) $row->chunk_count, (int) $row->indexed_count];
                }
            }
            ksort($result);

            return $result;
        };
        self::assertSame(['budgeting' => [1, 0, 0], 'estimate' => [1, 0, 0], 'file_document' => [1, 0, 0], 'project' => [1001, 1, 0]], $read());
        $documentBatches = $this->policy->aggregateSourceIdentityBatches(
            RagSource::query()->from('ai_rag_status_sources as ai_rag_sources')->where('source_type', 'file_document'),
            $this->actor, $this->organization->id, $columns, $aggregate);
        self::assertCount(1, $documentBatches);
        self::assertSame('file_document', $documentBatches[0]->first()->source_type);
        self::assertSame(1, (int) $documentBatches[0]->first()->stored_count);
        $scanned = 0;
        $collect = static function (array $node) use (&$collect, &$scanned): void {
            if (($node['Relation Name'] ?? null) === 'ai_rag_status_sources') {
                $scanned += (($node['Actual Rows'] ?? 0) + ($node['Rows Removed by Filter'] ?? 0)) * ($node['Actual Loops'] ?? 1);
            }
            foreach ($node['Plans'] ?? [] as $child) {
                $collect($child);
            }
        };
        foreach ($batches as $batch) {
            $plan = DB::selectOne('EXPLAIN (ANALYZE, BUFFERS, TIMING FALSE, FORMAT JSON) '.$batch->toSql(), $batch->getBindings());
            $collect(json_decode($plan->{'QUERY PLAN'}, true, 512, JSON_THROW_ON_ERROR)[0]['Plan']);
        }
        self::assertLessThanOrEqual(1200, $scanned, 'Bounded counter batches must not recover the same source rows again after their identity check.');
        DB::table('ai_rag_chunks')->where('source_id', $source->id)->delete();
        self::assertSame(['budgeting' => [1, 0, 0], 'estimate' => [1, 0, 0], 'file_document' => [1, 0, 0], 'project' => [1001, 0, 0]], $read());
        $file->update(['path' => 'counter/replaced.pdf']);
        self::assertSame(['budgeting' => [1, 0, 0], 'estimate' => [1, 0, 0], 'project' => [1001, 0, 0]], $read());
        self::assertNull($documentBatches[0]->first());
        $this->actor->assignedProjects()->updateExistingPivot($this->visible->id, ['is_active' => false]);
        self::assertSame([], $read());
        $this->actor->assignedProjects()->updateExistingPivot($this->visible->id, ['is_active' => true]);
        $this->actor->organizations()->updateExistingPivot($this->organization->id, ['is_active' => false]);
        self::assertSame([], $read());
    }

    public function test_present_report_identity_keeps_structured_content_scope_checks(): void
    {
        $base = [
            'organization_id' => $this->organization->id, 'project_id' => $this->visible->id,
            'report_date' => today(), 'generated_at' => now(), 'required_domains' => ['projects', 'reports'],
            'summary' => [], 'metrics' => [], 'urgent_actions' => [], 'risk_groups' => [],
            'finance' => [], 'activity' => [], 'recommendations' => [],
        ];
        $valid = ProjectPulseReport::withoutEvents(fn (): ProjectPulseReport => ProjectPulseReport::create($base + [
            'source_refs' => [['entity_type' => 'project', 'entity_id' => (string) $this->visible->id]],
        ]));
        $structured = ProjectPulseReport::withoutEvents(fn (): ProjectPulseReport => ProjectPulseReport::create($base + [
            'source_refs' => [['entity_type' => 'project', 'entity_id' => (string) $this->visible->id,
                'content_scope' => 'structured', 'checked_fields' => ['budget_amount'],
                'required_permissions' => ['finance.view'], 'required_domains' => ['finance']]],
        ]));
        $allowed = $this->source($this->organization->id, $this->visible->id, 'project_pulse', 'project_pulse_report', $valid->id);
        $this->source($this->organization->id, $this->visible->id, 'project_pulse', 'project_pulse_report', $structured->id);
        $this->assertSame([$allowed->id], $this->policy->applyToSources(RagSource::query(), $this->actor, $this->organization->id)->pluck('id')->all());
        $this->deniedModules = ['payments'];
        $this->assertSame([], $this->policy->applyToSources(RagSource::query(), $this->actor, $this->organization->id)->pluck('id')->all());
    }

    public function test_expected_batches_keep_canonical_bigint_parts_generation_and_current_parent_access(): void
    {
        $estimate = Estimate::create(['organization_id' => $this->organization->id, 'project_id' => $this->visible->id,
            'number' => 'typed-identity-'.$this->organization->id, 'name' => 'Estimate', 'estimate_date' => today()]);
        $allowed = [];
        foreach ([PHP_INT_MIN, -1, 0, PHP_INT_MAX] as $id) {
            DB::table('estimate_items')->insert(['id' => $id, 'estimate_id' => $estimate->id, 'position_number' => (string) $id, 'name' => 'Item']);
            $row = $this->expected($this->organization->id, $this->visible->id, 'estimate', 'estimate_item', $id);
            $allowed[] = $row->id;
        }
        $part = $row->replicate()->forceFill(['identity_part_key' => 'second-part']);
        $part->save();
        $allowed[] = $part->id;
        foreach (['00', '+0', '-0', ' 0', '0 ', '0.0', '0e0', 'invalid', '9223372036854775808', '-9223372036854775809', '٠'] as $id) {
            $row->replicate()->forceFill(['entity_id' => $id])->save();
        }
        $row->replicate()->forceFill(['generation' => '00000000-0000-4000-8000-000000000002'])->save();
        $row->replicate()->forceFill(['project_id' => $this->hidden->id, 'identity_part_key' => 'hidden-project'])->save();
        $row->replicate()->forceFill(['source_type' => 'project'])->save();
        $foreign = Organization::withoutEvents(fn () => Organization::factory()->create());
        $row->replicate()->forceFill(['organization_id' => $foreign->id])->save();
        $read = function (): array {
            $query = RagExpectedSource::query()->where('generation', '00000000-0000-4000-8000-000000000001')->where('source_type', 'estimate');
            $batches = $this->policy->aggregateExpectedSourceIdentityBatches($query, $this->actor, $this->organization->id,
                ['ai_rag_expected_sources.id'], static fn ($visible) => DB::query()->fromSub($visible, 'visible')->select('visible.id'));

            return array_merge(...array_map(static fn ($batch): array => $batch->get()->pluck('id')->all(), $batches));
        };
        $this->assertEqualsCanonicalizing($allowed, $read());
        DB::table('estimate_items')->where('id', 0)->update(['deleted_at' => now()]);
        $this->assertEqualsCanonicalizing(array_values(array_diff($allowed, [$allowed[2]])), $read());
        $this->actor->assignedProjects()->updateExistingPivot($this->visible->id, ['is_active' => false]);
        $this->assertSame([], $read());
        $this->actor->assignedProjects()->updateExistingPivot($this->visible->id, ['is_active' => true]);
        $this->deniedModules = ['budget-estimates'];
        $this->assertSame([], $read());
    }

    public function test_large_live_only_catalog_projection_stays_excluded_and_aggregate_scope_remains_current(): void
    {
        $this->actor->assignedProjects()->attach($this->hidden->id, ['is_active' => true, 'role' => 'member']);
        $resource = DB::table('normative_resources')->insertGetId(['code' => 'batch-resource', 'name' => 'Resource', 'type' => 'material']);
        DB::statement("INSERT INTO normative_resources (code, name, type) SELECT 'unused-plan-resource-' || n, 'Unused resource', 'material' FROM generate_series(1, 5000) n");
        $balance = DB::table('organization_balances')->insertGetId(['organization_id' => $this->organization->id]);
        DB::statement("INSERT INTO ai_rag_expected_sources (organization_id, project_id, identity_project_id, generation, source_type, entity_type, entity_id, identity_part_key, checksum, pending_since) "
            ."SELECT ?, ?, ?, '00000000-0000-4000-8000-000000000001'::uuid, 'core_business_money', 'core_normative_resource', ?::text, n::text, md5('resource'), NOW() FROM generate_series(1, 10001) n",
            [$this->organization->id, $this->visible->id, $this->visible->id, $resource]);
        $row = $this->expected($this->organization->id, $this->visible->id, 'core_business_money', 'core_organization_balance', $balance);
        $row->replicate()->forceFill(['entity_id' => '0'.$balance])->save();
        $read = function (bool $checkExclusionPlan = false): array {
            $batches = $this->policy->aggregateExpectedSourceIdentityBatches(
                RagExpectedSource::query()->where('generation', '00000000-0000-4000-8000-000000000001'),
                $this->actor, $this->organization->id, ['ai_rag_expected_sources.id'],
                static fn ($visible) => DB::query()->fromSub($visible, 'visible')->selectRaw('COUNT(*) AS total'),
            );
            if ($checkExclusionPlan) {
                $nativeScans = [];
                $collectScans = static function (array $node) use (&$collectScans, &$nativeScans): void {
                    if (($node['Relation Name'] ?? null) === 'normative_resources') {
                        $nativeScans[] = $node;
                    }
                    foreach ($node as $value) {
                        if (is_array($value)) { $collectScans($value); }
                    }
                };
                foreach ($batches as $batch) {
                    $result = DB::selectOne('EXPLAIN (ANALYZE, FORMAT JSON) '.$batch->toSql(), $batch->getBindings());
                    $collectScans(json_decode(((array) $result)['QUERY PLAN'], true, 512, JSON_THROW_ON_ERROR));
                }
                $this->assertSame([], $nativeScans);
            }
            $counts = array_map(static fn ($batch): int => (int) $batch->first()->total, $batches);
            sort($counts);

            return $counts;
        };
        $this->assertSame([0, 1], $read(true));
        $this->deniedModules = ['budget-estimates'];
        $this->assertSame([0, 1], $read());
        $this->deniedModules = [];
        $this->actor->assignedProjects()->updateExistingPivot($this->hidden->id, ['is_active' => false]);
        $this->assertSame([0, 0], $read());
        $this->actor->assignedProjects()->updateExistingPivot($this->visible->id, ['is_active' => false]);
        $this->assertSame(0, array_sum($read()));
    }

    private function legacyAliasFixtures(): array
    {
        $template = DB::table('estimate_templates')->insertGetId([
            'organization_id' => $this->organization->id, 'name' => 'Alias template', 'template_structure' => '{}',
            'created_by_user_id' => $this->actor->id, 'is_public' => false,
        ]);
        $library = DB::table('estimate_libraries')->insertGetId([
            'organization_id' => $this->organization->id, 'created_by_user_id' => $this->actor->id,
            'name' => 'Alias library', 'access_level' => 'private', 'is_active' => true,
        ]);
        $item = DB::table('estimate_library_items')->insertGetId(['library_id' => $library, 'name' => 'Alias item']);
        $base = DB::table('normative_base_types')->insertGetId(['code' => 'alias-base-'.$this->organization->id, 'name' => 'Alias base', 'is_active' => true]);
        $collection = DB::table('normative_collections')->insertGetId(['base_type_id' => $base, 'code' => 'alias', 'name' => 'Alias collection', 'is_active' => true]);
        $rate = DB::table('normative_rates')->insertGetId(['collection_id' => $collection, 'code' => 'alias-rate', 'name' => 'Alias rate']);
        $estimate = Estimate::withoutEvents(fn () => Estimate::create([
            'organization_id' => $this->organization->id, 'project_id' => $this->visible->id,
            'number' => 'alias-estimate-'.$this->organization->id, 'name' => 'Alias estimate', 'estimate_date' => today(),
        ]));
        DB::table('estimate_items')->insert(['estimate_id' => $estimate->id, 'position_number' => '1', 'name' => 'Alias rate item', 'normative_rate_id' => $rate]);
        $journal = DB::table('construction_journals')->insertGetId([
            'organization_id' => $this->organization->id, 'performing_organization_id' => $this->organization->id,
            'project_id' => $this->visible->id, 'name' => 'Alias journal', 'start_date' => today(), 'created_by_user_id' => $this->actor->id,
        ]);
        $entry = DB::table('construction_journal_entries')->insertGetId([
            'journal_id' => $journal, 'entry_date' => today(), 'entry_number' => 1,
            'work_description' => 'Alias journal entry', 'created_by_user_id' => $this->actor->id,
        ]);

        return ['estimate_template' => ['estimate_reference', $template], 'estimate_library_item' => ['estimate_reference', $item],
            'normative_rate' => ['estimate_reference', $rate], 'construction_journal_entry' => ['construction_journal', $entry]];
    }

    private function source(int $organizationId, int $projectId, string $sourceType, string $entityType, int $entityId): RagSource
    {
        return RagSource::withoutEvents(fn (): RagSource => RagSource::create([
            'organization_id' => $organizationId, 'project_id' => $projectId, 'identity_project_id' => $projectId,
            'source_type' => $sourceType, 'entity_type' => $entityType, 'entity_id' => (string) $entityId,
            'title' => 'ACL source', 'checksum' => hash('sha256', $sourceType.$entityType.$entityId), 'indexed_at' => now(),
        ]));
    }

    private function expected(int $organizationId, int $projectId, string $sourceType, string $entityType, int $entityId): RagExpectedSource
    {
        return RagExpectedSource::create([
            'organization_id' => $organizationId, 'project_id' => $projectId, 'identity_project_id' => $projectId, 'generation' => '00000000-0000-4000-8000-000000000001',
            'source_type' => $sourceType, 'entity_type' => $entityType, 'entity_id' => (string) $entityId,
            'checksum' => hash('sha256', $sourceType.$entityType.$entityId), 'pending_since' => now(),
        ]);
    }
}
