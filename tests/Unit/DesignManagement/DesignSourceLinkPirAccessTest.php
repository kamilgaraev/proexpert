<?php

declare(strict_types=1);

namespace Tests\Unit\DesignManagement;

use App\BusinessModules\Features\DesignManagement\Models\DesignArtifactVersion;
use App\BusinessModules\Features\DesignManagement\Models\DesignSourceLink;
use App\BusinessModules\Features\DesignManagement\Services\DesignSourceLinkService;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use App\Modules\Core\AccessController;
use DomainException;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

final class DesignSourceLinkPirAccessTest extends TestCase
{
    public function test_target_card_keeps_safe_snapshot_when_pir_is_off(): void
    {
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->shouldNotReceive('can');
        $access = Mockery::mock(AccessController::class);
        $access->shouldReceive('hasModuleAccess')->once()->with(7, 'design-management')->andReturnFalse();
        $service = new DesignSourceLinkService($authorization, $access);
        $link = new DesignSourceLink(['organization_id' => 7, 'project_id' => 12, 'source_version_id' => 18, 'source_sheet_id' => 4, 'source_snapshot' => ['title' => 'АР. План', 'revision' => 'R01'], 'target_snapshot' => ['type' => 'completed_work', 'label' => 'Кладка']]);
        $version = new DesignArtifactVersion(['title' => 'АР. План', 'revision' => 'R01']);
        $version->setAttribute('id', 18);
        $link->setRelation('sourceVersion', $version);

        $payload = $this->invoke($service, 'presentLink', [new User(['id' => 3]), $link]);

        self::assertNull($payload['source_version_id']);
        self::assertNull($payload['source_sheet_id']);
        self::assertSame(['title' => 'АР. План', 'revision' => 'R01', 'available' => false], $payload['source']);
        self::assertArrayNotHasKey('download_url', $payload['source']);
    }

    public function test_source_snapshot_becomes_real_source_only_when_pir_and_permission_are_available(): void
    {
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->shouldReceive('can')->once()->withArgs(fn (User $user, string $permission, array $scope): bool => $permission === 'design-management.view' && $scope === ['organization_id' => 7, 'project_id' => 12])->andReturnTrue();
        $access = Mockery::mock(AccessController::class);
        $access->shouldReceive('hasModuleAccess')->once()->with(7, 'design-management')->andReturnTrue();
        $service = new DesignSourceLinkService($authorization, $access);
        $link = new DesignSourceLink(['organization_id' => 7, 'project_id' => 12, 'source_version_id' => 18, 'source_snapshot' => ['title' => 'АР. План', 'revision' => 'R01'], 'target_snapshot' => ['type' => 'completed_work', 'label' => 'Кладка']]);
        $version = new DesignArtifactVersion(['title' => 'АР. План', 'revision' => 'R01']);
        $version->setAttribute('id', 18);
        $link->setRelation('sourceVersion', $version);

        $payload = $this->invoke($service, 'presentLink', [new User(['id' => 3]), $link]);

        self::assertSame(18, $payload['source_version_id']);
        self::assertSame(['title' => 'АР. План', 'revision' => 'R01', 'id' => 18, 'available' => true], $payload['source']);
    }

    public function test_target_read_depends_only_on_target_permission(): void
    {
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->shouldReceive('can')->once()->withArgs(fn (User $user, string $permission): bool => $permission === 'completed_works.view')->andReturnTrue();
        $access = Mockery::mock(AccessController::class);
        $access->shouldNotReceive('hasModuleAccess');
        $service = new DesignSourceLinkService($authorization, $access);

        $this->invoke($service, 'authorizeTargetRead', [new User(['id' => 3]), 'completed_work', 7, 12]);
    }

    public function test_target_read_is_denied_without_target_permission_even_if_pir_state_is_not_checked(): void
    {
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->shouldReceive('can')->once()->andReturnFalse();
        $access = Mockery::mock(AccessController::class);
        $access->shouldNotReceive('hasModuleAccess');
        $service = new DesignSourceLinkService($authorization, $access);

        $this->expectException(DomainException::class);
        $this->invoke($service, 'authorizeTargetRead', [new User(['id' => 3]), 'completed_work', 7, 12]);
    }

    public function test_source_actions_require_active_pir(): void
    {
        $authorization = Mockery::mock(AuthorizationService::class);
        $access = Mockery::mock(AccessController::class);
        $access->shouldReceive('hasModuleAccess')->once()->with(7, 'design-management')->andReturnFalse();
        $service = new DesignSourceLinkService($authorization, $access);

        $this->expectException(DomainException::class);
        $this->invoke($service, 'requirePirAccess', [7]);
    }

    private function invoke(DesignSourceLinkService $service, string $method, array $arguments): mixed
    {
        $reflection = new ReflectionMethod($service, $method);
        return $reflection->invokeArgs($service, $arguments);
    }
}
