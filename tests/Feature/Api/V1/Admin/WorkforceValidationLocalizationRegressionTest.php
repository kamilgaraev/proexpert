<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use App\Modules\Core\AccessController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class WorkforceValidationLocalizationRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_workforce_validation_summary_uses_russian_business_field_names(): void
    {
        // Regression: ISSUE-075 — API раскрывал машинные имена полей и английский счётчик ошибок
        // Found by /qa on 2026-08-29
        // Report: .gstack/qa-reports/qa-report-most-full-2026-08-28.md
        $context = AdminApiTestContext::create();
        $this->allowAccess();

        $cases = [
            '/api/v1/admin/workforce/employees' => 'Заполните поле «табельный номер». (и ещё 3 ошибки)',
            '/api/v1/admin/workforce/departments' => 'Заполните поле «код». (и ещё 1 ошибка)',
            '/api/v1/admin/workforce/positions' => 'Заполните поле «код». (и ещё 1 ошибка)',
            '/api/v1/admin/workforce/staff-units' => 'Заполните поле «подразделение». (и ещё 3 ошибки)',
            '/api/v1/admin/workforce/work-schedules' => 'Заполните поле «код». (и ещё 1 ошибка)',
            '/api/v1/admin/workforce/absences' => 'Заполните поле «сотрудник». (и ещё 2 ошибки)',
            '/api/v1/admin/workforce/business-trips' => 'Заполните поле «сотрудник». (и ещё 3 ошибки)',
            '/api/v1/admin/workforce/payroll-periods' => 'Заполните поле «начало расчётного периода». (и ещё 1 ошибка)',
            '/api/v1/admin/workforce/accounting-mappings' => 'Заполните поле «уровень настройки». (и ещё 1 ошибка)',
        ];

        foreach ($cases as $url => $message) {
            $this->withHeaders($context->authHeaders())
                ->postJson($url)
                ->assertUnprocessable()
                ->assertJsonPath('message', $message);
        }
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
