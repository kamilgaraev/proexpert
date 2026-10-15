<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Operational;

use App\Models\ContractPerformanceAct;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

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
        $examples = (clone $query)->where('status', ContractPerformanceAct::STATUS_PENDING_APPROVAL)
            ->orderBy('submitted_at')->orderBy('id')->limit(20)
            ->select(['id', 'contract_id', 'act_document_number', 'act_date', 'period_start', 'period_end', 'submitted_at'])
            ->get()->map(static fn ($row): array => ['id' => (int) $row->id, 'contract_id' => (int) $row->contract_id,
                'number' => $row->act_document_number, 'date' => $row->act_date?->toDateString(),
                'period_start' => $row->period_start?->toDateString(), 'period_end' => $row->period_end?->toDateString(),
                'submitted_at' => $row->submitted_at?->toIso8601String()])->all();
        $amounts = [];
        $table = $query->getModel()->getTable();
        foreach (['pending' => [ContractPerformanceAct::STATUS_PENDING_APPROVAL],
            'approved' => [ContractPerformanceAct::STATUS_APPROVED, ContractPerformanceAct::STATUS_SIGNED]] as $group => $statuses) {
            $amounts[$group] = (clone $query)->whereIn($table.'.status', $statuses)->reorder()->select($table.'.currency')
                ->selectRaw('SUM('.$table.'.amount) AS total_amount')->groupBy($table.'.currency')->orderBy($table.'.currency')->get()
                ->map(static fn ($row): array => ['currency' => (string) ($row->getRawOriginal('currency') ?? ''),
                    'amount' => (string) $row->getAttribute('total_amount')])->all();
        }
        return ['counts' => $counts, 'total' => array_sum($counts), 'pending' => $counts[ContractPerformanceAct::STATUS_PENDING_APPROVAL] ?? 0,
            'amounts_by_status' => $amounts, 'examples' => $examples];
    }

    protected function format(array $data): string
    {
        $lines = [trans_message('ai_assistant_operational.act_scope', ['project' => self::text($data['project']['name']), 'date' => $data['as_of_date']]),
            trans_message('ai_assistant_operational.act_counts', ['total' => $data['total'], 'pending' => $data['pending'],
                'draft' => $data['counts']['draft'] ?? 0, 'approved' => ($data['counts']['approved'] ?? 0) + ($data['counts']['signed'] ?? 0),
                'rejected' => $data['counts']['rejected'] ?? 0])];
        foreach ($data['amounts_by_status'] as $group => $amounts) {
            $lines[] = trans_message('ai_assistant_operational.act_amount_'.$group, ['amounts' => $amounts === []
                ? trans_message('ai_assistant_operational.act_amount_empty')
                : implode('; ', array_map(static fn (array $row): string => $row['amount'].' '.self::text($row['currency'] === ''
                    ? trans_message('ai_assistant_operational.act_currency_unknown') : $row['currency']), $amounts))]);
        }
        $lines[] = trans_message('ai_assistant_operational.act_amount_basis');
        foreach ($data['examples'] as $row) {
            $lines[] = '- #'.$row['id'].' '.self::text((string) $row['number']).'; '.trans_message('ai_assistant_operational.act_entry', [
                'contract' => $row['contract_id'], 'date' => $row['date'] ?? trans_message('ai_assistant_operational.not_recorded'),
                'from' => $row['period_start'] ?? trans_message('ai_assistant_operational.not_recorded'),
                'to' => $row['period_end'] ?? trans_message('ai_assistant_operational.not_recorded')]);
        }
        $lines[] = trans_message('ai_assistant_operational.act_step');
        $lines[] = trans_message('ai_assistant_operational.act_basis');
        $lines[] = trans_message('ai_assistant_operational.examples_basis');
        return implode("\n", $lines);
    }
}
