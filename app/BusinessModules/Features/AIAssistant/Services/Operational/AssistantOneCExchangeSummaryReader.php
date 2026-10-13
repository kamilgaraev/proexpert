<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Operational;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

final readonly class AssistantOneCExchangeSummaryReader extends AssistantOrganizationOperationalReader
{
    public const TYPE = 'live_organization_one_c_exchange_summary_projection';

    public static function hasIntent(string $query): bool
    {
        return preg_match('/(?:1с|1c|один\s*эс)/iu', $query) === 1
            && preg_match('/обмен|синхронизац/iu', $query) === 1;
    }

    public static function matches(string $query): bool
    {
        return self::hasIntent($query)
            && preg_match('/запусти|повтори|настрой|измени|создай|удали|отправь|выгрузи|загрузи|конфликт|парол|токен|сотрудник|сч[её]т|оплат|прибыл|остатк|бюджет/iu', $query) !== 1;
    }

    protected function collect(User $actor, int $organizationId): array
    {
        if (! $this->access->canReadDomain($actor, $organizationId, 'core_integration')) { throw new AuthorizationException; }
        $query = $this->access->entityQuery($actor, $organizationId, 'core_one_c_exchange_operation');
        if ($query === null) { throw new AuthorizationException; }
        $success = (clone $query)->whereIn('status', ['delivered', 'accepted', 'posted', 'completed']);
        $failed = (clone $query)->whereIn('status', ['failed', 'dead_letter', 'rejected', 'requires_mapping']);
        $last = (clone $success)->select([])->selectRaw('MAX(COALESCE(finished_at, updated_at)) AS last_success_at')->first()?->getAttribute('last_success_at');

        return ['total' => (clone $query)->count(), 'success' => (clone $success)->count(), 'failed' => (clone $failed)->count(),
            'pending' => (clone $query)->whereIn('status', ['pending', 'queued', 'retry_scheduled'])->count(),
            'processing' => (clone $query)->where('status', 'processing')->count(),
            'last_success_at' => $last === null ? null : \Carbon\CarbonImmutable::parse($last, 'UTC')->toIso8601String()];
    }

    protected function format(array $data): string
    {
        return implode("\n", [trans_message('ai_assistant_operational.one_c_scope', ['date' => $data['as_of_date']]),
            trans_message('ai_assistant_operational.one_c_counts', array_intersect_key($data, array_flip(['total', 'success', 'failed', 'pending', 'processing']))),
            trans_message('ai_assistant_operational.one_c_last_success', ['date' => $data['last_success_at'] ?? trans_message('ai_assistant_operational.not_recorded')]),
            trans_message('ai_assistant_operational.one_c_basis')]);
    }
}
