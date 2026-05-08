<?php

declare(strict_types=1);

namespace App\DTOs\Activity;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

final class ActivityEventFilters
{
    public function __construct(
        public readonly int $page = 1,
        public readonly int $perPage = 20,
        public readonly ?int $actorUserId = null,
        public readonly ?int $targetUserId = null,
        public readonly ?int $projectId = null,
        public readonly ?string $module = null,
        public readonly ?string $eventType = null,
        public readonly ?string $action = null,
        public readonly ?string $result = null,
        public readonly ?string $severity = null,
        public readonly ?string $subjectType = null,
        public readonly ?int $subjectId = null,
        public readonly ?Carbon $dateFrom = null,
        public readonly ?Carbon $dateTo = null,
        public readonly ?string $search = null,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $validated = method_exists($request, 'validated') ? $request->validated() : $request->query();

        return new self(
            page: max(1, (int) ($validated['page'] ?? 1)),
            perPage: min(100, max(1, (int) ($validated['per_page'] ?? 20))),
            actorUserId: isset($validated['actor_user_id']) ? (int) $validated['actor_user_id'] : null,
            targetUserId: isset($validated['target_user_id']) ? (int) $validated['target_user_id'] : null,
            projectId: isset($validated['project_id']) ? (int) $validated['project_id'] : null,
            module: self::stringOrNull($validated['module'] ?? null),
            eventType: self::stringOrNull($validated['event_type'] ?? null),
            action: self::stringOrNull($validated['action'] ?? null),
            result: self::stringOrNull($validated['result'] ?? null),
            severity: self::stringOrNull($validated['severity'] ?? null),
            subjectType: self::stringOrNull($validated['subject_type'] ?? null),
            subjectId: isset($validated['subject_id']) ? (int) $validated['subject_id'] : null,
            dateFrom: !empty($validated['date_from']) ? Carbon::parse($validated['date_from'])->startOfDay() : null,
            dateTo: !empty($validated['date_to']) ? Carbon::parse($validated['date_to'])->endOfDay() : null,
            search: self::stringOrNull($validated['search'] ?? null),
        );
    }

    private static function stringOrNull(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
