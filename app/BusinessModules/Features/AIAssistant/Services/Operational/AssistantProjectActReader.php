<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Operational;

use App\Models\ContractPerformanceAct;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;

final readonly class AssistantProjectActReader extends AssistantProjectOperationalReader
{
    public const TYPE = 'live_project_act_readiness_projection';
    public const INTENT_PATTERN = '/(?<!\pL)акт(?:ы|а|у|ом|е|ов|ам|ами|ах)?(?!\pL)[\s\S]*(?:согласован|решени|одобрен)|(?:согласован|одобрен)[\s\S]*(?<!\pL)акт(?:ы|а|у|ом|е|ов|ам|ами|ах)?(?!\pL)/iu';

    public static function matches(string $query): bool
    {
        $query = self::intentQuery($query);
        return preg_match(self::INTENT_PATTERN, $query) === 1
            && preg_match('/создай|удали|отправь|согласуй|одобри|подпиши|измени|оплат|исполнительн|наряд|журнал|бюджет|расход|смет|(?:какие|покажи|список|перечисли)\s+(?:действующ[а-яё]*\s+|активн[а-яё]*\s+)?договор|услови[а-яё]*\s+договор/iu', $query) !== 1;
    }

    public static function intentQuery(string $query): string
    {
        if (preg_match(self::INTENT_PATTERN, $query) !== 1) { return $query; }
        return preg_replace('/не\s+смеш(?:ать|ивать)\s+(?:их\s+)?с\s+(?:оплатой\s+счетов|платежами)(?!\pL)/iu', '', $query) ?? $query;
    }

    protected function domain(): string { return 'contracts'; }

    protected function collect(User $actor, int $organizationId, int $projectId): array
    {
        $query = $this->projectQuery($actor, $organizationId, $projectId, 'performance_act');
        if ($query === null) { throw new AuthorizationException; }
        $counts = self::statusCounts($query);
        $examples = $this->examples($query, [ContractPerformanceAct::STATUS_PENDING_APPROVAL], 'submitted_at');
        $approvedExamples = $this->examples($query, [ContractPerformanceAct::STATUS_APPROVED, ContractPerformanceAct::STATUS_SIGNED], 'act_date');
        $amounts = []; $unknownCurrencies = [];
        $amountsAvailable = $this->access->canCurrentPermission($actor, $organizationId, 'finance.view');
        $table = $query->getModel()->getTable();
        foreach ($amountsAvailable ? ['pending' => [ContractPerformanceAct::STATUS_PENDING_APPROVAL],
            'approved' => [ContractPerformanceAct::STATUS_APPROVED, ContractPerformanceAct::STATUS_SIGNED]] : [] as $group => $statuses) {
            $groupQuery = (clone $query)->whereIn($table.'.status', $statuses);
            $unknownCurrencies[$group] = (clone $groupQuery)->whereRaw("COALESCE(".$table.".currency, '') !~ ?", ['^[A-Z]{3}$'])->count();
            $amounts[$group] = $groupQuery->whereRaw($table.'.currency ~ ?', ['^[A-Z]{3}$'])->reorder()->select($table.'.currency')
                ->selectRaw('SUM('.$table.'.amount) AS total_amount')->groupBy($table.'.currency')->orderBy($table.'.currency')->get()
                ->map(static fn ($row): array => ['currency' => (string) ($row->getRawOriginal('currency') ?? ''),
                    'amount' => (string) $row->getAttribute('total_amount')])->all();
        }
        return ['counts' => $counts, 'total' => array_sum($counts), 'pending' => $counts[ContractPerformanceAct::STATUS_PENDING_APPROVAL] ?? 0,
            'amounts_by_status' => $amountsAvailable ? $amounts : null, 'amounts_unknown_currency' => $amountsAvailable ? $unknownCurrencies : null,
            'examples' => $examples, 'approved_examples' => $approvedExamples];
    }

    private function examples(Builder $query, array $statuses, string $order): array
    {
        return (clone $query)->whereIn('status', $statuses)->orderBy($order)->orderBy('id')->limit(20)
            ->select(['id', 'contract_id', 'act_document_number', 'act_date', 'period_start', 'period_end', 'submitted_at'])
            ->get()->map(static fn ($row): array => ['id' => (int) $row->id, 'contract_id' => (int) $row->contract_id,
                'number' => $row->act_document_number, 'date' => $row->act_date?->toDateString(),
                'period_start' => $row->period_start?->toDateString(), 'period_end' => $row->period_end?->toDateString(),
                'submitted_at' => $row->submitted_at?->toIso8601String()])->all();
    }

    protected function format(array $data): string
    {
        $lines = [trans_message('ai_assistant_operational.act_scope', ['project' => self::text($data['project']['name']), 'date' => $data['as_of_date']]),
            trans_message('ai_assistant_operational.act_counts', ['total' => $data['total'], 'pending' => $data['pending'],
                'draft' => $data['counts']['draft'] ?? 0, 'approved' => ($data['counts']['approved'] ?? 0) + ($data['counts']['signed'] ?? 0),
                'rejected' => $data['counts']['rejected'] ?? 0])];
        foreach ($data['amounts_by_status'] ?? [] as $group => $amounts) {
            $unknown = $data['amounts_unknown_currency'][$group];
            $lines[] = trans_message('ai_assistant_operational.act_amount_'.$group, ['amounts' => $amounts === []
                ? trans_message($unknown > 0 ? 'ai_assistant_operational.act_amount_unconfirmed' : 'ai_assistant_operational.act_amount_empty')
                : implode('; ', array_map(static fn (array $row): string => $row['amount'].' '.self::text($row['currency']), $amounts))]);
            if ($unknown > 0) { $lines[] = trans_message('ai_assistant_operational.act_amount_unknown_'.$group, ['count' => $unknown]); }
        }
        $lines[] = trans_message($data['amounts_by_status'] === null ? 'ai_assistant_operational.act_amount_unavailable' : 'ai_assistant_operational.act_amount_basis');
        foreach (['examples' => $data['pending'], 'approved_examples' => ($data['counts']['approved'] ?? 0) + ($data['counts']['signed'] ?? 0)] as $key => $total) {
            if ($data[$key] === []) { continue; }
            $lines[] = trans_message('ai_assistant_operational.act_'.$key);
            foreach ($data[$key] as $row) {
                $lines[] = '- #'.$row['id'].' '.self::text((string) $row['number']).'; '.trans_message('ai_assistant_operational.act_entry', [
                    'contract' => $row['contract_id'], 'date' => $row['date'] ?? trans_message('ai_assistant_operational.not_recorded'),
                    'from' => $row['period_start'] ?? trans_message('ai_assistant_operational.not_recorded'),
                    'to' => $row['period_end'] ?? trans_message('ai_assistant_operational.not_recorded')]);
            }
            if (count($data[$key]) < $total) { $lines[] = trans_message('ai_assistant_operational.act_window', ['shown' => count($data[$key]), 'total' => $total]); }
        }
        $lines[] = trans_message('ai_assistant_operational.act_step');
        $lines[] = trans_message('ai_assistant_operational.act_basis');
        $lines[] = trans_message('ai_assistant_operational.examples_basis');
        return implode("\n", $lines);
    }
}
