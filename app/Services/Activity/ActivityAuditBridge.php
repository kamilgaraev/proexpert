<?php

declare(strict_types=1);

namespace App\Services\Activity;

use App\DTOs\Activity\ActivityEventData;
use App\Enums\Activity\ActivityActionEnum;
use App\Enums\Activity\ActivityResultEnum;
use App\Enums\Activity\ActivitySeverityEnum;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use Throwable;

final class ActivityAuditBridge
{
    public function __construct(
        private readonly ActivityEventRecorder $recorder,
    ) {
    }

    public function record(string $event, array $context = []): void
    {
        if (!$this->shouldRecord($event)) {
            return;
        }

        $organizationId = $this->resolveOrganizationId($context);

        if ($organizationId === null) {
            return;
        }

        try {
            $actor = $this->resolveActor($context);
            $subject = $this->resolveSubject($event, $context);

            $this->recorder->record(ActivityEventData::make(
                organizationId: $organizationId,
                module: $this->resolveModule($event),
                eventType: $event,
                action: $this->resolveAction($event),
                actorUserId: $actor?->id,
                actorName: $actor?->name,
                actorEmail: $actor?->email,
                interface: $this->resolveInterface(),
                result: ActivityResultEnum::Success,
                severity: $this->resolveSeverity($event),
                subjectType: $subject['type'],
                subjectId: $subject['id'],
                subjectLabel: $subject['label'],
                projectId: $this->resolveProjectId($context),
                targetUserId: $this->resolveTargetUserId($context),
                changes: $this->resolveChanges($context),
                context: $this->normalizeContext($context),
                ipAddress: request()?->ip(),
                userAgent: request()?->userAgent(),
                correlationId: $this->stringValue($context['correlation_id'] ?? request()?->headers->get('X-Request-Id')),
            ));
        } catch (Throwable) {
            return;
        }
    }

    private function shouldRecord(string $event): bool
    {
        if (Str::startsWith($event, ['auth.admin.', 'user.admin.'])) {
            return false;
        }

        $events = Lang::get('activity.events');

        return is_array($events) && array_key_exists($event, $events);
    }

    private function resolveOrganizationId(array $context): ?int
    {
        $organizationId = $this->intValue($context['organization_id'] ?? null)
            ?? $this->intValue(request()?->attributes->get('current_organization_id'))
            ?? $this->intValue(Auth::user()?->current_organization_id);

        return $organizationId > 0 ? $organizationId : null;
    }

    private function resolveActor(array $context): ?User
    {
        $actorId = $this->intValue($context['performed_by'] ?? null)
            ?? $this->intValue($context['created_by'] ?? null)
            ?? $this->intValue($context['updated_by'] ?? null)
            ?? $this->intValue($context['deleted_by'] ?? null)
            ?? $this->intValue($context['revoked_by'] ?? null)
            ?? $this->intValue($context['approved_by'] ?? null)
            ?? $this->intValue($context['rejected_by'] ?? null)
            ?? $this->intValue(Auth::id());

        $currentUser = Auth::user();

        if ($currentUser instanceof User && $actorId === $currentUser->id) {
            return $currentUser;
        }

        return $actorId ? User::query()->find($actorId) : null;
    }

    private function resolveModule(string $event): string
    {
        return match (true) {
            Str::startsWith($event, 'contract.'), Str::startsWith($event, 'performance_act.') => 'contracts',
            Str::startsWith($event, ['construction_journal.', 'construction_journal_entry.']) => 'construction-journals',
            Str::startsWith($event, ['project_schedule.', 'schedule_task.', 'schedule_dependency.']) => 'schedules',
            Str::startsWith($event, 'project.') => 'projects',
            Str::startsWith($event, 'material.') => 'materials',
            Str::startsWith($event, 'time_tracking.') => 'time-tracking',
            Str::startsWith($event, 'user_invitation.') => 'users',
            Str::startsWith($event, 'contractor.') => 'contractors',
            Str::startsWith($event, 'subscription.'), Str::startsWith($event, 'billing.') => 'billing',
            Str::startsWith($event, 'report.') => 'reports',
            Str::startsWith($event, 'organization.') => 'organization',
            Str::startsWith($event, 'module.') => 'modules',
            Str::startsWith($event, 'workflow.') => 'workflow',
            Str::startsWith($event, 'ai.') => 'ai-assistant',
            default => (string) Str::of($event)->before('.')->replace('_', '-'),
        };
    }

