<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationPackage;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationPackageItem;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimateDatasetVersion;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimateNormCollection;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\Models\ReportSavedViewRecord;
use App\BusinessModules\Core\Reporting\Application\Contracts\Access\ReportModuleEntitlement;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Infrastructure\Access\LaravelReportModuleEntitlement;
use App\BusinessModules\Core\Mdm\Models\MdmRecord;
use App\BusinessModules\Enterprise\MultiOrganization\Website\Domain\Models\HoldingSite;
use App\BusinessModules\Enterprise\MultiOrganization\Website\Domain\Models\HoldingSitePage;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\DomainMetadata\AssistantOrganizationReportingMetadata;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Module;
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

    public function test_norm_resource_pagination_keeps_only_published_parents_and_same_collection_sections(): void
    {
        $source = new OrganizationReportingRagSource;
        $published = [];
        $stale = [];
        foreach (['fsnb_2022', 'fsbc', 'fgis_labor_prices'] as $type) {
            foreach (['old', 'current', 'unfinished', 'errors', 'empty'] as $version) {
                $id = DB::table('estimate_dataset_versions')->insertGetId([
                    'source_type' => $type, 'version_key' => $version, 'bucket' => 'testing', 'prefix' => 'norms', 'status' => 'parsed',
                    'finished_at' => $version === 'unfinished' ? null : now()->addDays($version === 'old' ? -1 : ($version === 'current' ? 0 : 1)),
                    'rows_imported' => $version === 'empty' ? 0 : 100, 'errors_count' => $version === 'errors' ? 1 : 0,
                ]);
                $resource = DB::table('construction_resources')->insertGetId(['dataset_version_id' => $id, 'ksr_code' => $version, 'name' => 'Ресурс', 'resource_type' => 'material']);
                if ($version === 'current') { $published[$type] = ['dataset' => $id, 'resource' => $resource]; }
                else { $stale[] = $resource; }
            }
        }
        $collection = DB::table('estimate_norm_collections')->insertGetId(['dataset_version_id' => $published['fsnb_2022']['dataset'], 'code' => 'current', 'name' => 'Сборник', 'norm_type' => 'gesn', 'source_file' => 'testing.xml']);
        $otherCollection = DB::table('estimate_norm_collections')->insertGetId(['dataset_version_id' => $published['fsnb_2022']['dataset'], 'code' => 'other', 'name' => 'Другой сборник', 'norm_type' => 'gesn', 'source_file' => 'testing.xml']);
        $wrongSourceCollection = DB::table('estimate_norm_collections')->insertGetId(['dataset_version_id' => $published['fsbc']['dataset'], 'code' => 'wrong-source', 'name' => 'Не нормы', 'norm_type' => 'gesn', 'source_file' => 'testing.xml']);
        $section = DB::table('estimate_norm_sections')->insertGetId(['collection_id' => $collection, 'name' => 'Раздел', 'path' => '1']);
        $otherSection = DB::table('estimate_norm_sections')->insertGetId(['collection_id' => $otherCollection, 'name' => 'Другой раздел', 'path' => '2']);
        $norm = DB::table('estimate_norms')->insertGetId(['collection_id' => $collection, 'section_id' => $section, 'code' => 'valid', 'name' => 'Норма', 'unit' => 'м2']);
        $wrongSectionNorm = DB::table('estimate_norms')->insertGetId(['collection_id' => $collection, 'section_id' => $otherSection, 'code' => 'wrong-section', 'name' => 'Норма', 'unit' => 'м2']);
        $wrongSourceNorm = DB::table('estimate_norms')->insertGetId(['collection_id' => $wrongSourceCollection, 'code' => 'wrong-source', 'name' => 'Норма', 'unit' => 'м2']);
        $expected = [];
        $resources = [null, ...array_column($published, 'resource')];
        for ($n = 0; $n < 65; $n++) {
            DB::table('estimate_norm_resources')->insert(['estimate_norm_id' => $norm, 'construction_resource_id' => $stale[$n % count($stale)]]);
            DB::table('estimate_norm_resources')->insert(['estimate_norm_id' => $n % 2 === 0 ? $wrongSectionNorm : $wrongSourceNorm]);
            $expected[] = DB::table('estimate_norm_resources')->insertGetId(['estimate_norm_id' => $norm, 'construction_resource_id' => $resources[$n % count($resources)]]);
        }
        $query = $source->scopedQuery('approved_estimate_norm_resource', 1);
        self::assertSame($expected, $query->lazyById(50)->pluck('id')->all());
        self::assertSame([$norm], $source->scopedQuery('approved_estimate_norm', 1)->orderBy('id')->pluck('id')->all());
        self::assertSame(array_column($published, 'resource'), $source->scopedQuery('approved_construction_resource', 1)->orderBy('id')->pluck('id')->all());
        self::assertSame([], $source->scopedQuery('approved_estimate_norm_resource', 0)->get()->all());
        self::assertSame([], $source->scopedQuery('approved_estimate_norm_resource', 1, 1)->get()->all());
    }

    public function test_resource_price_pagination_preserves_nullable_resources_and_dataset_and_regional_publication(): void
    {
        $source = new OrganizationReportingRagSource;
        $published = [];
        $stale = [];
        $finishedAt = now()->startOfSecond();
        foreach (['fsnb_2022', 'fsbc', 'fgis_labor_prices'] as $type) {
            foreach (['old', 'current-tie', 'current', 'unfinished', 'errors', 'empty'] as $version) {
                $dataset = DB::table('estimate_dataset_versions')->insertGetId([
                    'source_type' => $type, 'version_key' => 'price-'.$version, 'bucket' => 'testing', 'prefix' => 'prices', 'status' => 'parsed',
                    'finished_at' => $version === 'unfinished' ? null : $finishedAt->copy()->addDays($version === 'old' ? -1 : (in_array($version, ['current-tie', 'current'], true) ? 0 : 1)),
                    'rows_imported' => $version === 'empty' ? 0 : 100, 'errors_count' => $version === 'errors' ? 1 : 0,
                ]);
                $resource = DB::table('construction_resources')->insertGetId(['dataset_version_id' => $dataset, 'ksr_code' => 'price-'.$version, 'name' => 'Ресурс', 'resource_type' => 'material']);
                if ($version === 'current') { $published[$type] = ['dataset' => $dataset, 'resource' => $resource]; }
                else { $stale[] = ['dataset' => $dataset, 'resource' => $resource]; }
            }
        }
        $priceNumber = 0;
        $insertPrice = static function (array $attributes = []) use ($published, &$priceNumber): int {
            return DB::table('estimate_resource_prices')->insertGetId([
                'dataset_version_id' => $published['fsnb_2022']['dataset'], 'construction_resource_id' => null,
                'resource_code' => 'price-'.++$priceNumber, 'base_price' => 10, 'price_type' => 'material', ...$attributes,
            ]);
        };
        $expected = [];
        $resources = [null, ...array_column($published, 'resource')];
        $datasets = array_column($published, 'dataset');
        for ($n = 0; $n < 65; $n++) {
            $insertPrice(['construction_resource_id' => $stale[$n % count($stale)]['resource']]);
            $insertPrice(['dataset_version_id' => $stale[$n % count($stale)]['dataset']]);
            $expected[] = $insertPrice(['dataset_version_id' => $datasets[$n % count($datasets)], 'construction_resource_id' => $resources[$n % count($resources)]]);
        }
        foreach ([null, 0, -1] as $basePrice) { $insertPrice(['base_price' => $basePrice]); }
        $regions = [];
        $zones = [];
        foreach ([true, false] as $n => $supported) {
            $regions[] = DB::table('estimate_regions')->insertGetId(['code' => 'price-'.$n, 'name' => 'Регион '.$n, 'fgiscs_subject_id' => 10001 + $n, 'is_supported' => $supported]);
            $zones[] = DB::table('estimate_price_zones')->insertGetId(['estimate_region_id' => $regions[$n], 'name' => 'Зона '.$n, 'fgiscs_price_zone_id' => 10001 + $n]);
        }
        $periods = [];
        foreach ([1, 2] as $quarter) {
            $periods[] = DB::table('estimate_price_periods')->insertGetId(['fgiscs_period_id' => 10000 + $quarter, 'name' => 'Период '.$quarter, 'year' => 2091, 'quarter' => $quarter]);
        }
        $versions = [];
        foreach (['active', 'unactivated', 'draft', 'unsupported'] as $status) {
            $scope = $status === 'unsupported' ? 1 : 0;
            $versions[$status] = DB::table('estimate_regional_price_versions')->insertGetId([
                'source' => 'testing', 'region_id' => $regions[$scope], 'price_zone_id' => $zones[$scope],
                'period_id' => $periods[0], 'version_key' => $status, 'status' => 'draft',
            ]);
            $attributes = ['dataset_version_id' => $stale[0]['dataset'], 'regional_price_version_id' => $versions[$status],
                'region_id' => $regions[$scope], 'price_zone_id' => $zones[$scope], 'period_id' => $periods[0]];
            foreach ([null, $published['fsbc']['resource'], $stale[0]['resource']] as $resource) {
                $id = $insertPrice([...$attributes, 'construction_resource_id' => $resource]);
                if ($status === 'active' && $resource !== $stale[0]['resource']) { $expected[] = $id; }
            }
            if ($status === 'active') {
                foreach (['region_id' => $regions[1], 'price_zone_id' => $zones[1], 'period_id' => $periods[1]] as $dimension => $value) {
                    $insertPrice([...$attributes, $dimension => $value]);
                }
            }
        }
        DB::table('estimate_regional_price_versions')->whereIn('id', [$versions['active'], $versions['unactivated'], $versions['unsupported']])->update(['status' => 'active']);
        foreach (['active' => 0, 'unsupported' => 1] as $status => $scope) {
            DB::table('estimate_regional_price_activations')->insert(['region_id' => $regions[$scope], 'price_zone_id' => $zones[$scope], 'active_version_id' => $versions[$status], 'activated_at' => now(), 'activation_reason' => 'testing']);
        }
        $query = $source->scopedQuery('approved_estimate_resource_price', 1);
        DB::enableQueryLog();
        try {
            self::assertSame($expected, $query->lazyById(50)->pluck('id')->all());
            self::assertCount(2, DB::getQueryLog());
        } finally { DB::disableQueryLog(); DB::flushQueryLog(); }
        self::assertSame($expected, $source->scopedQuery('approved_estimate_resource_price', 2)->lazyById(50)->pluck('id')->all());
        self::assertSame([], $source->scopedQuery('approved_estimate_resource_price', 0)->get()->all());
        self::assertSame([], $source->scopedQuery('approved_estimate_resource_price', 1, 1)->get()->all());
        $models = (new \ReflectionMethod($source, 'priceModels'))->invoke($source, 1, null);
        $actual = collect(iterator_to_array($models, false))->pluck('id')->all();
        self::assertEqualsCanonicalizing($expected, $actual);
        self::assertCount(count($expected), array_unique($actual));
        self::assertSame([], iterator_to_array((new \ReflectionMethod($source, 'priceModels'))->invoke($source, 0, null)));
        self::assertSame([], iterator_to_array((new \ReflectionMethod($source, 'priceModels'))->invoke($source, 1, 1)));
        DB::table('estimate_regional_price_activations')->where('active_version_id', $versions['active'])->delete();
        $afterRevocation = collect(iterator_to_array((new \ReflectionMethod($source, 'priceModels'))->invoke($source, 1, null), false))->pluck('id')->all();
        self::assertEqualsCanonicalizing(array_slice($expected, 0, 65), $afterRevocation);
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

    public function test_report_card_scope_reads_each_source_module_once_and_rechecks_next_operation(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create(array_column(app(PackageCatalogService::class)->allPackages(), 'slug'));
        $organizationId = (int) $fixture->organization->id;
        $registry = app(ReportDefinitionRegistry::class);
        $modules = array_values(array_unique(array_map(
            static fn (string $code): string => $registry->published($code)->definition->sourceModule,
            $registry->publishedCodes(),
        )));
        self::assertGreaterThan(1, count($modules));

        $entitlements = new RecordingAssistantReportModuleEntitlement(app(LaravelReportModuleEntitlement::class));
        $this->app->instance(ReportModuleEntitlement::class, $entitlements);
        $policy = app(AssistantDataAccessPolicy::class);
        $first = ReportSavedViewRecord::query();
        $policy->withCurrentChecks($fixture->owner, $organizationId, function ($authorization) use ($first, $fixture, $organizationId, $policy): void {
            AssistantOrganizationReportingMetadata::applyActorScope('report_saved_view_card', $first, $fixture->owner, $organizationId, $authorization, $policy);
            for ($read = 0; $read < 4; $read++) {
                $next = ReportSavedViewRecord::query();
                AssistantOrganizationReportingMetadata::applyActorScope('report_saved_view_card', $next, $fixture->owner, $organizationId, $authorization, $policy);
                self::assertSame($first->getBindings(), $next->getBindings());
            }
        }, fresh: true);

        self::assertSame(array_fill_keys($modules, 1), $entitlements->calls);
        $allowedCodes = $first->getBindings();
        self::assertNotEmpty($allowedCodes);
        $allowedCode = $allowedCodes[0];
        $revokedModule = $registry->published($allowedCode)->definition->sourceModule;
        self::assertSame(1, Module::query()->where('slug', $revokedModule)->update(['is_active' => false]));

        $entitlements->calls = [];
        $second = ReportSavedViewRecord::query();
        AssistantOrganizationReportingMetadata::applyActorScope(
            'report_saved_view_card', $second, $fixture->owner, $organizationId,
            app(AuthorizationService::class)->forCurrentChecks(true), $policy,
        );

        self::assertSame(array_fill_keys($modules, 1), $entitlements->calls);
        self::assertNotContains($allowedCode, $second->getBindings());
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

final class RecordingAssistantReportModuleEntitlement implements ReportModuleEntitlement
{
    public array $calls = [];

    public function __construct(private readonly ReportModuleEntitlement $delegate) {}

    public function organizationHasModule(int $organizationId, string $moduleSlug): bool
    {
        $this->calls[$moduleSlug] = ($this->calls[$moduleSlug] ?? 0) + 1;

        return $this->delegate->organizationHasModule($organizationId, $moduleSlug);
    }
}
