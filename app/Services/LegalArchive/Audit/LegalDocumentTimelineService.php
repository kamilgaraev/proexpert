<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Audit;

use App\BusinessModules\Core\ImmutableAudit\DTO\ImmutableAuditEventFilters;
use App\BusinessModules\Core\ImmutableAudit\Models\ImmutableAuditEvent;
use App\BusinessModules\Core\ImmutableAudit\Services\ImmutableAuditQueryService;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\Models\User;
use App\Services\LegalArchive\Access\LegalDocumentAuthorizer;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Lang;

use function trans_message;

final readonly class LegalDocumentTimelineService
{
    public function __construct(
        private ImmutableAuditQueryService $audit,
        private LegalDocumentAuthorizer $access,
    ) {}

    public function paginate(LegalArchiveDocument $document, User $actor, int $page = 1, int $perPage = 50): LengthAwarePaginator
    {
        $this->access->authorizePermission($actor, $document, 'legal_archive.audit.view');
        $events = $this->audit->paginateActivity(new ImmutableAuditEventFilters(
            organizationId: (int) $document->organization_id,
            domain: 'legal_archive',
            subjectType: 'legal_document',
            subjectId: (string) $document->id,
            perPage: $perPage,
            page: $page,
        ));

        return new LengthAwarePaginator(
            array_map($this->present(...), $events->items()),
            $events->total(),
            $events->perPage(),
            $events->currentPage(),
        );
    }

    private function present(ImmutableAuditEvent $event): array
    {
        $actionKey = 'legal_archive.timeline.actions.'.$event->action;
        $resultKey = match ($event->result) {
            'success', 'failure', 'denied', 'pending' => 'legal_archive.timeline.results.'.$event->result,
            default => 'legal_archive.timeline.results.unknown',
        };
        $snapshot = $event->actor_snapshot;
        $name = is_array($snapshot) ? ($snapshot['name'] ?? null) : null;
        if (! is_string($name) || trim($name) === '') {
            $name = $event->actor?->name;
        }
        if (! is_string($name) || trim($name) === '') {
            $name = trans_message($event->actor_type === 'system'
                ? 'legal_archive.timeline.system_actor'
                : 'legal_archive.timeline.unknown_actor');
        }

        return [
            'id' => (string) $event->id,
            'action_label' => trans_message(Lang::has($actionKey) ? $actionKey : 'legal_archive.timeline.unknown_action'),
            'actor_name' => trim($name),
            'occurred_at' => $event->occurred_at?->toIso8601String(),
            'result_label' => trans_message($resultKey),
        ];
    }
}