    private function resolveAction(string $event): ActivityActionEnum
    {
        return match (true) {
            Str::contains($event, ['.created', '.sent', '.accepted', '.renewed']) => ActivityActionEnum::Created,
            Str::contains($event, ['.updated', '.modified', '.resolved', '.used', '.submitted']) => ActivityActionEnum::Updated,
            Str::contains($event, ['.cancelled', '.canceled']) => ActivityActionEnum::Cancelled,
            Str::contains($event, '.deleted') => ActivityActionEnum::Deleted,
            Str::contains($event, '.approved') => ActivityActionEnum::Approved,
            Str::contains($event, '.rejected') => ActivityActionEnum::Rejected,
            Str::contains($event, '.assigned') => ActivityActionEnum::Assigned,
            Str::contains($event, '.revoked') => ActivityActionEnum::Revoked,
            Str::contains($event, '.exported') => ActivityActionEnum::Exported,
            Str::contains($event, '.viewed') => ActivityActionEnum::Viewed,
            default => ActivityActionEnum::Updated,
        };
    }

    private function resolveSeverity(string $event): ActivitySeverityEnum
    {
        return match (true) {
            Str::contains($event, ['deleted', 'cancelled', 'canceled', 'rejected', 'revoked']) => ActivitySeverityEnum::Warning,
            Str::contains($event, ['approved', 'resolved', 'renewed']) => ActivitySeverityEnum::Notice,
            default => ActivitySeverityEnum::Info,
        };
    }

