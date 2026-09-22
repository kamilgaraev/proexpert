<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ExecutiveDocumentation\Services;

use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentRequirement;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentSet;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Exceptions\BusinessLogicException;
use App\Models\User;
use App\Services\Project\UserProjectAccessService;
use Illuminate\Support\Facades\DB;

final class ExecutiveDocumentRequirementsQueryService
{
    private const MAX_PER_PAGE = 100;

    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly UserProjectAccessService $projectAccess,
        private readonly ExecutiveDocumentRequirementsService $requirements,
    ) {}

    public function active(ExecutiveDocumentSet $set, User $actor, array $filters = []): array
    {
        return DB::transaction(function () use ($set, $actor, $filters): array {
            $freshSet = ExecutiveDocumentSet::query()->sharedLock()->findOrFail($set->id);
            $this->assertCanView($freshSet, $actor);
            $paginator = ExecutiveDocumentRequirement::query()
                ->where('document_set_id', $freshSet->id)
                ->whereNull('superseded_at')
                ->orderBy('id')
                ->paginate($this->perPage($filters), ['*'], 'page', $this->page($filters))
                ->through(static fn (ExecutiveDocumentRequirement $item): array => $item->toArray());

            return [
                'paginator' => $paginator,
                'composition_revision' => $this->compositionRevision((int) $freshSet->id),
                'summary' => $this->requirements->readiness($freshSet),
            ];
        });
    }

    public function history(ExecutiveDocumentSet $set, User $actor, array $filters = []): array
    {
        return DB::transaction(function () use ($set, $actor, $filters): array {
            $freshSet = ExecutiveDocumentSet::query()->sharedLock()->findOrFail($set->id);
            $this->assertCanView($freshSet, $actor);
            $paginator = DB::table('executive_document_requirement_events')
                ->where('document_set_id', $freshSet->id)
                ->orderByDesc('id')
                ->paginate($this->perPage($filters), ['*'], 'page', $this->page($filters))
                ->through(static fn (object $event): array => [
                    'id' => (int) $event->id,
                    'requirement_id' => (int) $event->requirement_id,
                    'document_set_id' => (int) $event->document_set_id,
                    'organization_id' => (int) $event->organization_id,
                    'actor_id' => (int) $event->actor_id,
                    'action' => $event->action,
                    'before_snapshot' => self::decodeSnapshot($event->before_snapshot),
                    'after_snapshot' => self::decodeSnapshot($event->after_snapshot),
                    'created_at' => $event->created_at,
                ]);

            return [
                'paginator' => $paginator,
                'composition_revision' => $this->compositionRevision((int) $freshSet->id),
                'summary' => $this->requirements->readiness($freshSet),
            ];
        });
    }

    private function assertCanView(ExecutiveDocumentSet $set, User $actor): void
    {
        $organizationId = (int) $set->organization_id;
        $project = $set->project;
        if (
            (int) $actor->current_organization_id !== $organizationId
            || ! $actor->belongsToOrganization($organizationId)
            || $project === null
            || ! $this->projectAccess->canAccessProject($actor, $project, $organizationId)
        ) {
            throw new BusinessLogicException(trans_message('executive_documentation.errors.document_not_found'), 404);
        }
        if (! $this->authorization->can($actor, 'executive-documentation.view', ['organization_id' => $organizationId, 'project_id' => (int) $set->project_id, 'strict_project_scope' => true])) {
            throw new BusinessLogicException(trans_message('executive_documentation.errors.forbidden'), 403);
        }
    }

    private function compositionRevision(int $setId): int
    {
        return (int) DB::table('executive_document_requirement_events')->where('document_set_id', $setId)->max('id');
    }

    private function perPage(array $filters): int
    {
        return min(max((int) ($filters['per_page'] ?? 25), 1), self::MAX_PER_PAGE);
    }

    private function page(array $filters): int
    {
        return max((int) ($filters['page'] ?? 1), 1);
    }

    private static function decodeSnapshot(mixed $snapshot): ?array
    {
        if ($snapshot === null) {
            return null;
        }
        if (is_array($snapshot)) {
            return $snapshot;
        }
        $decoded = json_decode((string) $snapshot, true);

        return is_array($decoded) ? $decoded : null;
    }
}
