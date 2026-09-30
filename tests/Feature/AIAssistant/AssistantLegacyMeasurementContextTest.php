<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Actions\MeasurementUnits\GetMeasurementUnitDetailsAction;
use App\BusinessModules\Features\AIAssistant\Actions\MeasurementUnits\GetMeasurementUnitsAction;
use App\BusinessModules\Features\AIAssistant\Services\AIPermissionChecker;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\ContextBuilder;
use App\BusinessModules\Features\AIAssistant\Services\IntentRecognizer;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Organization;
use App\Models\User;
use App\Services\Entitlements\OrganizationEntitlementService;
use App\Services\Logging\LoggingService;
use App\Services\Project\UserProjectAccessService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class AssistantLegacyMeasurementContextTest extends TestCase
{
    #[DataProvider('measurementReadIntents')]
    public function test_assistant_access_does_not_expose_measurement_context_without_current_view_permission(string $intent, string $actionClass): void
    {
        $organization = Organization::factory()->create();
        $actor = User::factory()->create(['current_organization_id' => $organization->id, 'is_active' => true]);
        $actor->organizations()->attach($organization->id, ['is_active' => true]);
        $authorization = $this->createMock(AuthorizationService::class);
        $authorization->method('canCurrent')->willReturnCallback(static fn (User $user, string $permission): bool => $permission === 'ai_assistant.chat');
        $authorization->method('forCurrentChecks')->willReturnSelf();
        $modules = $this->createMock(OrganizationEntitlementService::class);
        $modules->method('getEffectiveModules')->willReturn(collect([(object) ['slug' => 'ai-assistant'], (object) ['slug' => 'catalog-management']]));
        $policy = new AssistantDataAccessPolicy($authorization, new UserProjectAccessService, $modules);
        $this->app->instance(AssistantDataAccessPolicy::class, $policy);
        $action = $this->createMock($actionClass);
        $action->expects($this->never())->method('execute');
        $this->app->instance($actionClass, $action);
        $builder = new MeasurementContextBoundaryBuilder($this->createMock(IntentRecognizer::class), $this->createMock(LoggingService::class));

        $this->assertTrue((new AIPermissionChecker($authorization))->canUseAssistant($actor, $organization->id));
        $this->assertFalse($policy->canReadDomain($actor, $organization->id, 'measurement_units'));
        $this->assertNull($builder->read($intent, $organization->id, $actor));
    }

    public static function measurementReadIntents(): array
    {
        return [
            'registered list intent' => ['measurement_units_list', GetMeasurementUnitsAction::class],
            'registered details intent' => ['measurement_unit_details', GetMeasurementUnitDetailsAction::class],
        ];
    }
}

final class MeasurementContextBoundaryBuilder extends ContextBuilder
{
    public function read(string $intent, int $organizationId, User $actor): ?array
    {
        return $this->executeAction($intent, $organizationId, 'Покажи единицы измерения', $actor);
    }
}
