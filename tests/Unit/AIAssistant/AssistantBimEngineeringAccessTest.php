<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\Models\User;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;
use Mockery;
use PHPUnit\Framework\TestCase;
use Tests\Support\AssistantOfflineAclFixture;

final class AssistantBimEngineeringAccessTest extends TestCase
{
    private mixed $previousApp;
    private mixed $previousResolver;
    private AssistantDataAccessPolicy $policy;
    private User $actor;

    protected function setUp(): void
    {
        $this->previousApp = Facade::getFacadeApplication();
        $this->previousResolver = Model::getConnectionResolver();
        Facade::clearResolvedInstances();
        [$this->policy, $this->actor] = AssistantOfflineAclFixture::create();
        $app = Facade::getFacadeApplication();
        $app->instance('db.schema', new class($app->make('db.schema')) {
            public function __construct(private object $native) {}
            public function hasColumn($table, $column) { return $this->native->hasColumn($table, $column); }
            public function getColumnListing($table): array
            {
                $columns = $this->native->getColumnListing($table);
                return $table === 'design_ifc_model_elements'
                    ? array_values(array_unique([...$columns, 'source_content_hash', 'source_schema_version', 'source_equipment_kind', 'source_materials'])) : $columns;
            }
        });
        $modules = Mockery::mock(\App\Services\Entitlements\OrganizationEntitlementService::class);
        $modules->shouldReceive('getEffectiveModules')->andReturn(collect(['ai-assistant', 'project-management', 'design-management'])
            ->map(static fn (string $slug): object => (object) ['slug' => $slug]));
        $this->policy = new AssistantDataAccessPolicy($app->make(\App\Domain\Authorization\Services\AuthorizationService::class),
            $app->make(\App\Services\Project\UserProjectAccessService::class), $modules);
        $app->instance(AssistantDataAccessPolicy::class, $this->policy);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousApp);
        Container::setInstance($this->previousApp);
        if ($this->previousResolver !== null) { Model::setConnectionResolver($this->previousResolver); }
        else { Model::unsetConnectionResolver(); }
    }

    public function test_derivative_engineering_projection_reads_physical_columns_through_compiled_authorized_ids(): void
    {
        $query = $this->policy->engineeringEntityQuery($this->actor, 1, 'design_model_derivative');
        self::assertNotNull($query);
        self::assertSame('design_model_derivatives', $query->getQuery()->from);
        self::assertContains('design_model_derivatives.metadata', $query->getQuery()->columns);
        self::assertContains('design_model_derivatives.viewer_provider', $query->getQuery()->columns);
        $sql = $query->toSql();
        self::assertStringContainsString('"design_model_derivatives"."id" in (', $sql);
        self::assertStringContainsString('assistant_acl_', $sql);
        self::assertStringContainsString('design_artifact_versions', $sql);
        self::assertStringContainsString('design_artifacts', $sql);
        self::assertStringContainsString('design_packages', $sql);
        self::assertStringContainsString('organization_user', $sql);
        self::assertStringContainsString('current_organization_id', $sql);
    }

    public function test_private_engineering_columns_never_mutate_cached_public_entity_projection(): void
    {
        [$before, $engineering, $after] = $this->policy->withCurrentChecks($this->actor, 1, fn (): array => [
            $this->policy->entityQuery($this->actor, 1, 'design_ifc_model_element'),
            $this->policy->engineeringEntityQuery($this->actor, 1, 'design_ifc_model_element'),
            $this->policy->entityQuery($this->actor, 1, 'design_ifc_model_element'),
        ], true);
        self::assertNotNull($before);
        self::assertNotNull($engineering);
        self::assertNotNull($after);
        self::assertSame($before->getQuery()->columns, $after->getQuery()->columns);
        self::assertSame($before->toSql(), $after->toSql());
        self::assertSame($before->getBindings(), $after->getBindings());
        self::assertNotContains('design_ifc_model_elements.properties', $before->getQuery()->columns);
        self::assertNotContains('design_ifc_model_elements.classifications', $after->getQuery()->columns);
        self::assertContains('design_ifc_model_elements.properties', $engineering->getQuery()->columns);
        self::assertContains('design_ifc_model_elements.classifications', $engineering->getQuery()->columns);
        self::assertStringNotContainsString('"design_ifc_model_elements"."id" in (', $engineering->toSql());
        self::assertSame(1, substr_count($engineering->toSql(), 'from "design_ifc_model_elements"'));
        self::assertStringContainsString('"design_ifc_model_elements"."organization_id" = ?', $engineering->toSql());
        self::assertStringContainsString('organization_user', $engineering->toSql());
        foreach (['source_content_hash', 'source_schema_version', 'source_equipment_kind', 'source_materials'] as $field) {
            self::assertStringContainsString('"design_ifc_model_elements"."'.$field.'"', $engineering->toSql());
            self::assertStringNotContainsString('"design_ifc_model_elements"."'.$field.'"', $after->toSql());
        }
        self::assertStringContainsString('design_artifact_versions', $engineering->toSql());
        self::assertStringContainsString('design_model_derivatives', $engineering->toSql());
    }

    public function test_engineering_projection_rejects_entities_outside_the_closed_bim_surface(): void
    {
        self::assertNull($this->policy->engineeringEntityQuery($this->actor, 1, 'contract'));
        self::assertNull($this->policy->engineeringEntityQuery($this->actor, 1, 'file'));
        self::assertNull($this->policy->engineeringEntityQuery($this->actor, 1, 'design_source_link'));
    }
}
