<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin\Reporting\Waves23;

use App\BusinessModules\Features\SafetyManagement\Reporting\Admission\Models\SafetySiteWorkforceAssignment;
use App\BusinessModules\Features\SafetyManagement\Reporting\Admission\Services\SafetySiteAssignmentService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Route;

final class SafetyWorkforceSiteAssignmentHttpTest extends TestCase
{
    public function createApplication()
    {
        $app = require dirname(__DIR__, 7).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    public function test_admin_http_workflow_accepts_multiple_sites_and_returns_standard_response(): void
    {
        $this->withoutMiddleware();
        $this->app->instance(SafetySiteAssignmentService::class, new class extends SafetySiteAssignmentService {
            public function assignMany(
                int $organizationId,
                int $projectId,
                array $siteIds,
                int $workforceAssignmentId,
                int $employeeId,
                string $validFrom,
                ?string $validTo,
            ): array {
                return array_map(
                    static fn (int $siteId): SafetySiteWorkforceAssignment => (new SafetySiteWorkforceAssignment)
                        ->setRawAttributes([
                            'id' => $siteId,
                            'project_id' => $projectId,
                            'safety_site_id' => $siteId,
                            'workforce_assignment_id' => $workforceAssignmentId,
                            'employee_id' => $employeeId,
                            'valid_from' => $validFrom,
                            'valid_to' => $validTo,
                        ], true),
                    $siteIds,
                );
            }
        });

        $response = $this->postJson('/api/v1/admin/safety-management/workforce-site-assignments', [
            'project_id' => 10,
            'employee_id' => 20,
            'workforce_assignment_id' => 30,
            'safety_site_ids' => [40, 41],
            'valid_from' => '2026-07-01',
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data.assignments')
            ->assertJsonPath('data.assignments.1.safety_site_id', 41);

        $route = Route::getRoutes()->getByName('admin.safety_management.workforce_site_assignments.store');
        self::assertNotNull($route);
        self::assertContains('authorize:safety-management.settings.manage', $route->gatherMiddleware());
    }

    public function test_admin_http_workflow_rejects_duplicate_sites_before_service_execution(): void
    {
        $this->withoutMiddleware();

        $this->postJson('/api/v1/admin/safety-management/workforce-site-assignments', [
            'project_id' => 10,
            'employee_id' => 20,
            'workforce_assignment_id' => 30,
            'safety_site_ids' => [40, 40],
            'valid_from' => '2026-07-01',
        ])->assertUnprocessable()->assertJsonValidationErrors('safety_site_ids.1');
    }
}
