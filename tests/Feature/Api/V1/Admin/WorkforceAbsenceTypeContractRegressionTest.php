<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Features\WorkforceManagement\Domain\HR\Models\WorkforceEmployee;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use App\Modules\Core\AccessController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class WorkforceAbsenceTypeContractRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_absence_registry_returns_the_business_type(): void
    {
        // Regression: ISSUE-079 — реестр терял тип отсутствия и показывал общее «Отсутствие»
        // Found by /qa on 2026-08-29
        // Report: .gstack/qa-reports/qa-report-most-full-2026-08-28.md
        [$context, $employee] = $this->contextWithEmployee();

        $this->createVacation($context, $employee);

        $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/workforce/absences')
            ->assertOk()
            ->assertJsonPath('data.0.absence_type_code', 'vacation')
            ->assertJsonPath('data.0.absence_type_label', 'Отпуск');
    }

    public function test_absence_registry_searches_by_business_type(): void
    {
        // Regression: ISSUE-079 — поиск реестра обращался к отсутствующему полю absence_type_code
        // Found by /qa on 2026-08-29
        // Report: .gstack/qa-reports/qa-report-most-full-2026-08-28.md
        [$context, $employee] = $this->contextWithEmployee();

        $this->createVacation($context, $employee);

        $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/workforce/absences?search=vacation')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.absence_type_label', 'Отпуск');
    }

    private function contextWithEmployee(): array
    {
        $context = AdminApiTestContext::create();
        $employee = WorkforceEmployee::create([
            'organization_id' => $context->organization->id,
            'personnel_number' => 'QA-ABSENCE-TYPE',
            'last_name' => 'Иванов',
            'first_name' => 'Иван',
            'employment_status' => 'active',
            'hire_date' => '2026-05-01',
        ]);
        $this->allowAccess();

        return [$context, $employee];
    }

    private function createVacation(AdminApiTestContext $context, WorkforceEmployee $employee): void
    {
        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/workforce/absences', [
                'employee_id' => $employee->id,
                'absence_type_code' => 'vacation',
                'start_date' => '2026-09-02',
                'end_date' => '2026-09-03',
            ])
            ->assertCreated();
    }

    private function allowAccess(): void
    {
        $this->mock(AccessController::class, function (MockInterface $mock): void {
            $mock->shouldReceive('hasModuleAccess')->andReturn(true);
        });

        $this->mock(AuthorizationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('canAccessInterface')->andReturn(true);
            $mock->shouldReceive('can')->andReturn(true);
            $mock->shouldReceive('hasRole')->andReturn(true);
            $mock->shouldReceive('getUserRoleSlugs')->andReturn(['web_admin']);
            $mock->shouldReceive('getUserRoles')->andReturnUsing(
                static function (User $user, ?AuthorizationContext $context = null) {
                    return $user->roleAssignments()
                        ->where('is_active', true)
                        ->when($context !== null, static fn ($query) => $query->where('context_id', $context->id))
                        ->get();
                }
            );
        });
    }
}
