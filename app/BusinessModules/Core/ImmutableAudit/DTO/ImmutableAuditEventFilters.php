<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\ImmutableAudit\DTO;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

final class ImmutableAuditEventFilters
{
    public function __construct(
        public readonly int $organizationId,
        public readonly ?string $domain = null,
        public readonly ?string $eventType = null,
        public readonly ?string $action = null,
        public readonly ?string $result = null,
        public readonly ?string $severity = null,
        public readonly ?string $integrityStatus = null,
        public readonly ?int $actorUserId = null,
        public readonly ?int $projectId = null,
        public readonly ?string $subjectType = null,
        public readonly ?string $subjectId = null,
        public readonly ?string $correlationId = null,
        public readonly ?string $source = null,
        public readonly ?string $chainScope = null,
        public readonly ?Carbon $occurredFrom = null,
        public readonly ?Carbon $occurredTo = null,
        public readonly int $perPage = 50,
        public readonly int $page = 1,
    ) {}

    public static function fromRequest(Request $request, int $organizationId): self
    {
        $validated = method_exists($request, 'validated') ? $request->validated() : $request->query();

        return new self(
            organizationId: $organizationId,
            domain: self::stringOrNull($validated['domain'] ?? null),
            eventType: self::stringOrNull($validated['event_type'] ?? null),
            action: self::stringOrNull($validated['action'] ?? null),
            result: self::stringOrNull($validated['result'] ?? null),
            severity: self::stringOrNull($validated['severity'] ?? null),
            integrityStatus: self::stringOrNull($validated['integrity_status'] ?? null),
            actorUserId: isset($validated['actor_user_id']) ? (int) $validated['actor_user_id'] : null,
            projectId: isset($validated['project_id']) ? (int) $validated['project_id'] : null,
            subjectType: self::stringOrNull($validated['subject_type'] ?? null),
            subjectId: self::stringOrNull($validated['subject_id'] ?? null),
            correlationId: self::stringOrNull($validated['correlation_id'] ?? null),
            source: self::stringOrNull($validated['source'] ?? null),
            chainScope: self::stringOrNull($validated['chain_scope'] ?? null),
            occurredFrom: ! empty($validated['date_from']) ? Carbon::parse($validated['date_from'])->startOfDay() : null,
            occurredTo: ! empty($validated['date_to']) ? Carbon::parse($validated['date_to'])->endOfDay() : null,
            perPage: min(100, max(1, (int) ($validated['per_page'] ?? 50))),
            page: max(1, (int) ($validated['page'] ?? 1)),
        );
    }

    private static function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
