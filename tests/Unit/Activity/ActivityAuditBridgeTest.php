<?php

declare(strict_types=1);

namespace Tests\Unit\Activity;

use App\Models\Activity\ActivityEvent;
use App\Models\Organization;
use App\Models\User;
use App\Services\Logging\LoggingService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ActivityAuditBridgeTest extends TestCase
{
    #[DataProvider('bridgedAuditEventsProvider')]
    public function test_logging_audit_writes_user_activity_event_for_business_actions(
        string $eventName,
        array $context,
        string $expectedModule,
        string $expectedAction,
        string $expectedSubjectType,
        ?int $expectedSubjectId,
        ?string $expectedSubjectLabel,
    ): void
    {
        $organization = Organization::factory()->create();
        $actor = User::factory()->create([
            'name' => 'Ivan',
            'current_organization_id' => $organization->id,
        ]);

        auth()->setUser($actor);

        app(LoggingService::class)->audit($eventName, array_merge([
            'organization_id' => $organization->id,
            'performed_by' => $actor->id,
        ], $context));

        $event = ActivityEvent::query()->firstOrFail();

        $this->assertSame($organization->id, $event->organization_id);
        $this->assertSame($actor->id, $event->actor_user_id);
        $this->assertSame($expectedModule, $event->module);
        $this->assertSame($eventName, $event->event_type);
        $this->assertSame($expectedAction, $event->action);
        $this->assertSame($expectedSubjectType, $event->subject_type);
        if ($expectedSubjectId === -1) {
            $this->assertSame($organization->id, $event->subject_id);
        } else {
            $this->assertSame($expectedSubjectId, $event->subject_id);
        }
        $this->assertSame($expectedSubjectLabel, $event->subject_label);
        $this->assertNotEmpty($event->title);
        $this->assertNotEmpty($event->description);
        $this->assertStringNotContainsString('fallback', $event->title);
    }

    public function test_explicit_user_activity_events_are_not_duplicated_by_audit_bridge(): void
    {
        $organization = Organization::factory()->create();
        $actor = User::factory()->create(['current_organization_id' => $organization->id]);

        auth()->setUser($actor);

        app(LoggingService::class)->audit('user.admin.role.revoked', [
            'organization_id' => $organization->id,
            'target_user_id' => 10,
            'target_name' => 'Admin',
            'revoked_by' => $actor->id,
        ]);

        $this->assertSame(0, ActivityEvent::query()->count());
    }

    public static function bridgedAuditEventsProvider(): array
    {
        return [
            'auth role assigned' => [
                'auth.role.assigned',
                ['target_user_id' => 10, 'role' => 'organization_admin'],
                'auth',
                'assigned',
                'user_role',
                10,
                'organization_admin',
            ],
            'auth role revoked' => [
                'auth.role.revoked',
                ['target_user_id' => 10, 'role' => 'organization_admin'],
                'auth',
                'revoked',
                'user_role',
                10,
                'organization_admin',
            ],
            'module renewed' => [
                'module.renewed',
                ['module_id' => 7, 'module_slug' => 'system-logs'],
                'modules',
                'created',
                'module',
                7,
                'system-logs',
            ],
            'workflow override used' => [
                'workflow.override.used',
                ['workflow_id' => 8, 'reason' => 'manual approval'],
                'workflow',
                'updated',
                'workflow',
                8,
                'manual approval',
            ],
            'user invitation created' => [
                'user_invitation.created',
                ['invitation_id' => 11, 'email' => 'invite@example.test'],
                'users',
                'created',
                'user_invitation',
                11,
                'invite@example.test',
            ],
            'user invitation accepted' => [
                'user_invitation.accepted',
                ['invitation_id' => 11, 'email' => 'invite@example.test'],
                'users',
                'created',
                'user_invitation',
                11,
                'invite@example.test',
            ],
            'user invitation cancelled' => [
                'user_invitation.cancelled',
                ['invitation_id' => 11, 'email' => 'invite@example.test'],
                'users',
                'cancelled',
                'user_invitation',
                11,
                'invite@example.test',
            ],
            'report exported' => [
                'report.official_material_usage.exported',
                ['report_id' => 12, 'report_name' => 'Materials'],
                'reports',
                'exported',
                'report',
                12,
                'Materials',
            ],
            'report viewed' => [
                'report.official_material_usage.viewed',
                ['report_id' => 12, 'report_name' => 'Materials'],
                'reports',
                'viewed',
                'report',
                12,
                'Materials',
            ],
            'project created' => [
                'project.created',
                ['project_id' => 56, 'project_name' => 'Tower'],
                'projects',
                'created',
                'project',
                56,
                'Tower',
            ],
            'project deleted' => [
                'project.deleted',
                ['project_id' => 56, 'project_name' => 'Tower'],
                'projects',
                'deleted',
                'project',
                56,
                'Tower',
            ],
            'organization verification completed' => [
                'organization.verification.completed',
                ['organization_name' => 'Acme'],
                'organization',
                'updated',
                'organization',
                -1,
                'Acme',
            ],
            'organization data updated' => [
                'organization.data.updated',
                ['organization_name' => 'Acme'],
                'organization',
                'updated',
                'organization',
                -1,
                'Acme',
            ],
            'contractor invitation sent' => [
                'contractor.invitation.sent',
                ['contractor_id' => 13, 'contractor_email' => 'contractor@example.test'],
                'contractors',
                'created',
                'contractor',
                13,
                'contractor@example.test',
            ],
            'agreement applied to contract' => [
                'agreement.applied_to_contract',
                ['agreement_id' => 14, 'agreement_number' => 'DS-1'],
                'agreement',
                'updated',
                'agreement',
                14,
                'DS-1',
            ],
            'contract created' => [
                'contract.created',
                ['contract_id' => 164, 'contract_number' => 'D-1', 'project_id' => 56],
                'contracts',
                'created',
                'contract',
                164,
                'D-1',
            ],
            'contract updated' => [
                'contract.updated',
                ['contract_id' => 164, 'contract_number' => 'D-1', 'project_id' => 56],
                'contracts',
                'updated',
                'contract',
                164,
                'D-1',
            ],
            'contract side review resolved' => [
                'contract.side_review.resolved',
                ['contract_id' => 164, 'contract_number' => 'D-1', 'project_id' => 56],
                'contracts',
                'updated',
                'contract',
                164,
                'D-1',
            ],
            'contract deleted' => [
                'contract.deleted',
                ['contract_id' => 164, 'contract_number' => 'D-1', 'project_id' => 56],
                'contracts',
                'deleted',
                'contract',
                164,
                'D-1',
            ],
            'performance act created' => [
                'performance_act.created',
                ['act_id' => 21, 'act_document_number' => 'ACT-1'],
                'contracts',
                'created',
                'performance_act',
                21,
                'ACT-1',
            ],
            'performance act updated' => [
                'performance_act.updated',
                ['act_id' => 21, 'act_document_number' => 'ACT-1'],
                'contracts',
                'updated',
                'performance_act',
                21,
                'ACT-1',
            ],
            'performance act works modified' => [
                'performance_act.works.modified',
                ['act_id' => 21, 'document_number' => 'ACT-1'],
                'contracts',
                'updated',
                'performance_act',
                21,
                'ACT-1',
            ],
            'performance act deleted' => [
                'performance_act.deleted',
                ['act_id' => 21, 'act_document_number' => 'ACT-1'],
                'contracts',
                'deleted',
                'performance_act',
                21,
                'ACT-1',
            ],
            'completed work created' => [
                'completed_work.created',
                ['completed_work_id' => 31, 'work_type_name' => 'Concrete'],
                'completed-work',
                'created',
                'completed_work',
                31,
                'Concrete',
            ],
            'billing credit' => [
                'billing.transaction.credit',
                ['transaction_id' => 41, 'type' => 'credit'],
                'billing',
                'updated',
                'billing_transaction',
                41,
                'credit',
            ],
            'billing debit' => [
                'billing.transaction.debit',
                ['transaction_id' => 42, 'type' => 'debit'],
                'billing',
                'updated',
                'billing_transaction',
                42,
                'debit',
            ],
            'material created' => [
                'material.created',
                ['material_id' => 51, 'material_name' => 'Cement'],
                'materials',
                'created',
                'material',
                51,
                'Cement',
            ],
            'material bulk import' => [
                'material.bulk.import',
                ['material_id' => null, 'material_name' => 'Import'],
                'materials',
                'updated',
                'material',
                null,
                'Import',
            ],
            'project schedule created' => [
                'project_schedule.created',
                ['schedule_id' => 91, 'schedule_name' => 'Main schedule', 'project_id' => 56],
                'schedules',
                'created',
                'project_schedule',
                91,
                'Main schedule',
            ],
            'project schedule updated' => [
                'project_schedule.updated',
                ['schedule_id' => 91, 'schedule_name' => 'Main schedule', 'project_id' => 56],
                'schedules',
                'updated',
                'project_schedule',
                91,
                'Main schedule',
            ],
            'project schedule deleted' => [
                'project_schedule.deleted',
                ['schedule_id' => 91, 'schedule_name' => 'Main schedule', 'project_id' => 56],
                'schedules',
                'deleted',
                'project_schedule',
                91,
                'Main schedule',
            ],
            'project schedule exported' => [
                'project_schedule.exported',
                ['schedule_id' => 91, 'schedule_name' => 'Main schedule', 'project_id' => 56],
                'schedules',
                'exported',
                'project_schedule',
                91,
                'Main schedule',
            ],
            'project schedule critical path calculated' => [
                'project_schedule.critical_path_calculated',
                ['schedule_id' => 91, 'schedule_name' => 'Main schedule', 'project_id' => 56],
                'schedules',
                'updated',
                'project_schedule',
                91,
                'Main schedule',
            ],
            'project schedule baseline saved' => [
                'project_schedule.baseline_saved',
                ['schedule_id' => 91, 'schedule_name' => 'Main schedule', 'project_id' => 56],
                'schedules',
                'updated',
                'project_schedule',
                91,
                'Main schedule',
            ],
            'project schedule baseline cleared' => [
                'project_schedule.baseline_cleared',
                ['schedule_id' => 91, 'schedule_name' => 'Main schedule', 'project_id' => 56],
                'schedules',
                'updated',
                'project_schedule',
                91,
                'Main schedule',
            ],
            'construction journal created' => [
                'construction_journal.created',
                ['journal_id' => 41, 'journal_number' => 'ЖР-1', 'journal_name' => 'Общий журнал'],
                'construction-journals',
                'created',
                'construction_journal',
                41,
                'ЖР-1',
            ],
            'construction journal updated' => [
                'construction_journal.updated',
                ['journal_id' => 41, 'journal_number' => 'ЖР-1', 'journal_name' => 'Общий журнал'],
                'construction-journals',
                'updated',
                'construction_journal',
                41,
                'ЖР-1',
            ],
            'construction journal deleted' => [
                'construction_journal.deleted',
                ['journal_id' => 41, 'journal_number' => 'ЖР-1', 'journal_name' => 'Общий журнал'],
                'construction-journals',
                'deleted',
                'construction_journal',
                41,
                'ЖР-1',
            ],
            'construction journal entry created' => [
                'construction_journal_entry.created',
                ['journal_entry_id' => 42, 'entry_number' => 3, 'journal_id' => 41],
                'construction-journals',
                'created',
                'construction_journal_entry',
                42,
                '3',
            ],
            'construction journal entry updated' => [
                'construction_journal_entry.updated',
                ['journal_entry_id' => 42, 'entry_number' => 3, 'journal_id' => 41],
                'construction-journals',
                'updated',
                'construction_journal_entry',
                42,
                '3',
            ],
            'construction journal entry deleted' => [
                'construction_journal_entry.deleted',
                ['journal_entry_id' => 42, 'entry_number' => 3, 'journal_id' => 41],
                'construction-journals',
                'deleted',
                'construction_journal_entry',
                42,
                '3',
            ],
            'construction journal entry submitted' => [
                'construction_journal_entry.submitted',
                ['journal_entry_id' => 42, 'entry_number' => 3, 'journal_id' => 41],
                'construction-journals',
                'updated',
                'construction_journal_entry',
                42,
                '3',
            ],
            'construction journal entry approved' => [
                'construction_journal_entry.approved',
                ['journal_entry_id' => 42, 'entry_number' => 3, 'journal_id' => 41],
                'construction-journals',
                'approved',
                'construction_journal_entry',
                42,
                '3',
            ],
            'construction journal entry rejected' => [
                'construction_journal_entry.rejected',
                ['journal_entry_id' => 42, 'entry_number' => 3, 'journal_id' => 41],
                'construction-journals',
                'rejected',
                'construction_journal_entry',
                42,
                '3',
            ],
            'schedule task created' => [
                'schedule_task.created',
                ['schedule_id' => 91, 'task_id' => 92, 'task_name' => 'Foundation'],
                'schedules',
                'created',
                'schedule_task',
                92,
                'Foundation',
            ],
            'schedule task updated' => [
                'schedule_task.updated',
                ['schedule_id' => 91, 'task_id' => 92, 'task_name' => 'Foundation'],
                'schedules',
                'updated',
                'schedule_task',
                92,
                'Foundation',
            ],
            'schedule task deleted' => [
                'schedule_task.deleted',
                ['schedule_id' => 91, 'task_id' => 92, 'task_name' => 'Foundation'],
                'schedules',
                'deleted',
                'schedule_task',
                92,
                'Foundation',
            ],
            'schedule dependency created' => [
                'schedule_dependency.created',
                ['schedule_id' => 91, 'dependency_id' => 93, 'dependency_type' => 'finish_to_start'],
                'schedules',
                'created',
                'schedule_dependency',
                93,
                'finish_to_start',
            ],
            'schedule dependency updated' => [
                'schedule_dependency.updated',
                ['schedule_id' => 91, 'dependency_id' => 93, 'dependency_type' => 'finish_to_start'],
                'schedules',
                'updated',
                'schedule_dependency',
                93,
                'finish_to_start',
            ],
            'schedule dependency deleted' => [
                'schedule_dependency.deleted',
                ['schedule_id' => 91, 'dependency_id' => 93, 'dependency_type' => 'finish_to_start'],
                'schedules',
                'deleted',
                'schedule_dependency',
                93,
                'finish_to_start',
            ],
            'subscription created' => [
                'subscription.created',
                ['subscription_id' => 61, 'plan_name' => 'Pro'],
                'billing',
                'created',
                'subscription',
                61,
                'Pro',
            ],
            'subscription updated' => [
                'subscription.updated',
                ['subscription_id' => 61, 'plan_name' => 'Pro'],
                'billing',
                'updated',
                'subscription',
                61,
                'Pro',
            ],
            'subscription canceled' => [
                'subscription.canceled',
                ['subscription_id' => 61, 'plan_name' => 'Pro'],
                'billing',
                'cancelled',
                'subscription',
                61,
                'Pro',
            ],
            'subscription renewed' => [
                'subscription.renewed',
                ['subscription_id' => 61, 'plan_name' => 'Pro'],
                'billing',
                'created',
                'subscription',
                61,
                'Pro',
            ],
            'custom report created' => [
                'custom_report.created',
                ['report_id' => 71, 'report_name' => 'Weekly'],
                'reports',
                'created',
                'custom_report',
                71,
                'Weekly',
            ],
            'ai action executed' => [
                'ai.assistant.action.executed',
                ['action' => 81, 'tool' => 'project-pulse'],
                'ai-assistant',
                'updated',
                'ai_assistant',
                81,
                'project-pulse',
            ],
        ];
    }
}
