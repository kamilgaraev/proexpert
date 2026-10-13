<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Operational;

use App\BusinessModules\Features\CommercialProposals\Enums\CommercialProposalStatus;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

final readonly class AssistantProjectProposalReader extends AssistantProjectOperationalReader
{
    public const TYPE = 'live_project_proposal_readiness_projection';

    public static function matches(string $query): bool
    {
        return preg_match('/коммерческ[а-яё]*\s+предложен/iu', $query) === 1
            && preg_match('/создай|удали|отправь|согласуй|измени|оплат|бюджет|смет|журнал|охран|наряд|исполнительн|проектн[а-яё]*\s+комплект/iu', $query) !== 1;
    }

    protected function domain(): string { return 'commercial_processes'; }

    protected function collect(User $actor, int $organizationId, int $projectId): array
    {
        $query = $this->projectQuery($actor, $organizationId, $projectId, 'commercial_proposal');
        if ($query === null) { throw new AuthorizationException; }
        $counts = self::statusCounts($query);
        $pending = ['draft', 'internal_review', 'approved', 'sent', 'customer_review'];
        $examples = (clone $query)->whereIn('status', $pending)->orderBy('valid_until')->orderBy('id')->limit(20)
            ->select(['id', 'number', 'title', 'status', 'valid_until', 'sent_at', 'customer_decision_at'])->get()
            ->map(static fn ($row): array => ['id' => (int) $row->id, 'number' => $row->number, 'title' => $row->title,
                'status' => $row->getRawOriginal('status'), 'valid_until' => $row->valid_until?->toDateString(),
                'sent_at' => $row->sent_at?->toIso8601String(), 'customer_decision_at' => $row->customer_decision_at?->toIso8601String()])->all();
        return ['counts' => $counts, 'total' => array_sum($counts), 'pending' => array_sum(array_intersect_key($counts, array_flip($pending))),
            'expired_pending' => (clone $query)->whereIn('status', $pending)->whereDate('valid_until', '<', today())->count(), 'examples' => $examples];
    }

    protected function format(array $data): string
    {
        $lines = [trans_message('ai_assistant_operational.proposal_scope', ['project' => self::text($data['project']['name']), 'date' => $data['as_of_date']]),
            trans_message('ai_assistant_operational.proposal_counts', ['total' => $data['total'], 'draft' => $data['counts']['draft'] ?? 0,
                'internal' => $data['counts']['internal_review'] ?? 0, 'approved' => $data['counts']['approved'] ?? 0,
                'customer' => ($data['counts']['sent'] ?? 0) + ($data['counts']['customer_review'] ?? 0), 'expired' => $data['expired_pending']])];
        foreach ($data['examples'] as $row) {
            $lines[] = '- #'.$row['id'].' '.self::text((string) $row['number']).' '.self::text((string) $row['title']).'; '
                .trans_message(CommercialProposalStatus::from($row['status'])->labelKey()).'; '
                .trans_message('ai_assistant_operational.proposal_valid_until', ['date' => $row['valid_until'] ?? trans_message('ai_assistant_operational.not_recorded')]);
            $lines[] = trans_message('ai_assistant_operational.proposal_step_'.$row['status']);
        }
        $lines[] = trans_message('ai_assistant_operational.proposal_basis');
        $lines[] = trans_message('ai_assistant_operational.examples_basis');
        return implode("\n", $lines);
    }
}
