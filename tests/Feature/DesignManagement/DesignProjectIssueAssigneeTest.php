<?php

declare(strict_types=1);

namespace Tests\Feature\DesignManagement;

use App\BusinessModules\Features\QualityControl\Models\QualityDefect;
use App\BusinessModules\Features\QualityControl\Services\QualityDefectService;
use App\Models\Project;
use App\Models\User;
use DomainException;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class DesignProjectIssueAssigneeTest extends TestCase
{
    public function test_create_and_assignment_require_active_membership_in_the_same_project(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $otherProject = Project::factory()->create(['organization_id' => $context->organization->id]);
        $recipient = User::factory()->create(['current_organization_id' => $context->organization->id]);
        $context->organization->users()->attach($recipient->id, ['is_active' => true]);
        $otherProject->users()->attach($recipient->id, ['role' => 'project_manager', 'is_active' => true]);
        $service = app(QualityDefectService::class);
        $payload = ['project_id' => $project->id, 'kind' => 'project', 'title' => 'Проверка ответственного', 'severity' => 'major', 'inspection_required' => false];
        $issue = $service->create($context->organization->id, $context->user->id, $payload);

        foreach (['other_project', 'inactive_project', 'inactive_organization'] as $scenario) {
            if ($scenario === 'inactive_project') {
                $project->users()->attach($recipient->id, ['role' => 'project_manager', 'is_active' => false]);
            } elseif ($scenario === 'inactive_organization') {
                $project->users()->updateExistingPivot($recipient->id, ['is_active' => true]);
                $context->organization->users()->updateExistingPivot($recipient->id, ['is_active' => false]);
            }

            foreach (['create', 'assign'] as $operation) {
                try {
                    if ($operation === 'create') {
                        $service->create($context->organization->id, $context->user->id, $payload + ['assigned_to' => $recipient->id]);
                    } else {
                        $service->assign($issue, $recipient->id, $context->user->id);
                    }
                    self::fail($scenario.': '.$operation.' must reject the assignee');
                } catch (DomainException $exception) {
                    self::assertSame(trans_message('design_issues.errors.assignee_not_in_project'), $exception->getMessage());
                }
                self::assertNull($issue->fresh()->assigned_to);
                self::assertSame(1, QualityDefect::query()->where('project_id', $project->id)->count());
            }
        }

        $context->organization->users()->updateExistingPivot($recipient->id, ['is_active' => true]);
        $assigned = $service->assign($issue, $recipient->id, $context->user->id);
        self::assertSame($recipient->id, $assigned->assigned_to);
        $created = $service->create($context->organization->id, $context->user->id, $payload + ['assigned_to' => $recipient->id]);
        self::assertSame($recipient->id, $created->assigned_to);
    }
}
