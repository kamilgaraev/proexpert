<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Actions\Domains;

use App\BusinessModules\Features\AIAssistant\Contracts\AIToolInterface;
use App\BusinessModules\Features\AIAssistant\Services\FinancialEvidence\AssistantLiveProjectFinanceReader;
use App\Models\Organization;
use App\Models\User;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final readonly class GetLiveProjectFinancialEvidenceTool implements AIToolInterface
{
    public function __construct(private AssistantLiveProjectFinanceReader $reader) {}
    public function getName(): string { return 'get_live_project_financial_evidence'; }
    public function getDescription(): string { return 'Точные текущие разрешённые денежные итоги одного проекта за указанный период до 366 дней: фактическая выручка, затраты, маржа; план и прогноз только при доступной действующей версии бюджета. Проверяет текущие права и источник. Не создаёт отчёт и не доказывает проценты или риск.'; }
    public function getParametersSchema(): array
    {
        return ['type' => 'object', 'properties' => ['project_id' => ['type' => 'integer', 'minimum' => 1], 'period_start' => ['type' => 'string', 'pattern' => '^\d{4}-\d{2}-\d{2}$'],
            'period_end' => ['type' => 'string', 'pattern' => '^\d{4}-\d{2}-\d{2}$'], 'currency' => ['type' => ['string', 'null'], 'enum' => ['RUB', 'USD', 'EUR', null]]],
            'required' => ['project_id', 'period_start', 'period_end', 'currency'], 'additionalProperties' => false];
    }
    public function execute(array $arguments, ?User $user, Organization $organization): array|string
    {
        if ($user === null) { throw new AccessDeniedHttpException; }
        return $this->reader->read($user, $organization, $arguments);
    }
}
