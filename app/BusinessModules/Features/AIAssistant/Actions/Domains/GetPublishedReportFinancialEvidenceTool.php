<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Actions\Domains;

use App\BusinessModules\Features\AIAssistant\Contracts\AIToolInterface;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\FinancialEvidence\AssistantPublishedReportReader;
use App\Models\Organization;
use App\Models\User;
use DomainException;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final readonly class GetPublishedReportFinancialEvidenceTool implements AIToolInterface
{
    public function __construct(private AssistantPublishedReportReader $reader, private AssistantDataAccessPolicy $access) {}

    public function getName(): string { return 'get_published_report_financial_evidence'; }
    public function getDescription(): string { return 'Точные разрешённые суммы готового зарегистрированного отчёта по существующему run_id. Возвращает валюту, единицу, точность и доказательство снимка на as_of; не создаёт отчёт, не принимает произвольный запрос и не заменяет текущую бухгалтерскую книгу.'; }
    public function getParametersSchema(): array
    {
        return ['type' => 'object', 'properties' => ['run_id' => ['type' => 'string', 'pattern' => '^[0-9A-HJKMNP-TV-Z]{26}$'],
            'cursor' => ['type' => ['string', 'null'], 'maxLength' => 4096], 'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20]],
            'required' => ['run_id', 'cursor', 'limit'], 'additionalProperties' => false];
    }
    public function execute(array $arguments, ?User $user, Organization $organization): array|string
    {
        if ($user === null || ! $this->access->canReadDomain($user, (int) $organization->id, 'assistant')) { throw new AccessDeniedHttpException; }
        if (array_diff(array_keys($arguments), ['run_id', 'cursor', 'limit']) !== [] || ! is_string($arguments['run_id'] ?? null)
            || ! is_int($arguments['limit'] ?? null) || (! is_string($arguments['cursor'] ?? null) && ($arguments['cursor'] ?? null) !== null)) {
            throw new InvalidArgumentException('assistant_report_projection_arguments_invalid');
        }
        try { return $this->reader->read($user, $organization, $arguments['run_id'], $arguments['cursor'] ?? null, $arguments['limit']); }
        catch (DomainException $exception) {
            if (! in_array($exception->getMessage(), ['assistant_report_projection_numeric_fields_unavailable', 'assistant_report_projection_not_current_complete'], true)) { throw $exception; }
            return ['status' => 'insufficient_data', 'outcome' => 'insufficient_data', 'useful' => false, 'source_covered' => false, 'source_refs' => [], 'financial_evidence' => [],
                'reason' => $exception->getMessage(), 'validation_status' => 'partial', 'message' => 'Для точного ответа нужен доступный полный отчёт с подтверждёнными денежными полями.'];
        }
    }
}
