<?php

declare(strict_types=1);

namespace Tests\Unit\Authorization;

use App\Domain\Authorization\Services\RolePayloadFormatter;
use App\Domain\Authorization\Services\RoleScanner;
use App\Http\Controllers\Api\V1\Landing\RolesComparisonController;
use Tests\TestCase;

class RolesComparisonControllerTest extends TestCase
{
    public function test_comparison_count_matches_normalized_module_permissions(): void
    {
        $formatter = $this->app->make(RolePayloadFormatter::class);
        $controller = new class(new RoleScanner, $formatter) extends RolesComparisonController
        {
            public function formatRole(string $roleSlug, array $roleData): array
            {
                return $this->formatRoleForComparison($roleSlug, $roleData);
            }
        };

        $payload = $controller->formatRole('supplier', [
            'name' => 'Снабженец',
            'description' => '',
            'context' => 'organization',
            'interface_access' => [],
            'system_permissions' => [],
            'module_permissions' => [
                'site_requests' => [
                    'site_requests.view',
                ],
            ],
        ]);

        $this->assertSame(2, $payload['module_permissions_count']);
        $this->assertArrayHasKey(
            'site_requests.statistics',
            $payload['permissions']['module_permissions']['site_requests']
        );
    }
}
