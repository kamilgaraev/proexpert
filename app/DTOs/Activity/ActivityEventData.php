<?php

declare(strict_types=1);

namespace App\DTOs\Activity;

use App\Enums\Activity\ActivityActionEnum;
use App\Enums\Activity\ActivityResultEnum;
use App\Enums\Activity\ActivitySeverityEnum;
use Illuminate\Support\Carbon;

final class ActivityEventData
{
    public function __construct(
        public readonly int $organizationId,
        public readonly string $module,
        public readonly string $eventType,
        public readonly ActivityActionEnum|string $action,
        public readonly ?int $actorUserId = null,
        public readonly string $actorType = 'user',
        public readonly ?string $actorName = null,
        public readonly ?string $actorEmail = null,
        public readonly ?string $interface = null,
        public readonly ActivityResultEnum|string $result = ActivityResultEnum::Success,
        public readonly ActivitySeverityEnum|string $severity = ActivitySeverityEnum::Info,
        public readonly ?string $subjectType = null,
        public readonly ?int $subjectId = null,
        public readonly ?string $subjectLabel = null,
        public readonly ?int $projectId = null,
        public readonly ?int $targetUserId = null,
        public readonly ?string $title = null,
        public readonly ?string $description = null,
        public readonly array $changes = [],
        public readonly array $context = [],
        public readonly ?string $ipAddress = null,
        public readonly ?string $userAgent = null,
        public readonly ?string $correlationId = null,
        public readonly ?Carbon $occurredAt = null,
    ) {}

    public static function make(
        int $organizationId,
        string $module,
        string $eventType,
        ActivityActionEnum|string $action,
        ?int $actorUserId = null,
        string $actorType = 'user',
        ?string $actorName = null,
        ?string $actorEmail = null,
        ?string $interface = null,
        ActivityResultEnum|string $result = ActivityResultEnum::Success,
        ActivitySeverityEnum|string $severity = ActivitySeverityEnum::Info,
        ?string $subjectType = null,
        ?int $subjectId = null,
        ?string $subjectLabel = null,
        ?int $projectId = null,
        ?int $targetUserId = null,
        ?string $title = null,
        ?string $description = null,
        array $changes = [],
        array $context = [],
        ?string $ipAddress = null,
        ?string $userAgent = null,
        ?string $correlationId = null,
        ?Carbon $occurredAt = null,
    ): self {
        return new self(
            $organizationId,
            $module,
            $eventType,
            $action,
            $actorUserId,
            $actorType,
            $actorName,
            $actorEmail,
            $interface,
            $result,
            $severity,
            $subjectType,
            $subjectId,
            $subjectLabel,
            $projectId,
            $targetUserId,
            $title,
            $description,
            $changes,
            $context,
            $ipAddress,
            $userAgent,
            $correlationId,
            $occurredAt,
        );
    }

    public function toArray(): array
    {
        return [
            'organization_id' => $this->organizationId,
            'actor_user_id' => $this->actorUserId,
            'actor_type' => $this->actorType,
            'actor_name' => $this->actorName,
            'actor_email' => $this->actorEmail,
            'interface' => $this->interface,
            'module' => $this->module,
            'event_type' => $this->eventType,
            'action' => $this->normalizeEnum($this->action),
            'result' => $this->normalizeEnum($this->result),
            'severity' => $this->normalizeEnum($this->severity),
            'subject_type' => $this->subjectType,
            'subject_id' => $this->subjectId,
            'subject_label' => $this->subjectLabel,
            'project_id' => $this->projectId,
            'target_user_id' => $this->targetUserId,
            'title' => $this->title,
            'description' => $this->description,
            'changes' => $this->changes,
            'context' => $this->context,
            'ip_address' => $this->ipAddress,
            'user_agent' => $this->userAgent,
            'correlation_id' => $this->correlationId,
            'occurred_at' => $this->occurredAt ?? now(),
        ];
    }

    private function normalizeEnum(ActivityActionEnum|ActivityResultEnum|ActivitySeverityEnum|string $value): string
    {
        return $value instanceof \BackedEnum ? (string) $value->value : $value;
    }
}
