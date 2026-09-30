<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\DomainMetadata\AssistantOrganizationReportingMetadata as Metadata;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\TestCase;

final class AssistantOrganizationReportingMetadataTest extends TestCase
{
    public function test_every_model_in_five_main_groups_has_explicit_fields_or_reasoned_exclusion(): void
    {
        $root = dirname(__DIR__,3);
        $directories = ['app/BusinessModules/Core/Mdm/Models',
            'app/BusinessModules/Core/MultiOrganization/Models','app/BusinessModules/Core/MultiOrganization/Reporting/Models',
            'app/BusinessModules/Enterprise/MultiOrganization/Website/Domain/Models',
            'app/BusinessModules/Core/Reporting/Infrastructure/Persistence/Models','app/BusinessModules/Addons/EstimateGeneration/Models',
            'app/BusinessModules/Addons/EstimateGeneration/Normatives/Models'];
        $declared = array_column(Metadata::records(),0);
        $covered = [...$declared,...array_keys(Metadata::excludedModels()),...array_keys(Metadata::existingCoverage())];
        $count = 0;
        foreach ($directories as $directory) {
            foreach (glob($root.'/'.$directory.'/*.php') ?: [] as $path) {
                $source = (string) file_get_contents($path);
                preg_match('/namespace\s+([^;]+);/',$source,$matches);
                $class = $matches[1].'\\'.pathinfo($path,PATHINFO_FILENAME);
                self::assertContains($class,$covered,$class);
                $count++;
            }
        }
        self::assertSame(77,$count);
        self::assertCount(count($declared),array_unique($declared));
        self::assertSame([],array_intersect($declared,array_keys(Metadata::excludedModels())));
        foreach (Metadata::excludedModels() as $reason) { self::assertNotSame('',$reason); }
    }

    public function test_fields_are_finite_scalar_business_metadata_and_permissions_are_actual_module_permissions(): void
    {
        $root = dirname(__DIR__,3);
        $manifestPermissions = [];
        foreach (glob($root.'/config/ModuleList/*/*.json') ?: [] as $path) {
            $manifest = json_decode((string) file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
            foreach ($manifest['permissions'] ?? [] as $permission) { $manifestPermissions[] = is_array($permission) ? $permission['name'] : $permission; }
        }
        foreach (Metadata::records() as $type => [$class,$fields,$domain,$label]) {
            self::assertTrue(is_subclass_of($class,Model::class),$class);
            self::assertNotSame('',$label);
            self::assertSame([],array_intersect($fields,Metadata::technicalExclusions()),$type);
            self::assertSame([],array_intersect(Metadata::safeSelectColumns()[$type],Metadata::technicalExclusions()),$type);
            self::assertArrayHasKey($domain,Metadata::domainGates());
            foreach (Metadata::entityPermissions()[$type] as $permission) {
                if ($permission !== 'finance.view') { self::assertContains($permission,$manifestPermissions,$type); }
            }
        }
        self::assertContains('finance.view',Metadata::entityPermissions()['estimate_generation_item_card']);
        self::assertContains('finance.view',Metadata::entityPermissions()['holding_performance_row']);
        self::assertSame(['holding_site_template','approved_estimate_dataset','estimate_price_region','estimate_price_period',
            'active_estimate_price_version','approved_estimate_resource_price'],array_keys(Metadata::globalCatalogEntities()));
        self::assertSame(['is_active' => true],Metadata::rowPredicates()['holding_site_template']);
    }

    public function test_parent_graph_is_finite_and_private_authors_and_current_organization_matches_are_explicit(): void
    {
        $parents = Metadata::parentColumns();
        $visit = function (string $type,array $seen) use (&$visit,$parents): void {
            self::assertNotContains($type,$seen);
            foreach ($parents[$type] ?? [] as $parent) {
                if (isset(Metadata::records()[$parent['type']])) { $visit($parent['type'],[...$seen,$type]); }
                else { self::assertContains($parent['type'],['contract','performance_act','payment_document']); }
            }
        };
        foreach (array_keys(Metadata::records()) as $type) { $visit($type,[]); }
        self::assertSame('user_id',Metadata::actorColumns()['estimate_generation_session_card']);
        self::assertSame('requester_actor_id',Metadata::actorColumns()['report_run_card']);
        self::assertSame('owner_id',Metadata::actorColumns()['report_saved_view_card']);
        self::assertSame('parent_organization_id',Metadata::organizationColumns()['organization_group_card']);
        self::assertSame(['contributor_organization_id' => 'organization_id'],Metadata::rowColumnMatches()['holding_performance_row']);
        self::assertSame(['document_id' => 'document_id'],$parents['estimate_generation_fact_card']['page_id']['matches']);
        self::assertTrue($parents['estimate_generation_fact_card']['document_id']['match_project']);
    }

    public function test_tools_do_not_offer_arbitrary_queries_json_fields_or_writes(): void
    {
        foreach (Metadata::domainDefinitions() as $definition) {
            self::assertSame(['search','read','navigation'],$definition->operations);
            foreach ($definition->schemas as $schema) { self::assertFalse($schema['additionalProperties']); }
            self::assertSame([],array_intersect($definition->fields,Metadata::technicalExclusions()));
        }
    }
}
