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

final class WorkforceDateRangeLocalizationRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_absence_and_business_trip_reject_reverse_date_ranges_in_russian(): void
    {
        // Regression: ISSUE-078 — API возвращал ключ validation.after_or_equal вместо понятной ошибки
        // Found by /qa on 2026-08-29
        // Report: .gstack/qa-reports/qa-report-most-full-2026-08-28.md
        $context = AdminApiTestContext::create();
        $employee = WorkforceEmployee::create([
            'organization_id' => $context->organization->id,
            'personnel_number' => 'QA-DATE-RANGE',
            'last_name' => 'Иванов',
            'first_name' => 'Иван',
            'employment_status' => 'active',
            'hire_date' => '2026-05-01',
        ]);
        $this->allowAccess();

        $cases = [
            '/api/v1/admin/workforce/absences' => [
                'employee_id' => $employee->id,
                'absence_type_code' => 'vacation',
                'start_date' => '2026-09-03',
                'end_date' => '2026-09-02',
            ],
            '/api/v1/admin/workforce/business-trips' => [
                'employee_id' => $employee->id,
                'start_date' => '2026-09-05',
                'end_date' => '2026-09-04',
                'destination' => 'Казань',
            ],
        ];

        foreach ($cases as $url => $payload) {
            $this->withHeaders($context->authHeaders())
                ->postJson($url, $payload)
                ->assertUnprocessable()
                ->assertJsonPath('message', 'Дата окончания должна быть не раньше даты начала.')
                ->assertJsonPath('errors.end_date.0', 'Дата окончания должна быть не раньше даты начала.');
        }
    }

    public function test_workforce_date_range_fallback_does_not_expose_internal_field_names(): void
    {
        // Regression: ISSUE-078 — общий текст диапазона мог раскрыть date_from в пользовательском сообщении
        // Found by /qa on 2026-08-29
        // Report: .gstack/qa-reports/qa-report-most-full-2026-08-28.md
        $context = AdminApiTestContext::create();
        $this->allowAccess();

        $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/workforce/schedule-calendar?date_from=2026-09-03&date_to=2026-09-02')
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Дата в поле «дата окончания» не должна быть раньше начальной даты.')
            ->assertJsonPath('errors.date_to.0', 'Дата в поле «дата окончания» не должна быть раньше начальной даты.');
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
