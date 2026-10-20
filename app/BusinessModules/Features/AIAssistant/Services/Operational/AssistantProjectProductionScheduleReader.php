<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Operational;

use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\AssistantProductionSchedulePeriod;
use App\BusinessModules\Features\AIAssistant\Services\AssistantRequestExecutionContext;
use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantCustomReportSource;
use App\Models\User;
use App\Support\Reporting\StableReportingSourceView;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

final readonly class AssistantProjectProductionScheduleReader extends AssistantProjectOperationalReader
{
    public const TYPE = 'live_project_production_schedule_projection';

    private const COMPLETED_WORK_PARTICIPLE = '(?:выполнен[аоы]?|принят[аоы]?)';

    private const COMPLETED_WORK_FORM = '(?:'.self::COMPLETED_WORK_PARTICIPLE.'|выполненн(?:ая|ое|ый|ые|ую|ого|ой|ому|ым|ом|ых|ыми)|выполнил[аои]?|принят(?:ая|ое|ый|ые|ую|ого|ой|ому|ым|ом|ых|ыми)|принял[аои]?)';

    private const FINANCIAL_EXPENSE = '(?<![\pL\pN])(?:расход(?:а|у|ом|е|ы|ов|ам|ами|ах)?|расходн[а-яё]*|(?:из)?расход(?:ова|у)[а-яё]*)(?![\pL\pN])';

    public function __construct(AssistantDataAccessPolicy $access, private AssistantCustomReportSource $reports, private StableReportingSourceView $stableView)
    {
        parent::__construct($access);
    }

    public static function hasIntent(string $query): bool
    {
        $query = \App\BusinessModules\Features\AIAssistant\Services\AssistantNamedProjectReadScope::semanticQuery($query);
        $query = preg_replace('/\b(?:буд(?:у|ешь|ет|ем|ете|ут)|долж(?:ен|на|но|ны)|нужно|надо|следует|необходимо|предстоит)(?:\s+(?:был(?:а|о|и)?|буд(?:у|ешь|ет|ем|ете|ут)))?(?:\s+(?:быть|полностью|уже|своевременно|фактически|окончательно|обязательно|сегодня|завтра)){0,3}\s+'.self::COMPLETED_WORK_PARTICIPLE.'\b/iu', ' ', $query) ?? $query;

        return preg_match('/\b(?:'.self::COMPLETED_WORK_FORM.'\s+работ[а-яё]*|работ[а-яё]*.*\b'.self::COMPLETED_WORK_FORM.')\b/iu', $query) === 1
            && preg_match('/график|планов[а-яё]*\s+срок/iu', $query) === 1;
    }

    public static function matches(string $query): bool
    {
        return self::hasIntent($query)
            && ! self::hasExcludedTopic(\App\BusinessModules\Features\AIAssistant\Services\AssistantNamedProjectReadScope::semanticQuery($query));
    }

    public static function hasExcludedTopic(string $query): bool
    {
        return preg_match('/сформир|выгруз|excel|xlsx|csv|pdf|создай|удали|измени|закрой|отправь|подпиши|назначь|назначит|перенес|перенос|сдвин|обнови|добавь|добавит|поменяй|исправь|заплат|договор|оплат|стоим|смет|бюджет|наряд|потрач|'.self::FINANCIAL_EXPENSE.'|затрат|прибыл|выручк|рентаб|марж|финанс/iu', $query) === 1;
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
        $name = 'assistant_production_schedule_read';
        if (isset(DB::getConnections()[$name]) || config('database.connections.'.$name) !== null) {
            throw new \LogicException('assistant_production_read_connection_in_use');
        }
        $searchPath = $outer->selectOne("SELECT current_setting('search_path') AS value")->value;
        $temporary = DB::build([...$outer->getConfig(), 'name' => $name]);
        try {
            $read = fn (): array => DB::usingConnection($name, function () use ($capture, $searchPath): array {
                DB::selectOne("SELECT set_config('search_path', ?, false)", [$searchPath]);

                return $this->stableView->capture(function () use ($capture): array {
                    DB::statement('SET TRANSACTION READ ONLY');

                    return $capture();
                });
            });
            if ($temporary instanceof Connection && app()->bound(AssistantRequestExecutionContext::class)) {
                return app(AssistantRequestExecutionContext::class)->withTemporaryDatabaseConnection($temporary, $read);
            }

            return $read();
        } finally {
            DB::purge($name);
        }
    }

    protected function domain(): string
    {
        return 'projects';
    }

    protected function validArguments(array $arguments): bool
    {
        return AssistantProductionSchedulePeriod::validArguments($arguments);
    }

    protected function readTime(): \DateTimeImmutable
    {
        return parent::readTime()->setTimezone(new \DateTimeZone('Europe/Moscow'));
    }

    protected function collectAt(User $actor, int $organizationId, array $arguments, \DateTimeImmutable $asOf): array
    {
        if (($arguments['scope_clarification'] ?? null) === 'location') {
            return ['location_unresolved' => true];
        }
        if (array_key_exists('period_start', $arguments) && $arguments['period_start'] === null && $arguments['period_end'] === null) {
            return ['period_unresolved' => true];
        }

        return $this->collect($actor, $organizationId, $arguments['project_id'], $arguments, $asOf);
    }

    public function verifiedAnswer(array $result, User $actor, int $organizationId): ?array
    {
        $answer = parent::verifiedAnswer($result, $actor, $organizationId);
        $arguments = $result['source_refs'][0]['_read_arguments'] ?? [];
        if ($answer !== null && ($arguments['scope_clarification'] ?? null) !== 'location'
            && array_key_exists('period_start', $arguments) && $arguments['period_start'] === null && $arguments['period_end'] === null) {
            $answer['needs_clarification'] = true;
        }

        return $answer;
    }

    protected function collect(User $actor, int $organizationId, int $projectId, array $arguments = [], ?\DateTimeImmutable $asOf = null): array
    {
        $asOf ??= $this->readTime();
        $works = $this->reports->resolve($actor, $organizationId, 'works', 'completed_work', $projectId)['query']->confirmed()
            ->where('completed_works.completion_date', '<=', $asOf->format('Y-m-d'));
        if (isset($arguments['period_start'])) {
            $works->where('completed_works.completion_date', '>=', $arguments['period_start']);
        }
        if (isset($arguments['period_end'])) {
            $works->where('completed_works.completion_date', '<=', $arguments['period_end']);
        }
        $tasks = $this->reports->resolve($actor, $organizationId, 'schedule', 'schedule_task', $projectId, $asOf)['query'];
        $schedules = $this->projectQuery($actor, $organizationId, $projectId, 'schedule');
        $project = $this->projectQuery($actor, $organizationId, $projectId, 'project')?->whereKey($projectId)->first(['id', 'end_date']);
        if ($schedules === null || $project === null) {
            throw new AuthorizationException;
        }
        $active = (clone $schedules)->where('project_schedules.status', 'active');
        $activeTasks = (clone $tasks)->whereIn('schedule_id', (clone $active)->select('project_schedules.id'))
            ->whereNotIn('task_type', ['container', 'summary']);
        $open = (clone $activeTasks)->whereNotIn('status', ['completed', 'cancelled']);
        $scheduleCounts = self::statusCounts($schedules);
        $taskCounts = self::statusCounts($tasks);
        $volumeRows = (clone $works)->reorder()->select('unit')->selectRaw('COUNT(*) AS records')
            ->selectRaw("CASE WHEN unit IS NULL OR BTRIM(unit) = '' THEN NULL ELSE SUM(quantity) END AS accepted_quantity")
            ->groupBy('unit')->orderBy('unit')->limit(21)->get();
        $volumes = $volumeRows->take(20)->map(static fn ($row): array => [
            'unit' => $row->getRawOriginal('unit'), 'records' => (int) $row->getAttribute('records'),
            'quantity' => $row->getAttribute('accepted_quantity') === null ? null : (string) $row->getAttribute('accepted_quantity'),
        ])->all();
        $examples = (clone $open)->orderByDesc('is_overdue')->orderBy('planned_end_date')->orderBy('id')->limit(20)
            ->get(['id', 'name', 'status', 'planned_end_date', 'assigned_user_id', 'assigned_user_name', 'is_overdue'])
            ->map(static fn ($row): array => ['id' => (int) $row->id, 'name' => (string) $row->name,
                'status' => (string) $row->getRawOriginal('status'), 'planned_end_on' => self::date($row->getRawOriginal('planned_end_date')),
                'owner' => $row->getRawOriginal('assigned_user_name'),
                'owner_state' => $row->getRawOriginal('assigned_user_id') === null ? 'unassigned'
                    : ($row->getRawOriginal('assigned_user_name') === null ? 'unavailable' : 'known'),
                'is_overdue' => (bool) $row->getRawOriginal('is_overdue'),
            ])->all();

        return ['period_start' => $arguments['period_start'] ?? null, 'period_end' => $arguments['period_end'] ?? null,
            'confirmed_works' => (int) (clone $works)->count(), 'accepted_volumes' => $volumes, 'volume_groups_limited' => $volumeRows->count() > 20,
            'schedule_counts' => $scheduleCounts, 'total_schedules' => array_sum($scheduleCounts), 'active_schedules' => $scheduleCounts['active'] ?? 0,
            'task_counts' => $taskCounts, 'total_tasks' => array_sum($taskCounts), 'active_work_tasks' => (int) (clone $activeTasks)->count(),
            'open_tasks' => (int) (clone $open)->count(), 'overdue_tasks' => (int) (clone $open)->where('is_overdue', true)->count(),
            'unassigned_open_tasks' => (int) (clone $open)->whereNull('assigned_user_id')->count(),
            'project_end_on' => self::date($project->getRawOriginal('end_date')),
            'active_schedule_end_on' => self::date((clone $active)->max('project_schedules.planned_end_date')), 'examples' => $examples];
    }

    protected function format(array $data): string
    {
        if (($data['location_unresolved'] ?? false) === true) {
            return trans_message('ai_assistant_production_schedule.location_clarification');
        }
        if (($data['period_unresolved'] ?? false) === true) {
            return trans_message('ai_assistant_production_schedule.period_clarification');
        }
        $unknownDate = trans_message('ai_assistant_operational.not_recorded');
        $lines = [trans_message('ai_assistant_production_schedule.scope', ['project' => self::text($data['project']['name']), 'date' => $data['as_of_date']]),
            trans_message('ai_assistant_production_schedule.works', ['count' => $data['confirmed_works'], 'date' => $data['as_of_date']])];
        if ($data['period_start'] !== null) {
            array_splice($lines, 1, 0, [trans_message('ai_assistant_production_schedule.period', ['from' => $data['period_start'], 'to' => $data['period_end']])]);
        } elseif ($data['period_end'] !== null) {
            array_splice($lines, 1, 0, [trans_message('ai_assistant_production_schedule.period_from_start', ['to' => $data['period_end']])]);
        }
        foreach ($data['accepted_volumes'] as $volume) {
            $lines[] = $volume['quantity'] === null
                ? trans_message('ai_assistant_production_schedule.unknown_unit', ['count' => $volume['records']])
                : trans_message('ai_assistant_production_schedule.volume', ['quantity' => $volume['quantity'],
                    'unit' => self::text((string) $volume['unit']), 'count' => $volume['records']]);
        }
        if ($data['volume_groups_limited']) {
            $lines[] = trans_message('ai_assistant_production_schedule.volume_groups_limited');
        }
        $lines[] = trans_message('ai_assistant_production_schedule.schedules', ['total' => $data['total_schedules'], 'active' => $data['active_schedules'], 'tasks' => $data['total_tasks']]);
        $lines[] = trans_message('ai_assistant_production_schedule.tasks', ['total' => $data['active_work_tasks'], 'open' => $data['open_tasks'],
            'overdue' => $data['overdue_tasks'], 'unassigned' => $data['unassigned_open_tasks']]);
        $lines[] = trans_message('ai_assistant_production_schedule.deadlines', ['project' => $data['project_end_on'] ?? $unknownDate,
            'schedule' => $data['active_schedule_end_on'] ?? $unknownDate]);
        $lines[] = trans_message('ai_assistant_production_schedule.'.($data['active_schedules'] === 0 ? 'no_active_schedule'
            : ($data['open_tasks'] > 0 ? 'unfinished' : 'no_open_tasks')));
        foreach ($data['examples'] as $row) {
            $owner = $row['owner_state'] === 'unavailable' ? trans_message('ai_assistant_production_schedule.owner_unavailable') : (string) $row['owner'];
            $lines[] = '- #'.$row['id'].' '.self::text($row['name']).'; '.trans_message('ai_assistant_production_schedule.task', [
                'status' => \App\BusinessModules\Features\AIAssistant\Services\AssistantStructuredFactLabels::display('schedule_task', 'status', $row['status']),
                'date' => $row['planned_end_on'] ?? $unknownDate, 'owner' => self::text($owner)]);
        }
        $lines[] = trans_message('ai_assistant_production_schedule.basis', ['shown' => count($data['examples']), 'total' => $data['open_tasks']]);

        return implode("\n", $lines);
    }

    private static function date(mixed $value): ?string
    {
        return $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : (is_string($value) && $value !== '' ? substr($value, 0, 10) : null);
    }
}