    private function resolveSubject(string $event, array $context): array
    {
        if (Str::startsWith($event, 'contract.')) {
            return $this->subject('contract', $context['contract_id'] ?? null, $context['contract_number'] ?? null);
        }

        if (Str::startsWith($event, 'performance_act.')) {
            return $this->subject('performance_act', $context['act_id'] ?? null, $context['act_document_number'] ?? $context['document_number'] ?? null);
        }

        if (Str::startsWith($event, 'project_schedule.')) {
            return $this->subject('project_schedule', $context['schedule_id'] ?? null, $context['schedule_name'] ?? null);
        }

        if (Str::startsWith($event, 'construction_journal_entry.')) {
            return $this->subject(
                'construction_journal_entry',
                $context['journal_entry_id'] ?? null,
                $context['entry_number'] ?? $context['entry_date'] ?? null
            );
        }

        if (Str::startsWith($event, 'construction_journal.')) {
            return $this->subject(
                'construction_journal',
                $context['journal_id'] ?? null,
                $context['journal_number'] ?? $context['journal_name'] ?? null
            );
        }

        if (Str::startsWith($event, 'schedule_task.')) {
            return $this->subject('schedule_task', $context['task_id'] ?? null, $context['task_name'] ?? null);
        }

        if (Str::startsWith($event, 'schedule_dependency.')) {
            return $this->subject('schedule_dependency', $context['dependency_id'] ?? null, $context['dependency_name'] ?? $context['dependency_type'] ?? null);
        }

        if (Str::startsWith($event, 'auth.role.')) {
            return $this->subject('user_role', $context['target_user_id'] ?? null, $context['role'] ?? $context['role_slug'] ?? null);
        }

        if (Str::startsWith($event, 'project.')) {
            return $this->subject('project', $context['project_id'] ?? null, $context['project_name'] ?? null);
        }

        if (Str::startsWith($event, 'material.')) {
            return $this->subject('material', $context['material_id'] ?? null, $context['material_name'] ?? null);
        }

        if (Str::startsWith($event, 'completed_work.')) {
            return $this->subject('completed_work', $context['completed_work_id'] ?? null, $context['work_type_name'] ?? null);
        }

        if (Str::startsWith($event, 'time_tracking.')) {
            return $this->subject('time_entry', $context['time_entry_id'] ?? null, $context['worker_name'] ?? null);
        }

        if (Str::startsWith($event, 'user_invitation.')) {
            return $this->subject('user_invitation', $context['invitation_id'] ?? null, $context['email'] ?? $context['target_email'] ?? null);
        }

        if (Str::startsWith($event, 'organization.')) {
            return $this->subject('organization', $context['organization_id'] ?? null, $context['organization_name'] ?? null);
        }

        if (Str::startsWith($event, 'contractor.')) {
            return $this->subject('contractor', $context['contractor_id'] ?? null, $context['contractor_name'] ?? $context['contractor_email'] ?? null);
        }

        if (Str::startsWith($event, 'agreement.')) {
            return $this->subject('agreement', $context['agreement_id'] ?? null, $context['agreement_number'] ?? null);
        }

        if (Str::startsWith($event, 'billing.')) {
            return $this->subject('billing_transaction', $context['transaction_id'] ?? null, $context['type'] ?? null);
        }

        if (Str::startsWith($event, 'subscription.')) {
            return $this->subject('subscription', $context['subscription_id'] ?? null, $context['plan_name'] ?? $context['status'] ?? null);
        }

        if (Str::startsWith($event, 'report.')) {
            return $this->subject('report', $context['report_id'] ?? null, $context['report_name'] ?? $context['period'] ?? null);
        }

        if (Str::startsWith($event, 'module.')) {
            return $this->subject('module', $context['module_id'] ?? null, $context['module_slug'] ?? $context['module_name'] ?? null);
        }

        if (Str::startsWith($event, 'workflow.')) {
            return $this->subject('workflow', $context['workflow_id'] ?? null, $context['reason'] ?? null);
        }

        if (Str::startsWith($event, 'ai.')) {
            return $this->subject('ai_assistant', $context['action'] ?? null, $context['tool'] ?? $context['action_name'] ?? null);
        }

        return $this->subject(
            (string) Str::of($event)->before('.')->replace('_', '-'),
            $context['subject_id'] ?? $context['id'] ?? null,
            $context['subject_name'] ?? $context['name'] ?? null,
        );
    }

    private function resolveProjectId(array $context): ?int
    {
        $projectId = $this->intValue($context['project_id'] ?? null);

        if ($projectId === null) {
            return null;
        }

        return Project::query()->whereKey($projectId)->exists() ? $projectId : null;
    }

    private function resolveTargetUserId(array $context): ?int
    {
        $targetUserId = $this->intValue($context['target_user_id'] ?? null);

        if ($targetUserId === null) {
            return null;
        }

        return User::query()->whereKey($targetUserId)->exists() ? $targetUserId : null;
    }

    private function subject(string $type, mixed $id, mixed $label): array
    {
        return [
            'type' => $type,
            'id' => $this->intValue($id),
            'label' => $this->stringValue($label),
        ];
    }

    private function resolveChanges(array $context): array
    {
        $changes = $context['changes'] ?? [];

        return is_array($changes) ? $changes : [];
    }

    private function normalizeContext(array $context): array
    {
        unset($context['changes']);

        return $context;
    }

    private function resolveInterface(): ?string
    {
        $path = request()?->path();

        return is_string($path) && Str::contains($path, '/admin') ? 'admin' : null;
    }

    private function intValue(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }

    private function stringValue(mixed $value): ?string
    {
        if ($value === null || is_array($value) || is_object($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
