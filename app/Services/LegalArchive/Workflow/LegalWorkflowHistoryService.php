<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Workflow;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\Models\User;
use App\Services\LegalArchive\Access\LegalDocumentAuthorizer;
use Illuminate\Database\Query\JoinClause;

final readonly class LegalWorkflowHistoryService
{
    public function __construct(private LegalDocumentAuthorizer $access) {}

    public function forDocument(User $actor, LegalArchiveDocument $document, ?int $beforeId = null): array
    {
        $this->access->authorizePermission($actor, $document, LegalWorkflowPermissions::VIEW);
        $items = $document->getConnection()->table('legal_workflow_decisions as decisions')
            ->join('legal_workflow_instances as instances', static function (JoinClause $join): void {
                $join->on('instances.id', '=', 'decisions.instance_id')
                    ->on('instances.organization_id', '=', 'decisions.organization_id')
                    ->on('instances.document_id', '=', 'decisions.document_id');
            })
            ->leftJoin('users as actors', 'actors.id', '=', 'decisions.actor_user_id')
            ->leftJoin('legal_workflow_steps as steps', static function (JoinClause $join): void {
                $join->on('steps.id', '=', 'decisions.step_id')
                    ->on('steps.organization_id', '=', 'decisions.organization_id')
                    ->on('steps.instance_id', '=', 'decisions.instance_id');
            })
            ->leftJoin('legal_archive_document_versions as versions', static function (JoinClause $join): void {
                $join->on('versions.id', '=', 'decisions.document_version_id')
                    ->on('versions.organization_id', '=', 'decisions.organization_id')
                    ->on('versions.document_id', '=', 'decisions.document_id');
            })
            ->where('decisions.organization_id', (int) $document->organization_id)
            ->where('decisions.document_id', (int) $document->id)
            ->where('instances.organization_id', (int) $document->organization_id)
            ->where('instances.document_id', (int) $document->id)
            ->when($beforeId !== null, static fn ($query) => $query->where('decisions.id', '<', $beforeId))
            ->orderByDesc('decisions.id')
            ->limit(21)
            ->get([
                'decisions.id', 'decisions.action', 'decisions.comment', 'decisions.reason',
                'decisions.decided_at', 'decisions.actor_type', 'actors.name as actor_name',
                'steps.label as step_label', 'versions.version_number',
            ]);
        $page = $items->take(20)->values();

        return [
            'items' => $page,
            'next_before_id' => $items->count() > 20 ? (int) $page->last()->id : null,
        ];
    }
}
