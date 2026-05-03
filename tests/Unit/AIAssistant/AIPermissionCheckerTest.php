<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Models\Conversation;
use App\BusinessModules\Features\AIAssistant\Actions\Reports\Tools\UpdateScheduleTaskStatusTool;
use App\BusinessModules\Features\AIAssistant\Services\AIPermissionChecker;
use App\Models\User;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class AIPermissionCheckerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function test_regular_member_cannot_execute_privileged_tool(): void
    {
        $checker = new AIPermissionChecker();
        $user = $this->makeUserDouble(15, true, false, false);

        $this->assertFalse($checker->canExecuteTool($user, 'approve_payment_request'));
    }

    public function test_regular_member_can_execute_read_only_tool(): void
    {
        $checker = new AIPermissionChecker();
        $user = $this->makeUserDouble(15, true, false, false);

        $this->assertTrue($checker->canExecuteTool($user, 'search_projects'));
    }

    public function test_domain_snapshot_tools_require_domain_permission(): void
    {
        $checker = new AIPermissionChecker();
        $userWithoutContractAccess = $this->makeUserDouble(15, true, false, false);
        $userWithContractAccess = $this->makeUserDouble(15, true, false, false, 1, ['contracts.view']);

        $this->assertFalse($checker->canExecuteTool($userWithoutContractAccess, 'get_contract_snapshot'));
        $this->assertTrue($checker->canExecuteTool($userWithContractAccess, 'get_contract_snapshot'));
    }

    public function test_admin_can_execute_privileged_tool(): void
    {
        $checker = new AIPermissionChecker();
        $user = $this->makeUserDouble(15, true, true, false);

        $this->assertTrue($checker->canExecuteTool($user, 'create_schedule_task'));
        $this->assertTrue($checker->canExecuteTool($user, 'update_schedule_task_status'));
    }

    public function test_mutation_tool_is_detected_by_name(): void
    {
        $checker = new AIPermissionChecker();

        $this->assertTrue($checker->isMutationTool('update_schedule_task_status'));
        $this->assertTrue($checker->isMutationTool('generate_profitability_report'));
        $this->assertFalse($checker->isMutationTool('search_projects'));
        $this->assertFalse($checker->isMutationTool('get_project_snapshot'));
    }

    public function test_schedule_status_tool_name_matches_permission_mapping(): void
    {
        $tool = new UpdateScheduleTaskStatusTool();
        $checker = new AIPermissionChecker();
        $user = $this->makeUserDouble(15, true, false, false, 1, ['schedule-management.edit']);

        $this->assertSame('update_schedule_task_status', $tool->getName());
        $this->assertTrue($checker->canExecuteTool($user, $tool->getName()));
        $this->assertTrue($checker->canExecuteTool($user, 'update_task_status'));
    }

    public function test_conversation_access_requires_same_user_and_organization(): void
    {
        $checker = new AIPermissionChecker();
        $user = $this->makeUserDouble(15, true, false, false, 42);
        $conversation = new Conversation([
            'organization_id' => 15,
            'user_id' => 99,
        ]);

        $this->assertFalse($checker->canAccessConversation($user, $conversation, 15));
    }

    private function makeUserDouble(
        int $organizationId,
        bool $belongsToOrganization,
        bool $isAdmin,
        bool $isOwner,
        int $userId = 1,
        array $permissions = []
    ): User {
        $user = Mockery::mock(User::class)->makePartial();
        $user->id = $userId;
        $user->current_organization_id = $organizationId;
        $user->shouldReceive('belongsToOrganization')->andReturn($belongsToOrganization);
        $user->shouldReceive('isOrganizationAdmin')->andReturn($isAdmin);
        $user->shouldReceive('isOrganizationOwner')->andReturn($isOwner);
        $user->shouldReceive('isAdminPanelUser')->andReturn($isAdmin || $isOwner);
        $user->shouldReceive('hasPermission')->andReturnUsing(
            static fn (string $permission): bool => in_array($permission, $permissions, true)
        );

        return $user;
    }
}
