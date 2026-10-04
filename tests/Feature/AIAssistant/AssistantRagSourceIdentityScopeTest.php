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

    protected function setUp(): void
    {
        parent::setUp();
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->shouldReceive('canCurrent')->andReturn(true);
        $authorization->shouldReceive('forCurrentChecks')->andReturnSelf();
        $authorization->shouldReceive('getUserRoles')->andReturn(collect());
        $modules = Mockery::mock(OrganizationEntitlementService::class);
        $modules->shouldReceive('getEffectiveModules')->andReturnUsing(fn () => collect([
            'ai-assistant', 'project-management', 'payments', 'budget-estimates', 'reports',
        ])->reject(fn (string $slug): bool => in_array($slug, $this->deniedModules, true))
            ->map(static fn (string $slug): object => (object) ['slug' => $slug]));
        $this->policy = new AssistantDataAccessPolicy($authorization, new UserProjectAccessService, $modules);
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

    public function test_source_id_aggregate_preserves_parts_exact_ids_and_current_scope(): void
    {
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

    private function source(int $organizationId, int $projectId, string $sourceType, string $entityType, int $entityId): RagSource
    {
        return RagSource::withoutEvents(fn (): RagSource => RagSource::create([
            'organization_id' => $organizationId, 'project_id' => $projectId,
            'source_type' => $sourceType, 'entity_type' => $entityType, 'entity_id' => (string) $entityId,
            'title' => 'ACL source', 'checksum' => hash('sha256', $sourceType.$entityType.$entityId), 'indexed_at' => now(),
        ]));
    }

    private function expected(int $organizationId, int $projectId, string $sourceType, string $entityType, int $entityId): RagExpectedSource
    {
        return RagExpectedSource::create([
            'organization_id' => $organizationId, 'project_id' => $projectId, 'generation' => '00000000-0000-4000-8000-000000000001',
            'source_type' => $sourceType, 'entity_type' => $entityType, 'entity_id' => (string) $entityId,
            'checksum' => hash('sha256', $sourceType.$entityType.$entityId), 'pending_since' => now(),
        ]);
    }
}
