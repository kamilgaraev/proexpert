<?php

declare(strict_types=1);

namespace Tests\Feature\LegalArchive;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class LegalWorkflowStepGuardPostgresTest extends TestCase
{
    public function test_active_step_can_be_approved_without_resolving_instance_only_columns(): void
    {
        self::assertSame('pgsql', DB::getDriverName());

        DB::statement('ALTER TABLE legal_workflow_steps DISABLE TRIGGER ALL');
        try {
            $stepId = DB::table('legal_workflow_steps')->insertGetId([
                'instance_id' => PHP_INT_MAX - 10,
                'organization_id' => PHP_INT_MAX - 11,
                'step_key' => 'review',
                'label' => 'Согласование',
                'sequence' => 1,
                'parallel_group' => 'sequence-1',
                'required' => true,
                'policy_key' => 'legal_review',
                'actor_type' => 'role',
                'actor_reference' => 'organization_owner',
                'status' => 'active',
                'lock_version' => 0,
                'assignment_revision' => 0,
                'due_in_hours' => 24,
                'activated_at' => now(),
                'due_at' => now()->addDay(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::statement('ALTER TABLE legal_workflow_steps ENABLE TRIGGER legal_workflow_steps_immutable_guard');
            self::assertSame(1, DB::table('legal_workflow_steps')->where('id', $stepId)->update([
                'status' => 'approved',
                'lock_version' => 1,
                'completed_at' => now(),
                'updated_at' => now(),
            ]));
        } finally {
            DB::statement('ALTER TABLE legal_workflow_steps ENABLE TRIGGER ALL');
        }
        self::assertSame('approved', DB::table('legal_workflow_steps')->where('id', $stepId)->value('status'));
    }

    public function test_in_progress_instance_can_be_approved_without_resolving_step_only_columns(): void
    {
        self::assertSame('pgsql', DB::getDriverName());

        DB::statement('ALTER TABLE legal_workflow_instances DISABLE TRIGGER ALL');
        try {
            $instanceId = DB::table('legal_workflow_instances')->insertGetId([
                'organization_id' => PHP_INT_MAX - 20,
                'document_id' => PHP_INT_MAX - 21,
                'document_version_id' => PHP_INT_MAX - 22,
                'document_content_hash' => str_repeat('a', 64),
                'template_id' => PHP_INT_MAX - 23,
                'template_version' => 1,
                'template_definition_hash' => str_repeat('b', 64),
                'template_snapshot' => '{}',
                'snapshot_hash' => str_repeat('c', 64),
                'client_request_hash' => str_repeat('d', 64),
                'request_hash' => str_repeat('e', 64),
                'idempotency_key' => 'approve-instance-regression',
                'status' => 'in_progress',
                'lock_version' => 1,
                'submitted_by_user_id' => PHP_INT_MAX - 24,
                'submitted_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::statement('ALTER TABLE legal_workflow_instances ENABLE TRIGGER legal_workflow_instances_immutable_guard');
            self::assertSame(1, DB::table('legal_workflow_instances')->where('id', $instanceId)->update([
                'status' => 'approved',
                'lock_version' => 2,
                'completed_at' => now(),
                'updated_at' => now(),
            ]));
        } finally {
            DB::statement('ALTER TABLE legal_workflow_instances ENABLE TRIGGER ALL');
        }
        self::assertSame('approved', DB::table('legal_workflow_instances')->where('id', $instanceId)->value('status'));
    }
}
