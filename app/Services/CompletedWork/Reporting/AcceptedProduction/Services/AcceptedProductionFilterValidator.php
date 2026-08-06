<?php

declare(strict_types=1);

namespace App\Services\CompletedWork\Reporting\AcceptedProduction\Services;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use DateTimeImmutable;

final readonly class AcceptedProductionFilterValidator
{
    private const FILTERS = [
        'period_from',
        'period_to',
        'work_ids',
        'act_ids',
        'contractor_ids',
        'unit_codes',
        'zones',
        'statuses',
        'organization_id',
        'project_id',
    ];

    private const STATUSES = [
        'accepted',
        'reversed',
    ];

    public function validate(ReportQuery $query): int
    {
        $values = $query->filters->values;
        foreach (array_keys($values) as $filter) {
            if (! is_string($filter) || ! in_array($filter, self::FILTERS, true)) {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_FILTER_UNSUPPORTED);
            }
        }

        $organizationId = $this->positiveInteger($values['organization_id'] ?? null);
        $projectId = $this->positiveInteger($values['project_id'] ?? null);
        if ($organizationId !== $query->scope->organizationId
            || ! in_array($projectId, $query->scope->projectIds, true)
        ) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SCOPE_FORBIDDEN);
        }

        $from = $this->date($values, 'period_from');
        $to = $this->date($values, 'period_to');
        if ($from > $to) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_FILTER_RANGE_INVALID);
        }

        foreach (['work_ids', 'act_ids', 'contractor_ids'] as $filter) {
            $this->positiveIntegerList($values, $filter);
        }
        foreach (['unit_codes', 'zones'] as $filter) {
            $this->nonEmptyStringList($values, $filter);
        }

        $statuses = $this->nonEmptyStringList($values, 'statuses');
        if (array_diff($statuses, self::STATUSES) !== []) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_REQUEST_INVALID);
        }

        return $projectId;
    }

    private function date(array $values, string $filter): string
    {
        $value = $values[$filter] ?? null;
        if (! is_string($value)
            || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1
            || DateTimeImmutable::createFromFormat('!Y-m-d', $value)?->format('Y-m-d') !== $value
        ) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_FILTER_RANGE_INVALID);
        }

        return $value;
    }

    private function positiveIntegerList(array $values, string $filter): array
    {
        $items = $this->list($values, $filter);
        foreach ($items as $item) {
            if (! is_int($item) || $item < 1) {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_REQUEST_INVALID);
            }
        }

        return $items;
    }

    private function positiveInteger(mixed $value): int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^[1-9]\d*$/D', $value) === 1) {
            return (int) $value;
        }

        throw ReportContractException::fromCode(ReportErrorCode::REPORT_SCOPE_FORBIDDEN);
    }

    private function nonEmptyStringList(array $values, string $filter): array
    {
        $items = $this->list($values, $filter);
        foreach ($items as $item) {
            if (! is_string($item) || trim($item) === '') {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_REQUEST_INVALID);
            }
        }

        return $items;
    }

    private function list(array $values, string $filter): array
    {
        $items = $values[$filter] ?? [];
        if (! is_array($items) || ! array_is_list($items)) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_REQUEST_INVALID);
        }

        return $items;
    }
}
