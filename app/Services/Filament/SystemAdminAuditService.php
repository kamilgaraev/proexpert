<?php

declare(strict_types=1);

namespace App\Services\Filament;

use App\DTOs\Activity\ActivityEventData;
use App\Enums\Activity\ActivityActionEnum;
use App\Enums\Activity\ActivitySeverityEnum;
use App\Models\Activity\ActivityEvent;
use App\Models\Organization;
use App\Models\SystemAdmin;
use App\Services\Activity\ActivityEventRecorder;
use App\Filament\Support\SystemAdminAccess;
use Illuminate\Database\Eloquent\Model;

use function trans_message;

final class SystemAdminAuditService
{
    public function __construct(
        private readonly ActivityEventRecorder $recorder,
    ) {}

    public function record(
        SystemAdmin $actor,
        string $eventType,
        ActivityActionEnum|string $action,
        ?string $subjectType = null,
        ?int $subjectId = null,
        ?string $subjectLabel = null,
        ?int $organizationId = null,
        ?string $title = null,
        ?string $description = null,
        array $before = [],
        array $after = [],
        array $context = [],
    ): ?ActivityEvent {
        return $this->recorder->record(ActivityEventData::make(
            organizationId: $organizationId,
            module: 'system_admin',
            eventType: $eventType,
            action: $action,
            actorUserId: null,
            actorType: 'system_admin',
            actorName: $actor->name,
            actorEmail: $actor->email,
            interface: 'admin',
            severity: ActivitySeverityEnum::Warning,
            subjectType: $subjectType,
            subjectId: $subjectId,
            subjectLabel: $subjectLabel,
            title: $title,
            description: $description,
            changes: [
                'before' => $before,
                'after' => $after,
            ],
            context: array_merge([
                'actor_system_admin_id' => $actor->id,
                'actor_system_admin_role' => $actor->getRoleSlug(),
            ], $context),
            ipAddress: request()->ip(),
            userAgent: request()->userAgent(),
            correlationId: request()->headers->get('X-Request-Id'),
        ));
    }

    public function recordDeletedModel(Model $record, string $resourceClass): ?ActivityEvent
    {
        $actor = SystemAdminAccess::user();

        if ($actor === null) {
            return null;
        }

        $subjectLabel = $this->resolveSubjectLabel($record);

        return $this->record(
            actor: $actor,
            eventType: 'system_admin.filament.deleted',
            action: ActivityActionEnum::Deleted,
            subjectType: $record::class,
            subjectId: $this->resolveSubjectId($record),
            subjectLabel: $subjectLabel,
            organizationId: $this->resolveOrganizationId($record),
            title: trans_message('filament_actions.audit.deleted_title', ['subject' => $subjectLabel]),
            description: trans_message('filament_actions.audit.deleted_description', ['subject' => $subjectLabel]),
            before: $record->getAttributes(),
            context: [
                'resource_class' => $resourceClass,
                'table' => $record->getTable(),
            ],
        );
    }

    private function resolveOrganizationId(Model $record): ?int
    {
        if ($record instanceof Organization) {
            return (int) $record->getKey();
        }

        $value = $record->getAttribute('organization_id');

        return is_numeric($value) ? (int) $value : null;
    }

    private function resolveSubjectId(Model $record): ?int
    {
        $key = $record->getKey();

        return is_numeric($key) ? (int) $key : null;
    }

    private function resolveSubjectLabel(Model $record): string
    {
        foreach (['name', 'title', 'email', 'slug'] as $attribute) {
            $value = $record->getAttribute($attribute);

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return sprintf('%s #%s', class_basename($record), (string) $record->getKey());
    }
}

