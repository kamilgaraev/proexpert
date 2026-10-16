<?php

declare(strict_types=1);

namespace App\Services\CompletedWork\Reporting\AcceptedProduction\Services;

use App\BusinessModules\Core\Reporting\Application\Contracts\ReportFilterInputNormalizer;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportFilterSet;
use App\Services\CompletedWork\Reporting\AcceptedProduction\AcceptedProductionCandidateContract;
use DateTimeImmutable;

final readonly class AcceptedProductionFilterInputNormalizer implements ReportFilterInputNormalizer
{
    public function __construct(private AcceptedProductionCandidateContract $contract) {}

    public function forExecution(ReportExecutionContext $context, ReportDefinition $definition, ReportFilterSet $filters): ReportFilterSet
    {
        if ($definition->code !== AcceptedProductionCandidateContract::CODE) {
            return $filters;
        }
        $this->contract->assertDefinition($definition);
        if (count($context->scope->projectIds) !== 1) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SCOPE_FORBIDDEN);
        }
        $values = $filters->values;
        foreach (['organization_id' => $context->scope->organizationId, 'project_id' => $context->scope->projectIds[0]] as $key => $id) {
            if (array_key_exists($key, $values) && $values[$key] !== $id && $values[$key] !== (string) $id) {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_SCOPE_FORBIDDEN);
            }
            $values[$key] ??= $id;
        }

        return new ReportFilterSet($values);
    }

    public function normalize(ReportExecutionContext $context, ReportDefinition $definition, array $input): ?ReportFilterSet
    {
        if ($definition->code !== AcceptedProductionCandidateContract::CODE) {
            return null;
        }
        $this->contract->assertDefinition($definition);
        if (array_diff(array_keys($input), array_column($definition->filters, 'id')) !== []) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_FILTER_UNSUPPORTED);
        }
        foreach (['period_from', 'period_to'] as $field) {
            $date = $input[$field] ?? null;
            if (! is_string($date) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) !== 1
                || DateTimeImmutable::createFromFormat('!Y-m-d', $date)?->format('Y-m-d') !== $date) {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_FILTER_RANGE_INVALID);
            }
        }
        if ($input['period_from'] > $input['period_to']) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_FILTER_RANGE_INVALID);
        }
        foreach (['work_ids', 'act_ids', 'contractor_ids', 'unit_codes', 'zones', 'statuses'] as $field) {
            $items = $input[$field] ?? [];
            if (! is_array($items) || ! array_is_list($items)) {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_REQUEST_INVALID);
            }
            foreach ($items as $item) {
                if (in_array($field, ['work_ids', 'act_ids', 'contractor_ids'], true)
                    ? ! is_int($item) || $item < 1
                    : ! is_string($item) || trim($item) === '') {
                    throw ReportContractException::fromCode(ReportErrorCode::REPORT_REQUEST_INVALID);
                }
            }
        }
        if (array_diff($input['statuses'] ?? [], ['accepted', 'reversed']) !== []) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_REQUEST_INVALID);
        }

        return new ReportFilterSet($input);
    }
}
