<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantCustomReportBuilder;
use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantCustomReportReference;
use App\Domain\Authorization\Models\OrganizationCustomRole;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\Project;
use App\Services\Modules\PackageCatalogService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

final class AssistantContractSelfExecutionAuthorizationTest extends TestCase
{
    public function test_self_execution_label_remains_visible_with_real_catalog_denials_and_fresh_contract_access(): void
    {
        Queue::fake();
        config(['cache.default' => 'array']);
        $fixture = Model::withoutEvents(fn () => AssistantRealAuthorizationFixture::create(array_column(app(PackageCatalogService::class)->allPackages(), 'slug')));
        $org = (int) $fixture->organization->id;
        $actor = Model::withoutEvents(fn () => $fixture->addMember([
            'ai-assistant' => ['ai_assistant.chat'], 'reports' => ['reports.view'],
            'contract-management' => ['contracts.view'], 'project-management' => ['projects.view'],
        ]));
        $project = Model::withoutEvents(fn () => Project::factory()->create(['organization_id' => $org, 'is_archived' => false]));
        Model::withoutEvents(fn () => $actor->assignedProjects()->attach($project->id, ['is_active' => true, 'role' => 'member']));
        $contractor = Model::withoutEvents(fn () => Contractor::query()->create([
            'organization_id' => $org, 'source_organization_id' => $org, 'name' => 'SECRET-SELF-CONTRACTOR', 'email' => 'secret-contractor@example.test',
        ]));
        foreach (['SELF-1' => $contractor->id, 'SELF-2' => null] as $number => $contractorId) {
            Model::withoutEvents(fn () => Contract::query()->create([
                'organization_id' => $org, 'project_id' => $project->id, 'contractor_id' => $contractorId,
                'supplier_id' => null, 'contract_side_type' => 'contract', 'number' => $number,
                'date' => '2026-10-01', 'status' => 'active', 'is_self_execution' => true,
                'total_amount' => '10.00', 'currency' => 'RUB',
            ]));
        }
        $authorization = app(AuthorizationService::class);
        $context = ['organization_id' => $org];
        foreach (['contractors.view', 'suppliers.view', 'finance.view'] as $permission) {
            self::assertFalse($authorization->forCurrentChecks(true)->canCurrent($actor, $permission, $context), $permission);
        }
        self::assertTrue($authorization->forCurrentChecks(true)->canCurrent($actor, 'contracts.view', $context));
        $builder = app(AssistantCustomReportBuilder::class);
        $references = app(AssistantCustomReportReference::class);
        $section = ['title' => 'Договоры', 'domain' => 'contracts', 'entity_type' => 'contract', 'project_id' => null,
            'columns' => ['number', 'counterparty_name'], 'filters' => [], 'group_by' => [], 'metrics' => [],
            'order_by' => [['field' => 'number', 'direction' => 'asc']]];
        $plan = ['title' => 'Договоры собственными силами', 'format' => 'xlsx', 'sections' => [$section]];
        $report = $builder->build($plan, $actor, $org);
        self::assertSame([['SELF-1', 'Собственные силы'], ['SELF-2', 'Собственные силы']], $report['sections'][0]['rows']);
        self::assertSame(2, $report['sections'][0]['source_row_count']);
        self::assertCount(1, $report['source_refs']);
        $encoded = json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        foreach (['SECRET-SELF-', 'secret-contractor@example.test'] as $private) {
            self::assertStringNotContainsString($private, $encoded);
        }
        self::assertTrue($references->canReadReference($actor, $org, $report['source_refs'][0]));
        $nullPlan = $plan;
        $nullPlan['sections'][0]['filters'] = [['field' => 'counterparty_name', 'operator' => 'is_null', 'value' => null]];
        self::assertSame([], $builder->build($nullPlan, $actor, $org)['sections'][0]['rows']);
        $assignment = UserRoleAssignment::query()->where('user_id', $actor->id)->firstOrFail();
        $role = OrganizationCustomRole::query()->findOrFail($assignment->custom_role_id);
        $permissions = $role->module_permissions;
        $permissions['contract-management'] = [];
        $role->update(['module_permissions' => $permissions]);
        self::assertFalse($authorization->forCurrentChecks(true)->canCurrent($actor, 'contracts.view', $context));
        self::assertFalse($references->canReadReference($actor, $org, $report['source_refs'][0]));
        $this->expectException(AccessDeniedHttpException::class);
        $builder->build($plan, $actor, $org);
    }
}
