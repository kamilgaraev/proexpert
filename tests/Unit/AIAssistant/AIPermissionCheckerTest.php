<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Models\Conversation;
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

    public function test_admin_can_execute_privileged_tool(): void
    {
        $checker = new AIPermissionChecker();
        $user = $this->makeUserDouble(15, true, true, false);

        $this->assertTrue($checker->canExecuteTool($user, 'create_schedule_task'));
    }

    public function test_mutation_tool_is_detected_by_name(): void
    {
        $checker = new AIPermissionChecker();

        $this->assertTrue($checker->isMutationTool('update_task_status'));
        $this->assertFalse($checker->isMutationTool('search_projects'));
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
        int $userId = 1
    ): User {
        $user = Mockery::mock(User::class)->makePartial();
        $user->id = $userId;
        $user->current_organization_id = $organizationId;
        $user->shouldReceive('belongsToOrganization')->andReturn($belongsToOrganization);
        $user->shouldReceive('isOrganizationAdmin')->andReturn($isAdmin);
        $user->shouldReceive('isOrganizationOwner')->andReturn($isOwner);
        $user->shouldReceive('isAdminPanelUser')->andReturn($isAdmin || $isOwner);
        $user->shouldReceive('hasPermission')->andReturn(false);

        return $user;
    }
}
