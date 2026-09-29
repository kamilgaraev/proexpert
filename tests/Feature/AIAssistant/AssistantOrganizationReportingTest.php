<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationPackage;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationPackageItem;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimateDatasetVersion;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimateNormCollection;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\Models\ReportSavedViewRecord;
use App\BusinessModules\Core\Mdm\Models\MdmRecord;
use App\BusinessModules\Enterprise\MultiOrganization\Website\Domain\Models\HoldingSite;
use App\BusinessModules\Enterprise\MultiOrganization\Website\Domain\Models\HoldingSitePage;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\OrganizationReportingRagSource;
use App\Models\OrganizationGroup;
use App\Models\Project;
use App\Services\Modules\PackageCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

final class AssistantOrganizationReportingTest extends TestCase
{
    use RefreshDatabase;

    public function test_collectors_use_actual_tables_and_never_read_model_prompts_queries_tokens_or_rollups(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create(array_column(app(PackageCatalogService::class)->allPackages(),'slug'));
        $project = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $fixture->organization->id]));
        $session = $this->createGenerationSession($fixture->organization->id,$project->id,$fixture->owner->id);
        DB::enableQueryLog();
        try {
            $chunks = iterator_to_array((function () use ($fixture) { yield from (new OrganizationReportingRagSource)->collectForOrganization($fixture->organization->id); })());
            $queries = array_column(DB::getQueryLog(),'query');
        } finally { DB::disableQueryLog(); DB::flushQueryLog(); }
        $chunk = array_values(array_filter($chunks,static fn ($chunk): bool => $chunk->entityType === 'estimate_generation_session_card' && (int) $chunk->entityId === $session->id))[0];
        self::assertSame($project->id,$chunk->projectId);
        self::assertStringNotContainsString('private-provider-secret',$chunk->content);
        foreach ($queries as $query) {
            foreach (['input_payload','definition_snapshot','canonical_query_json','execution_input_bytes','storage_path'] as $secretColumn) {
                self::assertStringNotContainsString('"'.$secretColumn.'"',$query);
            }
        }
        self::assertTrue(app(AssistantDataAccessPolicy::class)->canReadEntity($fixture->owner,$fixture->organization->id,'estimate_generation_session_card',$session->id));
    }

    public function test_private_author_and_private_project_are_filtered_before_limit_even_for_organization_owner(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create(array_column(app(PackageCatalogService::class)->allPackages(),'slug'));
        $visible = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $fixture->organization->id]));
        $hidden = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $fixture->organization->id]));
        $fixture->memberRole->update(['module_permissions' => ['ai-assistant' => ['ai_assistant.chat'],'ai-estimates' => ['estimate_generation.view'],'project-management' => ['projects.view']]]);
        $fixture->member->assignedProjects()->attach($visible->id,['is_active' => true,'role' => 'member']);
        $private = $this->createGenerationSession($fixture->organization->id,$visible->id,$fixture->member->id);
        $hiddenSession = $this->createGenerationSession($fixture->organization->id,$hidden->id,$fixture->member->id);
        $owned = $this->createGenerationSession($fixture->organization->id,$visible->id,$fixture->owner->id);
        $policy = app(AssistantDataAccessPolicy::class);
        self::assertFalse($policy->canReadEntity($fixture->owner,$fixture->organization->id,'estimate_generation_session_card',$private->id));
        self::assertFalse($policy->canReadEntity($fixture->member,$fixture->organization->id,'estimate_generation_session_card',$hiddenSession->id));
        self::assertSame($owned->id,$policy->entityQuery($fixture->owner,$fixture->organization->id,'estimate_generation_session_card')->orderBy('id')->limit(1)->firstOrFail()->id);
        self::assertSame($private->id,$policy->entityQuery($fixture->member,$fixture->organization->id,'estimate_generation_session_card')->orderBy('id')->limit(1)->firstOrFail()->id);
    }

    public function test_inherited_foreign_session_and_holding_site_parents_are_denied_before_limit(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create(array_column(app(PackageCatalogService::class)->allPackages(),'slug'));
        $foreignProject = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $fixture->foreignOrganization->id]));
        $foreignSession = $this->createGenerationSession($fixture->foreignOrganization->id,$foreignProject->id,$fixture->foreignOwner->id);
        $package = EstimateGenerationPackage::withoutEvents(fn () => EstimateGenerationPackage::query()->create(['session_id' => $foreignSession->id,'key' => 'foreign','title' => 'Чужая смета','scope_type' => 'local']));
        $group = OrganizationGroup::query()->create(['parent_organization_id' => $fixture->foreignOrganization->id,'name' => 'Чужая группа','slug' => 'foreign-'.Str::lower(Str::random(10)),'created_by_user_id' => $fixture->foreignOwner->id,'status' => 'active']);
        $site = HoldingSite::query()->create(['organization_group_id' => $group->id,'title' => 'Чужой сайт','status' => 'draft']);
        $page = HoldingSitePage::query()->create(['holding_site_id' => $site->id,'page_type' => 'custom','slug' => 'foreign','title' => 'Чужая страница','created_by_user_id' => $fixture->foreignOwner->id]);
        $policy = app(AssistantDataAccessPolicy::class);
        self::assertFalse($policy->canReadEntity($fixture->owner,$fixture->organization->id,'estimate_generation_package_card',$package->id));
        self::assertFalse($policy->canReadEntity($fixture->owner,$fixture->organization->id,'holding_site_page',$page->id));
        self::assertNull($policy->entityQuery($fixture->owner,$fixture->organization->id,'holding_site_page')?->limit(1)->first());
        self::assertNull((new OrganizationReportingRagSource)->scopedQuery('holding_site_page',$fixture->organization->id)->limit(1)->first());
        self::assertSame([],iterator_to_array((function () use ($fixture,$package) { yield from (new OrganizationReportingRagSource)->collectEntity($fixture->organization->id,'estimate_generation_package_card',$package->id); })()));
    }

    public function test_package_amounts_require_current_financial_permission_and_revoke_immediately(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create(array_column(app(PackageCatalogService::class)->allPackages(),'slug'));
        $project = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $fixture->organization->id]));
        $fixture->member->assignedProjects()->attach($project->id,['is_active' => true,'role' => 'member']);
        $permissions = ['ai-assistant' => ['ai_assistant.chat'],'ai-estimates' => ['estimate_generation.view'],'project-management' => ['projects.view']];
        $fixture->memberRole->update(['module_permissions' => $permissions]);
        $session = $this->createGenerationSession($fixture->organization->id,$project->id,$fixture->member->id);
        $package = EstimateGenerationPackage::withoutEvents(fn () => EstimateGenerationPackage::query()->create(['session_id' => $session->id,'key' => 'own','title' => 'Смета','scope_type' => 'local']));
        $item = EstimateGenerationPackageItem::withoutEvents(fn () => EstimateGenerationPackageItem::query()->create(['package_id' => $package->id,'key' => 'work','name' => 'Работы','unit' => 'м2','quantity' => 3,'unit_price' => 10,'total_cost' => 30]));
        $policy = app(AssistantDataAccessPolicy::class);
        self::assertFalse($policy->canReadEntity($fixture->member,$fixture->organization->id,'estimate_generation_item_card',$item->id));
        $fixture->memberRole->update(['module_permissions' => $permissions + ['payments' => ['finance.view'],'budget-estimates' => ['budget-estimates.finance.view']]]);
        self::assertTrue($policy->canReadEntity($fixture->member,$fixture->organization->id,'estimate_generation_item_card',$item->id));
        $fixture->memberRole->update(['module_permissions' => $permissions]);
        self::assertFalse($policy->canReadEntity($fixture->member,$fixture->organization->id,'estimate_generation_item_card',$item->id));
    }

    public function test_normative_lookup_rejects_parsed_incomplete_error_and_stale_datasets_before_limit(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create(array_column(app(PackageCatalogService::class)->allPackages(),'slug'));
        foreach ([['finished_at' => null,'rows_imported' => 1,'errors_count' => 0],['finished_at' => now(),'rows_imported' => 1,'errors_count' => 1],
            ['finished_at' => now()->subDay(),'rows_imported' => 1,'errors_count' => 0],['finished_at' => now(),'rows_imported' => 1,'errors_count' => 0]] as $attributes) {
            $dataset = EstimateDatasetVersion::query()->create(['source_type' => 'fsnb_2022','version_key' => 'proof-'.Str::uuid(),'status' => 'parsed','bucket' => 'private','prefix' => 'private-provider-secret',...$attributes]);
            $collection = EstimateNormCollection::query()->create(['dataset_version_id' => $dataset->id,'code' => 'proof-'.Str::uuid(),'name' => 'Сборник','norm_type' => 'gesn','source_file' => 'proof-norm-collection.xml']);
        }
        $policy = app(AssistantDataAccessPolicy::class);
        self::assertSame($collection->id,$policy->entityQuery($fixture->owner,$fixture->organization->id,'approved_estimate_norm_collection')->orderBy('id')->limit(1)->firstOrFail()->id);
        self::assertSame($dataset->id,(new OrganizationReportingRagSource)->scopedQuery('approved_estimate_dataset',$fixture->organization->id)->orderBy('id')->limit(1)->firstOrFail()->id);
    }

    public function test_saved_report_is_private_to_current_owner_before_limit_and_after_role_revoke(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create(array_column(app(PackageCatalogService::class)->allPackages(),'slug'));
        $registry = app(\App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry::class);
        $codes = $registry->publishedCodes();
        self::assertNotEmpty($codes);
        $code = $codes[0];
        $private = ReportSavedViewRecord::query()->create(['id' => (string) Str::ulid(),'organization_id' => $fixture->organization->id,
            'owner_id' => $fixture->member->id,'report_code' => $code,'contract_version' => '1.0','name' => 'Приватный отчёт',
            'filters_json' => [],'sort_json' => [],'columns_json' => []]);
        $own = ReportSavedViewRecord::query()->create(['id' => (string) Str::ulid(),'organization_id' => $fixture->organization->id,
            'owner_id' => $fixture->owner->id,'report_code' => $code,'contract_version' => '1.0','name' => 'Мой отчёт',
            'filters_json' => [],'sort_json' => [],'columns_json' => []]);
        $policy = app(AssistantDataAccessPolicy::class);
        self::assertFalse($policy->canReadEntity($fixture->owner,$fixture->organization->id,'report_saved_view_card',$private->id));
        self::assertSame($own->id,$policy->entityQuery($fixture->owner,$fixture->organization->id,'report_saved_view_card')->orderBy('id')->limit(1)->firstOrFail()->id);
        $fixture->ownerAssignment->update(['is_active' => false]);
        self::assertFalse($policy->canReadEntity($fixture->owner,$fixture->organization->id,'report_saved_view_card',$own->id));
    }

    public function test_mdm_polymorphic_project_ancestor_is_current_and_filtered_before_limit(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create(array_column(app(PackageCatalogService::class)->allPackages(),'slug'));
        $fixture->memberRole->update(['module_permissions' => ['ai-assistant' => ['ai_assistant.chat'],'catalog-management' => ['mdm.view'],
            'project-management' => ['projects.view']]]);
        $visible = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $fixture->organization->id]));
        $hidden = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $fixture->organization->id]));
        $fixture->member->assignedProjects()->attach($visible->id,['is_active' => true,'role' => 'member']);
        foreach ([$hidden,$visible] as $project) {
            $record = MdmRecord::query()->create(['organization_id' => $fixture->organization->id,'entity_type' => 'project',
                'entity_id' => $project->id,'display_name' => 'Проект','status' => 'active']);
        }
        $policy = app(AssistantDataAccessPolicy::class);
        self::assertSame($record->id,$policy->entityQuery($fixture->member,$fixture->organization->id,'mdm_record')->orderBy('id')->limit(1)->firstOrFail()->id);
        $fixture->member->assignedProjects()->detach($visible->id);
        self::assertFalse($policy->canReadEntity($fixture->member,$fixture->organization->id,'mdm_record',$record->id));
    }

    private function createGenerationSession(int $organizationId,int $projectId,int $userId): EstimateGenerationSession
    {
        return EstimateGenerationSession::withoutEvents(fn () => EstimateGenerationSession::query()->create(['organization_id' => $organizationId,
            'project_id' => $projectId,'user_id' => $userId,'input_payload' => ['note' => 'private-provider-secret']]));
    }
}
