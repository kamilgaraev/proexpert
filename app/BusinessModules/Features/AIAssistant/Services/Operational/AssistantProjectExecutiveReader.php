<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Operational;

use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentSet;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentVersion;
use App\BusinessModules\Features\ExecutiveDocumentation\Services\ExecutiveDocumentationWorkflowService;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;

final readonly class AssistantProjectExecutiveReader extends AssistantProjectOperationalReader
{
    public const TYPE = 'live_project_executive_readiness_projection';

    public function __construct(AssistantDataAccessPolicy $access, private ExecutiveDocumentationWorkflowService $workflow)
    {
        parent::__construct($access);
    }

    public static function matches(string $query): bool
    {
        return preg_match('/исполнительн[а-яё]*\s+(?:документ|комплект)|комплект[а-яё]*\s+ид\b/iu', $query) === 1
            && preg_match('/создай|удали|отправь|передай|подпиши|измени|оплат|бюджет|смет|журнал|охран|коммерческ|производствен[а-яё]*\s+наряд|проектн[а-яё]*\s+комплект/iu', $query) !== 1;
    }

    protected function domain(): string { return 'documents'; }

    protected function collect(User $actor, int $organizationId, int $projectId): array
    {
        $query = $this->projectQuery($actor, $organizationId, $projectId, 'executive_document_set');
        if ($query === null) { throw new AuthorizationException; }
        $counts = self::statusCounts($query);
        $waiting = (clone $query)->whereHas('transmittal', static fn (Builder $transmittals): Builder => $transmittals
            ->where('executive_document_transmittals.organization_id', $organizationId)->where('status', 'sent')->whereNull('decision_at'))->count();
        $sets = (clone $query)->where('status', '!=', 'archived')->orderBy('planned_transmittal_date')->orderBy('id')->limit(20)
            ->select(['id', 'organization_id', 'project_id', 'approved_list_id', 'set_number', 'title', 'status', 'planned_transmittal_date'])->get();
        $examples = [];
        foreach ($sets as $set) {
            $row = ['id' => (int) $set->id, 'number' => $set->set_number, 'title' => $set->title, 'status' => $set->getRawOriginal('status'),
                'planned_date' => $set->planned_transmittal_date?->toDateString(), 'readiness' => null, 'transmittal' => null];
            $transmittal = $set->transmittal()->where('executive_document_transmittals.organization_id', $organizationId)->select(array_map(static fn (string $column): string => 'executive_document_transmittals.'.$column,
                ['id', 'document_set_id', 'status', 'transmitted_at', 'acknowledged_at', 'decision_at']))->first();
            if ($transmittal !== null) {
                $row['transmittal'] = ['status' => $transmittal->status, 'transmitted_at' => $transmittal->transmitted_at?->toIso8601String(),
                    'acknowledged_at' => $transmittal->acknowledged_at?->toIso8601String(), 'decision_at' => $transmittal->decision_at?->toIso8601String()];
            }
            if ($this->bounded($set)) {
                $set->load('documents.versions', 'documents.remarks');
                foreach ($set->documents as $document) { $document->setRelation('workType', null); $document->setRelation('journalEntry', null); }
                $native = $this->workflow->readinessSummary($set);
                $row['readiness'] = array_intersect_key($native, array_flip(['documents_total', 'documents_with_files', 'approved_documents', 'open_remarks',
                    'missing_required_data', 'ready_to_transmit', 'requirements_total', 'requirements_applicable', 'requirements_satisfied']));
                $row['readiness']['blockers'] = array_map(static fn (array $blocker): string => mb_substr((string) ($blocker['message'] ?? ''), 0, 255), array_slice($native['readiness_blockers'], 0, 10));
            }
            $examples[] = $row;
        }
        return ['counts' => $counts, 'total' => array_sum($counts), 'not_transmitted' => array_sum(array_diff_key($counts, array_flip(['transmitted', 'archived']))),
            'waiting_decision' => $waiting, 'examples' => $examples];
    }

    private function bounded(ExecutiveDocumentSet $set): bool
    {
        $documents = $set->documents()->getQuery();
        $versions = ExecutiveDocumentVersion::query()->whereIn('document_id', (clone $documents)->select('executive_documents.id'));
        $remarks = \App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentRemark::query()->whereIn('document_id', (clone $documents)->select('executive_documents.id'));
        $requirements = $set->requirements()->whereNull('superseded_at')->getQuery();
        $queries = [$documents, $versions, $remarks, $requirements, $set->transmittal()->getQuery(), $set->approvedList()->getQuery()];
        $bytes = 0;
        foreach ($queries as $query) {
            $table = $query->getModel()->getTable();
            if ((clone $query)->where($table.'.organization_id', '!=', $set->organization_id)->exists()) { return false; }
            if ((clone $query)->count() > 500) { return false; }
            $bytes += (int) (clone $query)->reorder()->select([])->selectRaw('COALESCE(SUM(octet_length(to_jsonb('.$table.')::text)), 0) AS bytes')->toBase()->value('bytes');
            if ($bytes > 1048576) { return false; }
        }
        if ((clone $documents)->where(static fn (Builder $q): Builder => $q->where('organization_id', '!=', $set->organization_id)->orWhere('project_id', '!=', $set->project_id))->exists()
            || (clone $requirements)->where(static fn (Builder $q): Builder => $q->where('organization_id', '!=', $set->organization_id)->orWhere('project_id', '!=', $set->project_id))->exists()) { return false; }
        $required = (clone $requirements)->get(['evidence']);
        $ids = []; $evidenceCount = 0;
        foreach ($required as $requirement) {
            $items = is_array($requirement->evidence) ? $requirement->evidence : [];
            $evidenceCount += count($items);
            if ($evidenceCount > 500) { return false; }
            foreach ($items as $item) {
                if (! is_array($item)) { return false; }
                if (isset($item['version_id'])) {
                    $id = $item['version_id'];
                    if (! is_int($id) && ! (is_string($id) && ctype_digit($id))) { return false; }
                    if ((int) $id < 1) { return false; }
                    $ids[] = (int) $id;
                }
            }
        }
        $ids = array_values(array_unique($ids));
        if ($ids !== [] && ExecutiveDocumentVersion::query()->whereIn('id', $ids)->whereNotIn('id', (clone $versions)->select('executive_document_versions.id'))->exists()) { return false; }
        return true;
    }

    protected function format(array $data): string
    {
        $lines = [trans_message('ai_assistant_operational.executive_scope', ['project' => self::text($data['project']['name']), 'date' => $data['as_of_date']]),
            trans_message('ai_assistant_operational.executive_counts', ['total' => $data['total'], 'pending' => $data['not_transmitted'],
                'transmitted' => $data['counts']['transmitted'] ?? 0, 'waiting' => $data['waiting_decision']])];
        foreach ($data['examples'] as $row) {
            $lines[] = '- #'.$row['id'].' '.self::text((string) $row['number']).' '.self::text((string) $row['title']).'; '
                .trans_message('executive_documentation.statuses.'.$row['status']);
            $readiness = $row['readiness'];
            if ($readiness === null) { $lines[] = trans_message('ai_assistant_operational.executive_limit'); }
            else {
                $lines[] = trans_message('ai_assistant_operational.executive_readiness', ['documents' => $readiness['documents_total'], 'files' => $readiness['documents_with_files'],
                    'approved' => $readiness['approved_documents'], 'remarks' => $readiness['open_remarks'], 'missing' => $readiness['missing_required_data'],
                    'required' => $readiness['requirements_applicable'], 'satisfied' => $readiness['requirements_satisfied']]);
                foreach ($readiness['blockers'] as $blocker) { $lines[] = trans_message('ai_assistant_operational.executive_blocker', ['message' => self::text($blocker)]); }
                $lines[] = trans_message('ai_assistant_operational.executive_step_'.($row['status'] === 'transmitted' ? 'transmitted'
                    : ($row['status'] === 'draft' && $readiness['ready_to_transmit'] ? 'ready' : 'blocked')));
            }
            if ($row['transmittal'] !== null) {
                $lines[] = trans_message('ai_assistant_operational.executive_transmittal', ['status' => self::text((string) $row['transmittal']['status']),
                    'acknowledged' => $row['transmittal']['acknowledged_at'] ?? trans_message('ai_assistant_operational.not_recorded'),
                    'decision' => $row['transmittal']['decision_at'] ?? trans_message('ai_assistant_operational.not_recorded')]);
            }
        }
        $lines[] = trans_message('ai_assistant_operational.executive_basis');
        $lines[] = trans_message('ai_assistant_operational.examples_basis');
        return implode("\n", $lines);
    }
}
