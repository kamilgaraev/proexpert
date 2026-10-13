<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Operational;

use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\ConstructionJournalEntry;
use App\Models\Project;
use App\Models\User;
use App\Services\ConstructionJournal\ConstructionJournalAccessService;
use Illuminate\Auth\Access\AuthorizationException;

final readonly class AssistantProjectJournalReader extends AssistantProjectOperationalReader
{
    public const TYPE = 'live_project_journal_readiness_projection';

    public function __construct(AssistantDataAccessPolicy $access, private ConstructionJournalAccessService $journals, private AuthorizationService $authorization)
    {
        parent::__construct($access);
    }

    public static function matches(string $query): bool
    {
        return preg_match('/журнал[а-яё]*.*(?:работ|запис|подпис|соглас|сдач)|запис[а-яё]*.*журнал/iu', $query) === 1
            && preg_match('/создай|измени|изменен|rfi|удали|подпиши|согласуй|отправь|оплат|оплач|плат[её]ж|сч[её]т|бюджет|смет|стоим|выручк|затрат|договор|при[её]мк|нарушен|охран[а-яё]*\s+труд|табел|техник|комплект|критическ/iu', $query) !== 1;
    }

    protected function domain(): string { return 'core_journals'; }

    protected function collect(User $actor, int $organizationId, int $projectId): array
    {
        $project = $this->access->entityQuery($actor, $organizationId, 'project')?->whereKey($projectId)->select(['id', 'organization_id', 'name'])->first();
        if (! $project instanceof Project || ! $this->journals->canAccessProject($actor, $project)
            || ! $this->authorization->forCurrentChecks(true)->canCurrent($actor, 'construction-journal.view',
                ['organization_id' => $organizationId, 'project_id' => $projectId, 'strict_project_scope' => true])) { throw new AuthorizationException; }
        $journals = $project->journals()->whereIn('performing_organization_id', $this->journals->visiblePerformerIds($actor, $project));
        $journalCounts = self::statusCounts($journals->getQuery());
        $entries = ConstructionJournalEntry::query()->whereIn('journal_id', (clone $journals)->select('construction_journals.id'));
        $entryCounts = self::statusCounts($entries);
        $examples = [];
        $names = [];
        foreach (['draft', 'submitted', 'rejected'] as $status) {
            $examples[$status] = (clone $entries)->where('status', $status)->orderBy('entry_date')->orderBy('id')->limit(3)
                ->select(['id', 'journal_id', 'entry_number', 'entry_date', 'work_description', 'status', 'created_by_user_id', 'rejection_reason'])
                ->with('createdBy:id,name')->get()
                ->map(static function ($row) use (&$names): array {
                    $data = $row->getAttributes();
                    if ($row->createdBy instanceof User) { $names[(int) $row->createdBy->id] = (string) $row->createdBy->name; }
                    $data['work_description'] = mb_substr((string) ($data['work_description'] ?? ''), 0, 255);
                    $data['rejection_reason'] = mb_substr((string) ($data['rejection_reason'] ?? ''), 0, 255);
                    return $data;
                })->all();
        }
        return ['journal_counts' => $journalCounts, 'journal_total' => array_sum($journalCounts), 'entry_counts' => $entryCounts,
            'entry_total' => array_sum($entryCounts), 'examples' => $examples, 'creator_names' => $names];
    }

    protected function format(array $data): string
    {
        $lines = [trans_message('ai_assistant_operational.journal_scope', ['project' => self::text($data['project']['name']), 'date' => $data['as_of_date']]),
            trans_message('ai_assistant_operational.journal_total', ['journals' => $data['journal_total'], 'entries' => $data['entry_total']])];
        foreach (['draft', 'submitted', 'approved', 'rejected'] as $status) {
            $lines[] = trans_message('ai_assistant_operational.journal_'.$status, ['count' => $data['entry_counts'][$status] ?? 0]);
        }
        foreach ($data['examples'] as $status => $examples) {
            foreach ($examples as $entry) {
                $owner = $data['creator_names'][$entry['created_by_user_id']] ?? trans_message('ai_assistant_operational.owner_unknown');
                $lines[] = '- #'.$entry['id'].' '.trans_message('ai_assistant_operational.journal_entry', ['journal' => $entry['journal_id'], 'number' => $entry['entry_number'],
                    'date' => substr((string) $entry['entry_date'], 0, 10), 'owner' => self::text($owner), 'description' => self::text($entry['work_description'])]);
                if ($entry['rejection_reason'] !== '') { $lines[] = trans_message('ai_assistant_operational.rejection', ['reason' => self::text($entry['rejection_reason'])]); }
                $lines[] = trans_message('ai_assistant_operational.journal_step_'.$status);
            }
        }
        $lines[] = trans_message('ai_assistant_operational.journal_signature_basis');
        $lines[] = trans_message('ai_assistant_operational.examples_basis');
        return implode("\n", $lines);
    }
}
