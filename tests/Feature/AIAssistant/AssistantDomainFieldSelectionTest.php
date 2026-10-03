<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDomainCatalog;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDomainReadService;
use App\BusinessModules\Features\AIAssistant\Services\AssistantSourceReferenceGuard;
use App\BusinessModules\Features\AIAssistant\Services\FinancialEvidence\AssistantEstimatePositionReadService;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Estimate;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Entitlements\OrganizationEntitlementService;
use App\Services\Project\UserProjectAccessService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;

final class AssistantDomainFieldSelectionTest extends TestCase
{
    public function test_denied_money_is_not_selected_for_read_navigation_or_structured_reference_refresh(): void
    {
        [$actor, $organization, $estimate, $reader, $policy] = $this->fixture();
        $selections = [];
        DB::listen(static function (QueryExecuted $query) use (&$selections): void {
            if (preg_match('/^select\s+("estimates"\..+?)\s+from\s+(?:"estimates"|\(WITH\s)/is', $query->sql, $match) === 1) {
                $selections[] = $match[1];
            }
        });
        $result = $reader->execute('read', ['domain' => 'estimates', 'entity_type' => 'estimate', 'id' => (string) $estimate->id,
            'fields' => ['id', 'name', 'total_amount']], $actor, $organization->id);
        $this->assertSame('Текущая смета', $result['results'][0]['fields']['name']);
        $this->assertArrayNotHasKey('total_amount', $result['results'][0]['fields']);
        $this->assertNotContains('total_amount', $result['source_refs'][0]['checked_fields']);
        $this->assertStringNotContainsString('12345.67', json_encode($result, JSON_THROW_ON_ERROR));
        $this->assertTrue((new AssistantSourceReferenceGuard($policy))->fresh($actor, $organization->id, $result['source_refs']));
        $navigation = $reader->execute('navigation', ['domain' => 'estimates', 'entity_type' => 'estimate', 'id' => $estimate->id], $actor, $organization->id);
        $this->assertStringNotContainsString('12345.67', json_encode($navigation, JSON_THROW_ON_ERROR));
        $this->assertGreaterThanOrEqual(3, count($selections));
        foreach ($selections as $selection) {
            $this->assertStringNotContainsString('total_amount', $selection);
            $this->assertStringNotContainsString('*', $selection);
        }
    }

    public function test_uuid_input_for_an_integer_primary_key_is_rejected_before_reading_rows(): void
    {
        [$actor, $organization, , $reader] = $this->fixture();
        $this->expectException(ValidationException::class);
        $reader->execute('read', ['domain' => 'estimates', 'entity_type' => 'estimate', 'id' => '12345678-1234-4123-8123-123456789012',
            'fields' => ['id', 'name']], $actor, $organization->id);
    }

    public function test_nonfinancial_reference_keeps_project_scope_and_cannot_hide_money_fields(): void
    {
        [$actor, $organization, $estimate, $reader, $policy] = $this->fixture();
        $result = $reader->execute('read', ['domain' => 'estimates', 'entity_type' => 'estimate', 'id' => $estimate->id,
            'fields' => ['id', 'name']], $actor, $organization->id);
        $reference = $result['source_refs'][0];
        $guard = new AssistantSourceReferenceGuard($policy);
        self::assertTrue($guard->fresh($actor, $organization->id, [$reference]));
        self::assertFalse($guard->fresh($actor, $organization->id, [$reference + ['estimate_id' => $estimate->id + 1]]));
        $moneyReference = $reference;
        $moneyReference['checked_fields'][] = 'total_amount';
        self::assertFalse($guard->fresh($actor, $organization->id, [$moneyReference]));
        $actor->assignedProjects()->updateExistingPivot($estimate->project_id, ['is_active' => false]);
        self::assertFalse($guard->fresh($actor, $organization->id, [$reference]));
    }

    private function fixture(): array
    {
        $organization = Organization::withoutEvents(fn () => Organization::factory()->create());
        $actor = User::withoutEvents(fn () => User::factory()->create(['current_organization_id' => $organization->id, 'is_active' => true]));
        $actor->organizations()->attach($organization->id, ['is_active' => true, 'project_access_mode' => 'assigned_projects']);
        $project = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $organization->id, 'is_archived' => false]));
        $actor->assignedProjects()->attach($project->id, ['is_active' => true, 'role' => 'member']);
        $estimate = Estimate::withoutEvents(fn () => Estimate::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id,
            'number' => 'SELECT-ACL', 'name' => 'Текущая смета', 'estimate_date' => now()->toDateString(), 'total_amount' => '12345.67']));
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->shouldReceive('canCurrent')->andReturnUsing(static fn (User $user, string $permission): bool => ! in_array($permission,
            ['budget-estimates.finance.view', 'finance.view', 'finance.view_project_budget'], true));
        $authorization->shouldReceive('forCurrentChecks')->andReturnSelf();
        $entitlements = Mockery::mock(OrganizationEntitlementService::class);
        $entitlements->shouldReceive('getEffectiveModules')->andReturn(collect([(object) ['slug' => 'project-management'], (object) ['slug' => 'budget-estimates']]));
        $policy = new AssistantDataAccessPolicy($authorization, new UserProjectAccessService, $entitlements);
        $this->app->instance(AssistantEstimatePositionReadService::class, new AssistantEstimatePositionReadService($policy));
        return [$actor, $organization, $estimate, new AssistantDomainReadService(new AssistantDomainCatalog(AssistantDomainCatalog::defaults()), $policy, $authorization), $policy];
    }
}
