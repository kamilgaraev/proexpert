<?php

declare(strict_types=1);

namespace Tests\Unit\Workflow;

use App\DTOs\Workflow\WorkflowSurfaceData;
use PHPUnit\Framework\TestCase;

class WorkflowSurfaceDataTest extends TestCase
{
    public function test_workflow_surface_has_stable_contract_for_clients(): void
    {
        $surface = new WorkflowSurfaceData(
            stage: 'inspection',
            stageLabel: 'Проверка',
            status: 'pending',
            statusLabel: 'Ожидает проверки',
            nextAction: 'approve',
            nextActionLabel: 'Принять',
            availableActions: ['approve', 'reject'],
            problemFlags: [
                [
                    'code' => 'photo_required',
                    'severity' => 'warning',
                    'message' => 'Нужны фотографии результата',
                ],
            ],
            blockers: [],
            warnings: ['Нужны фотографии результата'],
            meta: ['scope' => 'project'],
        );

        $this->assertSame([
            'stage' => 'inspection',
            'stage_label' => 'Проверка',
            'status' => 'pending',
            'status_label' => 'Ожидает проверки',
            'next_action' => 'approve',
            'next_action_label' => 'Принять',
            'available_actions' => ['approve', 'reject'],
            'problem_flags' => [
                [
                    'code' => 'photo_required',
                    'severity' => 'warning',
                    'message' => 'Нужны фотографии результата',
                ],
            ],
            'blockers' => [],
            'warnings' => ['Нужны фотографии результата'],
            'meta' => ['scope' => 'project'],
        ], $surface->toArray());
    }
}
