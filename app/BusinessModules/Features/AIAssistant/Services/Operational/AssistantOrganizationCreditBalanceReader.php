<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Operational;

use App\Models\Credits\AICreditLot;
use App\BusinessModules\Features\BudgetEstimates\Services\Finance\FinanceDecimal;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final readonly class AssistantOrganizationCreditBalanceReader extends AssistantOrganizationOperationalReader
{
    public const TYPE = 'live_organization_ai_credit_balance_projection';

    public static function hasIntent(string $query): bool
    {
        return preg_match('/(?:единиц|кредит[а-яё]*).*(?:мост|помощник|ии\b)|(?:помощник|ии\b).*(?:баланс|остаток|единиц|кредит)/iu', $query) === 1;
    }

    public static function matches(string $query): bool
    {
        $facts = preg_replace('/где(?:\s+\pL+){0,2}\s+(?:смотреть|посмотреть)\s+расход[а-яё]*/iu', '', $query) ?? $query;
        return self::hasIntent($query) && preg_match('/сколько|остаток|баланс|доступно/iu', $query) === 1
            && preg_match('/пополни|купи|спиши|переведи|создай|измени|расход|затрат|потрач|прибыл|выручк|рентабел|марж|сч[её]т|договор|смет|бюджет|зарплат|проект|за\s+(?:месяц|сентябр|октябр)/iu', $facts) !== 1;
    }

    protected function collect(User $actor, int $organizationId): array
    {
        $at = now();
        $wallet = DB::table('ai_credit_wallets')->where('organization_id', $organizationId)->first(['balance_minor', 'reserved_minor']);
        $lots = DB::table('ai_credit_lots')->where('organization_id', $organizationId)->where('remaining_minor', '>', 0);
        $expired = (int) (clone $lots)->whereNotNull('expires_at')->where('expires_at', '<=', $at)->sum('remaining_minor');
        $live = (clone $lots)->where(static fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', $at));
        $total = (int) ($wallet->balance_minor ?? 0) - $expired;
        $reserved = (int) ($wallet->reserved_minor ?? 0);
        $held = (int) (clone $live)->where('source', 'conversion')->where('metadata->'.AICreditLot::LEGACY_REFUND_HOLD, true)->sum('remaining_minor');
        $valid = $total >= 0 && ($wallet !== null || ! (clone $lots)->exists());

        return ['available_minor' => $valid ? max(0, $total - $reserved - $held) : null,
            'total_minor' => $valid ? $total : null, 'reserved_minor' => $reserved, 'reconciliation_held_minor' => $held,
            'included_minor' => (int) (clone $live)->where('source', 'subscription')->sum('remaining_minor'),
            'purchased_minor' => (int) (clone $live)->whereIn('source', ['purchase', 'conversion', 'refund'])->sum('remaining_minor'),
            'base_period_expires_at' => (clone $live)->where('source', 'subscription')->min('expires_at'),
            'unit_minor' => (int) config('ai-assistant-credits.unit_minor', 100)];
    }

    protected function format(array $data): string
    {
        if ($data['available_minor'] === null || $data['unit_minor'] <= 0) { return trans_message('ai_assistant_operational.credit_balance_unavailable'); }
        $units = static fn (int $minor): string => rtrim(rtrim(FinanceDecimal::divide((string) $minor, (string) $data['unit_minor'], 2), '0'), '.');
        $lines = [trans_message('ai_assistant_operational.credit_balance', ['available' => $units($data['available_minor']), 'total' => $units($data['total_minor']),
            'reserved' => $units($data['reserved_minor']), 'held' => $units($data['reconciliation_held_minor'])]),
            trans_message('ai_assistant_operational.credit_free_lots', ['included' => $units($data['included_minor']), 'purchased' => $units($data['purchased_minor'])])];
        if ($data['base_period_expires_at'] !== null) { $lines[] = trans_message('ai_assistant_operational.credit_expiry', ['date' => $data['base_period_expires_at']]); }
        $lines[] = trans_message('ai_assistant_operational.credit_balance_basis');

        return implode("\n", $lines);
    }
}
