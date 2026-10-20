<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Operational;

use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\AssistantRequestExecutionContext;
use App\BusinessModules\Features\AIAssistant\Services\AssistantRequestProjectScope;
use App\BusinessModules\Features\Budgeting\Services\EpmDataMartHealthService;
use App\Enums\UserProjectAccessMode;
use App\Models\User;
use App\Services\Project\UserProjectAccessService;
use Illuminate\Auth\Access\AuthorizationException;

final readonly class AssistantOrganizationCalculationHealthReader extends AssistantOrganizationOperationalReader
{
    public const TYPE = 'live_organization_calculation_health_projection';

    public function __construct(AssistantDataAccessPolicy $access, private EpmDataMartHealthService $health, private UserProjectAccessService $projects)
    {
        parent::__construct($access);
    }

    public static function hasIntent(string $query): bool
    {
        return preg_match('/(?:управленческ|расч[её]тн)[а-яё]*\s+данн|витрин[а-яё]*\s+(?:управленческ[а-яё]*\s+)?данн/iu', $query) === 1
            && preg_match('/подготов|запуск|запуска|расч[её]т|пересч[её]т|актуальн|состоян|статус|успеш|ошиб/iu', $query) === 1
            && preg_match('/бюджетирован|финансов[а-яё]*\s+центр|управленческ|витрин/iu', $query) === 1;
    }

    public static function matches(string $query): bool
    {
        $readQuery = preg_replace('/(?:ничего\s+)?не\s+(?:пересчитывай(?:те)?|пересчитывать|обновляй(?:те)?|обновлять|запускай(?:те)?|запускать|меняй(?:те)?|менять)(?!\pL)/iu', '', $query) ?? $query;
        $scopeQuery = AssistantRequestProjectScope::organizationWide($query)
            ? preg_replace('/(?<!\pL)(?:(?:во?\s+)?всех|по\s+всем)\s+(?:нашим\s+)?(?:проект|объект)[а-яё]*(?!\pL)/iu', '', $query) ?? $query
            : $query;

        return self::hasIntent($query)
            && preg_match('/проект|объект/iu', $scopeQuery) !== 1
            && preg_match('/создай|удали|измени|пересчитай|пересчитывай|запусти|запускай|подготовь|подготавливай|обнови|обновляй|меняй|сформируй|выгрузи|отправь|excel|xlsx|csv|pdf|зарплат|сотрудник|договор|смет|потрач|выручк|прибыл|оплат|расход|доход|затрат|марж|рентабел|стоим|(?<!\pL)цен[а-яё]*(?!\pL)|плат[её]ж|(?<!\pL)сч[её]т/iu', $readQuery) !== 1;
    }

    public function read(User $actor, int $organizationId, array $arguments): array
    {
        $read = fn (): array => parent::read($actor, $organizationId, $arguments);

        return app()->bound(AssistantRequestExecutionContext::class)
            ? app(AssistantRequestExecutionContext::class)->withOperationBudget($read, 30_000, 'pgsql') : $read();
    }

    protected function collect(User $actor, int $organizationId): array
    {
        if (! in_array('budgeting', $this->access->effectiveModuleSlugs($actor, $organizationId), true)
            || ! $this->access->canCurrentPermission($actor, $organizationId, 'budgeting.cfo.view')
            || $this->projects->modeFor($actor, $organizationId) !== UserProjectAccessMode::ALL_PROJECTS) {
            throw new AuthorizationException;
        }
        $health = $this->health->status($organizationId);
        $statuses = ['queued', 'running', 'succeeded', 'partial', 'stale', 'failed'];
        $counts = [];
        foreach ($statuses as $status) {
            $counts[$status] = max(0, (int) ($health['counts'][$status] ?? 0));
        }

        return ['state' => in_array($health['status'] ?? null, [...$statuses, 'unavailable'], true) ? $health['status'] : 'unavailable',
            'counts' => $counts, 'last_attempt_at' => $health['last_attempt_at'] ?? null, 'last_success_at' => $health['last_success_at'] ?? null,
            'history_limit' => max(50, min(5000, (int) ($health['thresholds']['health_history_limit'] ?? 1000))),
            'can_prepare' => $this->access->canCurrentPermission($actor, $organizationId, 'budgeting.cfo.manage')];
    }

    protected function format(array $data): string
    {
        $lines = [trans_message('ai_assistant_calculation_health.scope', ['date' => $data['as_of_date']]),
            trans_message('ai_assistant_calculation_health.state', ['state' => trans_message('ai_assistant_calculation_health.states.'.$data['state'])]),
            trans_message('ai_assistant_calculation_health.history', ['limit' => $data['history_limit']]),
            $data['last_attempt_at'] === null ? trans_message('ai_assistant_calculation_health.no_attempt')
                : trans_message('ai_assistant_calculation_health.last_attempt', ['date' => $data['last_attempt_at']]),
            $data['last_success_at'] === null ? trans_message('ai_assistant_calculation_health.no_success')
                : trans_message('ai_assistant_calculation_health.last_success', ['date' => $data['last_success_at']])];
        foreach ($data['counts'] as $state => $count) {
            $lines[] = '- '.trans_message('ai_assistant_calculation_health.states.'.$state).': '.$count;
        }
        $lines[] = trans_message('ai_assistant_calculation_health.navigation');
        $lines[] = trans_message('ai_assistant_calculation_health.'.($data['can_prepare'] ? 'can_prepare' : 'ask_administrator'));
        $lines[] = trans_message('ai_assistant_calculation_health.portfolio_prerequisites');
        $lines[] = trans_message('ai_assistant_calculation_health.read_only');

        return implode("\n", $lines);
    }
}
