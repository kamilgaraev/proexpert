<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Mobile;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use App\Services\Mobile\MobileMyActionsService;
use App\Services\Mobile\MobileProjectAccessResolver;
use App\Services\Project\UserProjectAccessService;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

final class MobileMyActionsServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        DB::statement('CREATE TEMP TABLE projects (id BIGINT PRIMARY KEY, organization_id BIGINT NOT NULL, name VARCHAR NOT NULL, deleted_at TIMESTAMP NULL) ON COMMIT DROP');
        DB::statement('CREATE TEMP TABLE project_schedules (id BIGINT PRIMARY KEY, project_id BIGINT NOT NULL, organization_id BIGINT NOT NULL, deleted_at TIMESTAMP NULL) ON COMMIT DROP');
        DB::statement('CREATE TEMP TABLE schedule_tasks (id BIGINT PRIMARY KEY, schedule_id BIGINT NOT NULL, organization_id BIGINT NOT NULL, assigned_user_id BIGINT NULL, name VARCHAR NOT NULL, status VARCHAR NOT NULL, planned_end_date DATE NULL, deleted_at TIMESTAMP NULL) ON COMMIT DROP');
        DB::statement('CREATE TEMP TABLE site_requests (id BIGINT PRIMARY KEY, project_id BIGINT NOT NULL, organization_id BIGINT NOT NULL, assigned_to BIGINT NULL, title VARCHAR NOT NULL, status VARCHAR NOT NULL, required_date DATE NULL, deleted_at TIMESTAMP NULL) ON COMMIT DROP');
        DB::statement('CREATE TEMP TABLE purchase_requests (id BIGINT PRIMARY KEY, organization_id BIGINT NOT NULL, site_request_id BIGINT NULL, assigned_to BIGINT NULL, request_number VARCHAR NOT NULL, status VARCHAR NOT NULL, deleted_at TIMESTAMP NULL) ON COMMIT DROP');
        DB::statement('CREATE TEMP TABLE quality_defects (id BIGINT PRIMARY KEY, project_id BIGINT NOT NULL, organization_id BIGINT NOT NULL, assigned_to BIGINT NULL, title VARCHAR NOT NULL, status VARCHAR NOT NULL, due_date DATE NULL, deleted_at TIMESTAMP NULL) ON COMMIT DROP');
        DB::statement('CREATE TEMP TABLE payment_documents (id BIGINT PRIMARY KEY, organization_id BIGINT NOT NULL, project_id BIGINT NULL, document_number VARCHAR NOT NULL, status VARCHAR NOT NULL, due_date DATE NULL, deleted_at TIMESTAMP NULL) ON COMMIT DROP');
        DB::statement('CREATE TEMP TABLE payment_approvals (id BIGINT PRIMARY KEY, payment_document_id BIGINT NOT NULL, organization_id BIGINT NOT NULL, approver_user_id BIGINT NULL, approval_permission VARCHAR NULL, status VARCHAR NOT NULL) ON COMMIT DROP');
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    public function test_results_are_limited_to_assigned_records_in_accessible_projects_and_paginated(): void
    {
        DB::table('projects')->insert([
            ['id' => 10, 'organization_id' => 1, 'name' => 'Project A'],
            ['id' => 99, 'organization_id' => 1, 'name' => 'Project B'],
        ]);
        DB::table('project_schedules')->insert([
            ['id' => 100, 'project_id' => 10, 'organization_id' => 1],
            ['id' => 199, 'project_id' => 99, 'organization_id' => 1],
        ]);
        DB::table('schedule_tasks')->insert([
            ['id' => 1, 'schedule_id' => 100, 'organization_id' => 1, 'assigned_user_id' => 7, 'name' => 'One', 'status' => 'not_started'],
            ['id' => 2, 'schedule_id' => 100, 'organization_id' => 1, 'assigned_user_id' => 7, 'name' => 'Two', 'status' => 'in_progress'],
            ['id' => 3, 'schedule_id' => 100, 'organization_id' => 1, 'assigned_user_id' => 8, 'name' => 'Other user', 'status' => 'not_started'],
            ['id' => 4, 'schedule_id' => 199, 'organization_id' => 1, 'assigned_user_id' => 7, 'name' => 'Other project', 'status' => 'not_started'],
            ['id' => 5, 'schedule_id' => 100, 'organization_id' => 1, 'assigned_user_id' => 7, 'name' => 'Completed', 'status' => 'completed'],
        ]);

        $projectAccess = $this->projectAccess([10]);
        $actor = Mockery::mock(User::class)->makePartial();
        $actor->setAttribute('id', 7);
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->shouldReceive('can')->andReturnUsing(static function (User $user, string $permission, array $context): bool {
            if (($context['strict_project_scope'] ?? false) !== true || ($context['project_id'] ?? null) !== 10) {
                return false;
            }

            return in_array($permission, ['schedule.view', 'schedule.edit'], true);
        });

        $service = new MobileMyActionsService($projectAccess, $authorization);
        $result = $service->paginate($actor, 1, null, 1, 1);

        $this->assertSame(2, $result->total());
        $this->assertSame(2, $result->lastPage());
        $this->assertSame('schedule_task', $result->items()[0]['type']);
        $this->assertSame('schedule_task', $result->items()[0]['route']);
        $this->assertSame(['update'], $result->items()[0]['allowed_actions']);
        $nextPage = $service->paginate($actor, 1, null, 2, 1);
        $this->assertSame(2, $nextPage->items()[0]['id']);
    }

    public function test_quality_actions_require_both_permissions_used_by_the_mobile_detail_routes(): void
    {
        DB::table('projects')->insert(['id' => 10, 'organization_id' => 1, 'name' => 'Project A']);
        DB::table('quality_defects')->insert([
            'id' => 20, 'project_id' => 10, 'organization_id' => 1, 'assigned_to' => 7,
            'title' => 'Assigned defect', 'status' => 'open',
        ]);

        $projectAccess = $this->projectAccess([10]);
        $actor = Mockery::mock(User::class)->makePartial();
        $actor->setAttribute('id', 7);

        $onlyDefectPermission = Mockery::mock(AuthorizationService::class);
        $onlyDefectPermission->shouldReceive('can')->andReturnUsing(
            static fn (User $user, string $permission, array $context): bool =>
                $permission === 'quality-control.defects.view'
                && ($context['strict_project_scope'] ?? false) === true
        );
        $withoutParentView = (new MobileMyActionsService($projectAccess, $onlyDefectPermission))
            ->paginate($actor, 1, null, 1, 20);
        $this->assertSame(0, $withoutParentView->total());

        $bothPermissions = Mockery::mock(AuthorizationService::class);
        $bothPermissions->shouldReceive('can')->andReturnUsing(
            static fn (User $user, string $permission, array $context): bool =>
                in_array($permission, ['quality-control.view', 'quality-control.defects.view'], true)
                && ($context['strict_project_scope'] ?? false) === true
        );
        $withParentView = (new MobileMyActionsService($projectAccess, $bothPermissions))
            ->paginate($actor, 1, null, 1, 20);

        $this->assertSame(1, $withParentView->total());
        $this->assertSame('quality_defect', $withParentView->items()[0]['route']);
    }

    public function test_my_actions_includes_only_assigned_pending_payment_approvals_in_viewable_projects(): void
    {
        DB::table('projects')->insert([
            ['id' => 10, 'organization_id' => 1, 'name' => 'Project A'],
            ['id' => 99, 'organization_id' => 1, 'name' => 'Project B'],
        ]);
        DB::table('payment_documents')->insert([
            ['id' => 30, 'organization_id' => 1, 'project_id' => 10, 'document_number' => 'PAY-30', 'status' => 'submitted'],
            ['id' => 31, 'organization_id' => 1, 'project_id' => 99, 'document_number' => 'PAY-31', 'status' => 'submitted'],
            ['id' => 32, 'organization_id' => 1, 'project_id' => 10, 'document_number' => 'PAY-32', 'status' => 'submitted'],
            ['id' => 33, 'organization_id' => 1, 'project_id' => 10, 'document_number' => 'PAY-33', 'status' => 'submitted'],
        ]);
        DB::table('payment_approvals')->insert([
            ['id' => 1, 'payment_document_id' => 30, 'organization_id' => 1, 'approver_user_id' => 7, 'status' => 'pending'],
            ['id' => 2, 'payment_document_id' => 31, 'organization_id' => 1, 'approver_user_id' => 7, 'status' => 'pending'],
            ['id' => 3, 'payment_document_id' => 32, 'organization_id' => 1, 'approver_user_id' => 8, 'status' => 'pending'],
            ['id' => 4, 'payment_document_id' => 33, 'organization_id' => 1, 'approver_user_id' => 7, 'status' => 'approved'],
        ]);

        $projectAccess = $this->projectAccess([10]);
        $actor = Mockery::mock(User::class)->makePartial();
        $actor->setAttribute('id', 7);
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->shouldReceive('can')->andReturnUsing(static function (User $user, string $permission, array $context): bool {
            return in_array($permission, ['payments.invoice.view', 'payments.transaction.approve'], true)
                && ($context['strict_project_scope'] ?? false) === true
                && ($context['organization_id'] ?? null) === 1
                && ($context['project_id'] ?? null) === 10;
        });

        $result = (new MobileMyActionsService($projectAccess, $authorization))->paginate($actor, 1, null, 1, 20);

        $this->assertSame(1, $result->total());
        $this->assertSame('payment_document', $result->items()[0]['type']);
        $this->assertSame('payment_document', $result->items()[0]['route']);
        $this->assertSame(30, $result->items()[0]['id']);
        $this->assertSame('PAY-30', $result->items()[0]['title']);
        $this->assertSame(['view', 'approve'], $result->items()[0]['allowed_actions']);
    }

    public function test_my_actions_includes_only_assigned_purchase_requests_from_viewable_projects(): void
    {
        DB::table('projects')->insert([
            ['id' => 10, 'organization_id' => 1, 'name' => 'Project A'],
            ['id' => 99, 'organization_id' => 1, 'name' => 'Project B'],
        ]);
        DB::table('site_requests')->insert([
            ['id' => 40, 'project_id' => 10, 'organization_id' => 1, 'title' => 'Site request A', 'status' => 'pending'],
            ['id' => 41, 'project_id' => 99, 'organization_id' => 1, 'title' => 'Site request B', 'status' => 'pending'],
        ]);
        DB::table('purchase_requests')->insert([
            ['id' => 50, 'organization_id' => 1, 'site_request_id' => 40, 'assigned_to' => 7, 'request_number' => 'PR-50', 'status' => 'pending'],
            ['id' => 51, 'organization_id' => 1, 'site_request_id' => 41, 'assigned_to' => 7, 'request_number' => 'PR-51', 'status' => 'pending'],
            ['id' => 52, 'organization_id' => 1, 'site_request_id' => 40, 'assigned_to' => 8, 'request_number' => 'PR-52', 'status' => 'pending'],
            ['id' => 53, 'organization_id' => 1, 'site_request_id' => 40, 'assigned_to' => 7, 'request_number' => 'PR-53', 'status' => 'cancelled'],
        ]);

        $projectAccess = $this->projectAccess([10]);
        $actor = Mockery::mock(User::class)->makePartial();
        $actor->setAttribute('id', 7);
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->shouldReceive('can')->andReturnUsing(static function (User $user, string $permission, array $context): bool {
            return $permission === 'procurement.purchase_requests.view'
                && ($context['strict_project_scope'] ?? false) === true
                && ($context['organization_id'] ?? null) === 1
                && ($context['project_id'] ?? null) === 10;
        });

        $result = (new MobileMyActionsService($projectAccess, $authorization))->paginate($actor, 1, null, 1, 20);

        $this->assertSame(1, $result->total());
        $this->assertSame('purchase_request', $result->items()[0]['type']);
        $this->assertSame('purchase_request', $result->items()[0]['route']);
        $this->assertSame(50, $result->items()[0]['id']);
        $this->assertSame('PR-50', $result->items()[0]['title']);
        $this->assertSame(['view'], $result->items()[0]['allowed_actions']);
    }

    public function test_my_actions_includes_permission_based_payment_approval_only_with_right_and_project_access(): void
    {
        DB::table('projects')->insert([
            ['id' => 10, 'organization_id' => 1, 'name' => 'Project A'],
            ['id' => 99, 'organization_id' => 1, 'name' => 'Project B'],
        ]);
        DB::table('payment_documents')->insert([
            ['id' => 30, 'organization_id' => 1, 'project_id' => 10, 'document_number' => 'PAY-30', 'status' => 'submitted'],
            ['id' => 31, 'organization_id' => 1, 'project_id' => 99, 'document_number' => 'PAY-31', 'status' => 'submitted'],
        ]);
        DB::table('payment_approvals')->insert([
            ['id' => 1, 'payment_document_id' => 30, 'organization_id' => 1, 'approval_permission' => 'payments.transaction.approve', 'status' => 'pending'],
            ['id' => 2, 'payment_document_id' => 31, 'organization_id' => 1, 'approval_permission' => 'payments.transaction.approve', 'status' => 'pending'],
        ]);

        $actor = Mockery::mock(User::class)->makePartial();
        $actor->setAttribute('id', 7);
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->shouldReceive('can')->andReturnUsing(static fn (User $user, string $permission, array $context): bool =>
            ($permission === 'payments.transaction.approve'
                && ($context['organization_id'] ?? null) === 1
                && ! isset($context['project_id']))
            || (in_array($permission, ['payments.invoice.view', 'payments.transaction.approve'], true)
                && ($context['project_id'] ?? null) === 10));

        $result = (new MobileMyActionsService($this->projectAccess([10]), $authorization))
            ->paginate($actor, 1, null, 1, 20);

        $this->assertSame(1, $result->total());
        $this->assertSame(30, $result->items()[0]['id']);
        $this->assertSame(['view', 'approve'], $result->items()[0]['allowed_actions']);
    }

    /** @param list<int> $accessibleProjectIds */
    private function projectAccess(array $accessibleProjectIds): MobileProjectAccessResolver
    {
        $access = Mockery::mock(UserProjectAccessService::class);
        $access->shouldReceive('queryAccessibleProjects')->andReturnUsing(
            static fn (User $user, int $organizationId) => \App\Models\Project::query()
                ->where('projects.organization_id', $organizationId)
                ->whereIn('projects.id', $accessibleProjectIds)
        );

        return new MobileProjectAccessResolver($access);
    }
}
