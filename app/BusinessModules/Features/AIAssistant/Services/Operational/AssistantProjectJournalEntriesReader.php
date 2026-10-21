<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Operational;

use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\AssistantJournalEntriesIntent;
use App\BusinessModules\Features\AIAssistant\Services\AssistantRequestExecutionContext;
use App\Models\Project;
use App\Models\User;
use App\Services\ConstructionJournal\ConstructionJournalAccessService;
use App\Services\ConstructionJournal\JournalEntryQueryService;
use App\Support\Reporting\StableReportingSourceView;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final readonly class AssistantProjectJournalEntriesReader extends AssistantProjectOperationalReader
{
    public const TYPE = 'live_project_journal_entries_projection';

    public function __construct(AssistantDataAccessPolicy $access, private ConstructionJournalAccessService $journals,
        private StableReportingSourceView $stableView, private JournalEntryQueryService $entries)
    {
        parent::__construct($access);
    }

    public static function matches(string $query): bool
    {
        return AssistantJournalEntriesIntent::matches($query);
    }

    public function read(User $actor, int $organizationId, array $arguments): array
    {
        $read = fn (): array => $this->capture(fn (): array => parent::read($actor, $organizationId, $arguments));

        return app()->bound(AssistantRequestExecutionContext::class)
            ? app(AssistantRequestExecutionContext::class)->withOperationBudget($read, 30_000, 'pgsql') : $read();
    }

    private function capture(callable $capture): array
    {
        $outer = DB::connection();
        if ($outer->transactionLevel() === 0) {
            return $this->stableView->capture($capture);
        }
        $name = 'assistant_journal_entries_read';
        if (isset(DB::getConnections()[$name]) || config('database.connections.'.$name) !== null) {
            throw new \LogicException('assistant_journal_read_connection_in_use');
        }
        $searchPath = $outer->selectOne("SELECT current_setting('search_path') AS value")->value;
        $temporary = DB::build([...$outer->getConfig(), 'name' => $name]);
        try {
            $read = fn (): array => DB::usingConnection($name, function () use ($capture, $searchPath): array {
                DB::selectOne("SELECT set_config('search_path', ?, false)", [$searchPath]);

                return $this->stableView->capture($capture);
            });

            return $temporary instanceof Connection && app()->bound(AssistantRequestExecutionContext::class)
                ? app(AssistantRequestExecutionContext::class)->withTemporaryDatabaseConnection($temporary, $read) : $read();
        } finally {
            DB::purge($name);
        }
    }

    protected function domain(): string
    {
        return 'core_journals';
    }

    protected function validArguments(array $arguments): bool
    {
        if (array_diff(array_keys($arguments), ['project_id', 'journal_name', 'journal_number', 'offset']) !== []
            || ! is_int($arguments['project_id'] ?? null) || $arguments['project_id'] < 1
            || ! is_int($arguments['offset'] ?? 0) || ($arguments['offset'] ?? 0) < 0 || ($arguments['offset'] ?? 0) > 9999) {
            return false;
        }
        foreach (['journal_name', 'journal_number'] as $key) {
            if (isset($arguments[$key]) && (! is_string($arguments[$key]) || trim($arguments[$key]) === '' || mb_strlen($arguments[$key]) > 255)) {
                return false;
            }
        }

        return true;
    }

    protected function collect(User $actor, int $organizationId, int $projectId): array
    {
        return $this->collectForArguments($actor, $organizationId, ['project_id' => $projectId]);
    }

    protected function collectForArguments(User $actor, int $organizationId, array $arguments): array
    {
        $projectId = $arguments['project_id'];
        $project = $this->access->entityQuery($actor, $organizationId, 'project')?->whereKey($projectId)->first(['id', 'organization_id', 'name']);
        if (! $project instanceof Project || ! $this->journals->canAccessProject($actor, $project)
            || ! $this->access->canCurrentProjectPermission($actor, $organizationId, $projectId, 'construction-journal.view')) {
            throw new AuthorizationException;
        }
        if (! isset($arguments['journal_name']) && ! isset($arguments['journal_number'])) {
            return ['journal_unresolved' => true];
        }
        $journals = $project->journals()->whereIn('performing_organization_id', $this->journals->visiblePerformerIds($actor, $project));
        foreach (['journal_name' => 'name', 'journal_number' => 'journal_number'] as $argument => $column) {
            if (isset($arguments[$argument])) {
                $journals->whereRaw('LOWER(REGEXP_REPLACE(BTRIM('.$column."), '\\s+', ' ', 'g')) = LOWER(REGEXP_REPLACE(BTRIM(?), '\\s+', ' ', 'g'))", [$arguments[$argument]]);
            }
        }
        $matches = $journals->orderBy('id')->limit(2)->get(['id', 'organization_id', 'performing_organization_id', 'project_id', 'name', 'journal_number']);
        if ($matches->count() !== 1) {
            return ['journal_unresolved' => true];
        }
        $journal = $matches->first();
        Gate::forUser($actor)->authorize('view', $journal);
        $entries = $this->entries->query($journal, [], $actor);
        $total = (clone $entries)->count();
        $offset = $arguments['offset'] ?? 0;
        $rows = $entries->orderBy('entry_date')->orderBy('id')->offset($offset)->limit(50)
            ->select(['id', 'entry_number', 'entry_date', 'status'])
            ->selectRaw('LEFT(work_description, 4001) AS work_description')->get()
            ->map(static fn ($row): array => ['id' => (int) $row->id, 'number' => (int) $row->entry_number,
                'date' => substr((string) $row->getRawOriginal('entry_date'), 0, 10), 'status' => (string) $row->getRawOriginal('status'),
                'description' => mb_substr((string) $row->work_description, 0, 4000),
                'description_truncated' => mb_strlen((string) $row->work_description) > 4000])->all();

        return ['journal' => ['id' => (int) $journal->id, 'name' => (string) $journal->name, 'number' => (string) $journal->journal_number],
            'entry_total' => $total, 'offset' => $offset, 'entries' => $rows, 'has_more' => $offset + count($rows) < $total];
    }

    public function verifiedAnswer(array $result, User $actor, int $organizationId): ?array
    {
        $answer = parent::verifiedAnswer($result, $actor, $organizationId);
        if ($answer !== null && ($result['data']['journal_unresolved'] ?? false)) {
            $answer['needs_clarification'] = true;
        }

        return $answer;
    }

    protected function format(array $data): string
    {
        if ($data['journal_unresolved'] ?? false) {
            return trans_message('ai_assistant_journal_entries.clarification', ['project' => self::text($data['project']['name'])]);
        }
        $journal = $data['journal'];
        $lines = [trans_message('ai_assistant_journal_entries.scope', ['project' => self::text($data['project']['name']),
            'number' => self::text($journal['number']), 'name' => self::text($journal['name']), 'date' => $data['as_of_date']]),
            trans_message('ai_assistant_journal_entries.window', ['total' => $data['entry_total'], 'returned' => count($data['entries']), 'start' => $data['offset'] + 1])];
        foreach ($data['entries'] as $entry) {
            $status = in_array($entry['status'], ['draft', 'submitted', 'approved', 'rejected'], true) ? $entry['status'] : 'unknown_status';
            $lines[] = trans_message('ai_assistant_journal_entries.entry', ['number' => $entry['number'], 'date' => $entry['date'],
                'status' => trans_message('ai_assistant_journal_entries.'.$status)]);
            $lines[] = str_replace(['*', '_', '[', ']', '`'], ['\\*', '\\_', '\\[', '\\]', '\\`'],
                htmlspecialchars(preg_replace('/[\p{Cc}\p{Cf}]/u', ' ', $entry['description']) ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
            if ($entry['description_truncated']) {
                $lines[] = trans_message('ai_assistant_journal_entries.shortened');
            }
            $lines[] = '['.trans_message('ai_assistant_journal_entries.open_entry').'](/journals/'.$journal['id'].'/entries/'.$entry['id'].')';
        }
        $lines[] = $data['has_more'] ? trans_message('ai_assistant_journal_entries.more', ['next' => $data['offset'] + count($data['entries']) + 1,
            'number' => self::text($journal['number']), 'name' => self::text($journal['name']), 'project' => self::text($data['project']['name'])])
            : trans_message('ai_assistant_journal_entries.'.($data['entry_total'] === 0 ? 'empty' : ($data['offset'] === 0 ? 'complete' : 'window')),
                ['total' => $data['entry_total'], 'returned' => count($data['entries']), 'start' => $data['offset'] + 1]);

        return implode("\n", $lines);
    }
}
