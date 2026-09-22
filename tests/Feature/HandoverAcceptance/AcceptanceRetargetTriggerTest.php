<?php

declare(strict_types=1);

namespace Tests\Feature\HandoverAcceptance;

use App\BusinessModules\Features\HandoverAcceptance\Models\AcceptanceScope;
use App\BusinessModules\Features\HandoverAcceptance\Models\AcceptanceSession;
use App\BusinessModules\Features\HandoverAcceptance\Services\HandoverAcceptanceService;
use App\Models\Project;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class AcceptanceRetargetTriggerTest extends TestCase
{
    public function test_session_retarget_to_null_project_is_blocked_after_flow_evidence(): void
    {
        [$scope, $session] = $this->scopeWithFlowEvent();

        $exception = $this->captureQueryException(static function () use ($session): void {
            DB::table('acceptance_sessions')->where('id', $session->id)->update([
                'project_id' => null,
            ]);
        });

        $this->assertSame('55000', $exception->errorInfo[0] ?? '');
        $this->assertSame((int) $scope->project_id, (int) DB::table('acceptance_sessions')->where('id', $session->id)->value('project_id'));
    }

    public function test_scope_retarget_to_another_project_is_blocked_and_status_update_still_works(): void
    {
        [$scope, $session] = $this->scopeWithFlowEvent();
        $otherProject = Project::factory()->create(['organization_id' => $scope->organization_id]);

        $scopeException = $this->captureQueryException(static function () use ($scope, $otherProject): void {
            DB::table('acceptance_scopes')->where('id', $scope->id)->update([
                'project_id' => $otherProject->id,
            ]);
        });
        $sessionException = $this->captureQueryException(static function () use ($session, $otherProject): void {
            DB::table('acceptance_sessions')->where('id', $session->id)->update([
                'project_id' => $otherProject->id,
            ]);
        });

        $this->assertSame('55000', $scopeException->errorInfo[0] ?? '');
        $this->assertSame('55000', $sessionException->errorInfo[0] ?? '');

        DB::table('acceptance_scopes')->where('id', $scope->id)->update(['status' => 'findings_open']);
        $this->assertSame('findings_open', DB::table('acceptance_scopes')->where('id', $scope->id)->value('status'));
    }

    /**
     * @return array{0: AcceptanceScope, 1: AcceptanceSession}
     */
    private function scopeWithFlowEvent(): array
    {
        $this->app->forgetInstance(\App\Domain\Authorization\Services\ModulePermissionChecker::class);
        $this->app->forgetInstance(\App\Domain\Authorization\Services\PermissionResolver::class);
        $this->app->forgetInstance(\App\Domain\Authorization\Services\AuthorizationService::class);
        $this->mock(\App\Modules\Core\AccessController::class)->shouldReceive('hasModuleAccess')->andReturnTrue();

        $context = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $scope = AcceptanceScope::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'created_by_user_id' => $context->user->id,
            'title' => 'Приёмка для проверки перенацеливания',
            'status' => 'in_progress',
        ]);
        $session = AcceptanceSession::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'acceptance_scope_id' => $scope->id,
            'created_by_user_id' => $context->user->id,
            'status' => 'in_progress',
            'participant_user_ids' => [],
        ]);
        app(HandoverAcceptanceService::class)->addFinding($session, (int) $context->user->id, [
            'create_quality_defect' => true,
            'title' => 'Зафиксированный источник приёмки',
            'severity' => 'major',
            'quality_defect_inspection_required' => true,
        ]);

        $this->assertTrue(DB::table('quality_defect_flow_events')->where('acceptance_session_id', $session->id)->exists());

        return [$scope, $session];
    }

    private function captureQueryException(callable $operation): QueryException
    {
        try {
            DB::transaction($operation);
            $this->fail('Ожидалось отклонение перенацеливания приёмки');
        } catch (QueryException $exception) {
            return $exception;
        }
    }
}
