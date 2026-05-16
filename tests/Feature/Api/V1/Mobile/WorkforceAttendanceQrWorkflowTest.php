<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Mobile;

use App\BusinessModules\Features\WorkforceManagement\Domain\HR\Models\WorkforceEmployee;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Modules\Core\AccessController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

final class WorkforceAttendanceQrWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_generates_qr_and_authorized_scanner_confirms_attendance(): void
    {
        $employeeContext = AdminApiTestContext::create(roleSlug: 'worker');
        $scannerContext = $this->contextForOrganization($employeeContext->organization, 'foreman');
        $project = Project::factory()->create(['organization_id' => $employeeContext->organization->id]);
        $employee = $this->employee($employeeContext, $employeeContext->user->id);
        $this->allowAccess();

        $issue = $this->withHeaders($employeeContext->authHeaders())
            ->postJson('/api/v1/mobile/workforce/attendance/qr', [
                'project_id' => $project->id,
                'work_date' => '2026-05-16',
            ]);

        $issue->assertOk()
            ->assertJsonPath('data.employee_label', 'Иванов Иван')
            ->assertJsonPath('data.project_label', $project->name)
            ->assertJsonPath('data.status_label', trans_message('workforce.attendance.qr_status_ready'));

        $token = (string) $issue->json('data.qr_token');

        $this->assertNotEmpty($token);
        $this->assertDatabaseMissing('workforce_attendance_qr_tokens', ['token_hash' => $token]);

        $scan = $this->withHeaders($scannerContext->authHeaders())
            ->postJson('/api/v1/mobile/workforce/attendance/qr/scan', [
                'qr_token' => $token,
                'device_id' => 'foreman-phone',
            ]);

        $scan->assertOk()
            ->assertJsonPath('data.employee_label', $employee->full_name)
            ->assertJsonPath('data.project_label', $project->name)
            ->assertJsonPath('data.status_label', trans_message('workforce.attendance.qr_status_confirmed'))
            ->assertJsonPath('data.source_label', trans_message('workforce.attendance.qr_source_label'));

        $this->withHeaders($scannerContext->authHeaders())
            ->postJson('/api/v1/mobile/workforce/attendance/qr/scan', ['qr_token' => $token])
            ->assertStatus(422)
            ->assertJsonPath('message', trans_message('workforce.errors.qr_token_already_used'));

        $this->assertDatabaseHas('workforce_attendance_scan_events', [
            'organization_id' => $employeeContext->organization->id,
            'employee_id' => $employee->id,
            'project_id' => $project->id,
            'result' => 'confirmed',
        ]);
    }

    public function test_expired_qr_is_rejected_with_business_message(): void
    {
        $employeeContext = AdminApiTestContext::create(roleSlug: 'worker');
        $scannerContext = $this->contextForOrganization($employeeContext->organization, 'foreman');
        $this->employee($employeeContext, $employeeContext->user->id);
        $this->allowAccess();

        $token = (string) $this->withHeaders($employeeContext->authHeaders())
            ->postJson('/api/v1/mobile/workforce/attendance/qr', ['work_date' => '2026-05-16'])
            ->assertOk()
            ->json('data.qr_token');

        DB::table('workforce_attendance_qr_tokens')->update([
            'expires_at' => now()->subMinute(),
            'updated_at' => now(),
        ]);

        $this->withHeaders($scannerContext->authHeaders())
            ->postJson('/api/v1/mobile/workforce/attendance/qr/scan', ['qr_token' => $token])
            ->assertStatus(422)
            ->assertJsonPath('message', trans_message('workforce.errors.qr_token_expired'));

        $this->assertDatabaseHas('workforce_attendance_scan_events', [
            'organization_id' => $employeeContext->organization->id,
            'result' => 'rejected',
            'failure_reason' => trans_message('workforce.errors.qr_token_expired'),
        ]);
    }

    private function employee(AdminApiTestContext $context, int $userId): WorkforceEmployee
    {
        return WorkforceEmployee::query()->create([
            'organization_id' => $context->organization->id,
            'user_id' => $userId,
            'personnel_number' => 'QR-001',
            'last_name' => 'Иванов',
            'first_name' => 'Иван',
            'employment_status' => 'active',
            'hire_date' => '2026-05-01',
        ]);
    }

    private function contextForOrganization(Organization $organization, string $roleSlug): AdminApiTestContext
    {
        $user = User::factory()->create(['current_organization_id' => $organization->id]);
        $organization->users()->attach($user->id, [
            'is_owner' => false,
            'is_active' => true,
            'settings' => null,
        ]);

        $context = AuthorizationContext::getOrganizationContext($organization->id);
        UserRoleAssignment::assignRole($user, $roleSlug, $context);
        $token = JWTAuth::claims(['organization_id' => $organization->id])->fromUser($user);

        return new AdminApiTestContext($organization, $user, $token);
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
            $mock->shouldReceive('getUserRoleSlugs')->andReturn(['foreman']);
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
