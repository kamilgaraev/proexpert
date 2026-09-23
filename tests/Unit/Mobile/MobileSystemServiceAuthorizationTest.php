<?php

declare(strict_types=1);

namespace Tests\Unit\Mobile;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use App\Modules\Core\AccessController;
use App\Services\Mobile\MobileSystemService;
use Illuminate\Auth\Access\AuthorizationException;
use Mockery;
use Tests\TestCase;

final class MobileSystemServiceAuthorizationTest extends TestCase
{
    public function test_rate_coefficients_reject_actor_without_read_permission(): void
    {
        $actor = new User();
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->shouldReceive('can')->once()->with($actor, 'rate_coefficients.view', ['organization_id' => 41])->andReturn(false);

        $this->app->instance(AuthorizationService::class, $authorization);
        $service = $this->app->make(MobileSystemService::class);

        $this->expectException(AuthorizationException::class);
        $service->currentRateCoefficients($actor, 41, 'general', null, 20);
    }

    public function test_one_c_status_rejects_actor_when_addon_is_inactive(): void
    {
        $actor = new User();
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->shouldReceive('can')->once()->with($actor, 'one_c_exchange.view', ['organization_id' => 41])->andReturn(true);

        $moduleAccess = Mockery::mock(AccessController::class);
        $moduleAccess->shouldReceive('hasModuleAccess')->once()->with(41, 'one-c-basic-exchange')->andReturn(false);

        $this->app->instance(AuthorizationService::class, $authorization);
        $this->app->instance(AccessController::class, $moduleAccess);
        $service = $this->app->make(MobileSystemService::class);

        $this->expectException(AuthorizationException::class);
        $service->oneCStatus($actor, 41);
    }
}
