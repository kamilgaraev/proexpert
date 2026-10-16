<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use App\BusinessModules\Core\Reporting\Application\Input\CreateReportExportData;
use App\BusinessModules\Core\Reporting\Application\Input\CreateReportRunData;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSortDirection;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final readonly class ReportSubscriptionExecutionInput
{
    public function __construct(
        public string $reportCode,
        public ReportFilterSet $filters,
        public array $comparison,
        public string $locale,
        public string $savedViewId,
        public string $format,
        public array $columns,
        public ReportWindowSort $sort,
        public DateTimeZone $timezone,
        public array $periodPolicy,
        public string $contractVersion,
        public Sha256Hash $definitionHash,
        public ?int $periodPolicyVersion = null,
        public ?ReportSavedViewRef $savedViewReference = null,
        public ?int $projectScopeId = null,
    ) {
        if (preg_match('/^[0-9ABCDEFGHJKMNPQRSTVWXYZ]{26}$/D', $savedViewId) !== 1
            || ! in_array($format, ['csv', 'xlsx', 'pdf'], true)
            || $columns === [] || count($columns) !== count(array_unique($columns))
            || preg_match('/^[a-z]{2}(?:-[A-Z]{2})?$/D', $locale) !== 1
            || $periodPolicy === [] || trim($contractVersion) === ''
            || ($periodPolicyVersion !== null && $periodPolicyVersion !== 1)
            || ($savedViewReference !== null && $savedViewReference->id !== $savedViewId)
            || ($projectScopeId !== null && $projectScopeId < 1)) {
            throw new InvalidArgumentException('report_subscription_execution_input_invalid');
        }
    }

    public function runData(DateTimeImmutable $asOf): CreateReportRunData
    {
        return new CreateReportRunData($this->reportCode, $this->filters, $this->comparison, $asOf, $this->locale, $this->savedViewId, $this->savedViewReference);
    }

    public function effectiveProjectId(): ?int
    {
        $projectId = $this->reportCode === 'accepted_production_progress'
            ? $this->projectScopeId
            : ($this->projectScopeId ?? $this->filters->values['project_id'] ?? null);
        if ($projectId === null) {
            return null;
        }
        if (is_array($projectId)) {
            if (count($projectId) !== 2 || ($projectId['operator'] ?? null) !== 'eq' || ! array_key_exists('value', $projectId)) {
                throw new RuntimeException('report_subscription_project_scope_invalid');
            }
            $projectId = $projectId['value'];
        }
        if (! is_int($projectId) && ! is_string($projectId)) {
            throw new RuntimeException('report_subscription_project_scope_invalid');
        }
        $projectId = filter_var($projectId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($projectId === false) {
            throw new RuntimeException('report_subscription_project_scope_invalid');
        }

        return $projectId;
    }

    public function exportData(): CreateReportExportData
    {
        return new CreateReportExportData($this->format, $this->columns, $this->sort, $this->locale, $this->timezone);
    }

    public function canonicalBytes(): string
    {
        return CanonicalJson::encode([
            'report_code' => $this->reportCode, 'filters' => $this->filters->values,
            'comparison' => $this->comparison, 'locale' => $this->locale, 'saved_view_id' => $this->savedViewId,
            'format' => $this->format, 'columns' => $this->columns,
            'sort' => ['field' => $this->sort->field, 'direction' => $this->sort->direction->value],
            'timezone' => $this->timezone->getName(), 'period_policy' => $this->periodPolicy,
            'contract_version' => $this->contractVersion, 'definition_sha256' => $this->definitionHash->value,
            ...($this->periodPolicyVersion === null ? [] : ['period_policy_version' => $this->periodPolicyVersion]),
            ...($this->projectScopeId === null ? [] : ['project_scope_id' => $this->projectScopeId]),
            ...($this->savedViewReference === null ? [] : ['saved_view_reference' => [
                'id' => $this->savedViewReference->id, 'revision' => $this->savedViewReference->revision,
                'sha256' => $this->savedViewReference->hash->value,
            ]]),
        ]);
    }

    public function digest(): Sha256Hash
    {
        return new Sha256Hash(hash('sha256', $this->canonicalBytes()));
    }

    public static function fromCanonicalBytes(string $bytes): self
    {
        try {
            $value = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($value) || CanonicalJson::encode($value) !== $bytes
                || (array_key_exists('period_policy_version', $value) && $value['period_policy_version'] !== 1)
                || (array_key_exists('project_scope_id', $value) && (! is_int($value['project_scope_id']) || $value['project_scope_id'] < 1))) {
                throw new InvalidArgumentException;
            }
            $reference = null;
            if (array_key_exists('saved_view_reference', $value)) {
                $ref = $value['saved_view_reference'];
                if (! is_array($ref) || array_keys($ref) !== ['id', 'revision', 'sha256']) {
                    throw new InvalidArgumentException;
                }
                $reference = new ReportSavedViewRef($ref['id'], $ref['revision'], new Sha256Hash($ref['sha256']));
            }

            return new self(
                $value['report_code'], new ReportFilterSet($value['filters']), $value['comparison'], $value['locale'],
                $value['saved_view_id'], $value['format'], $value['columns'],
                new ReportWindowSort($value['sort']['field'], ReportSortDirection::from($value['sort']['direction'])),
                new DateTimeZone($value['timezone']), $value['period_policy'], $value['contract_version'],
                new Sha256Hash($value['definition_sha256']), $value['period_policy_version'] ?? null, $reference,
                $value['project_scope_id'] ?? null,
            );
        } catch (Throwable $exception) {
            throw new InvalidArgumentException('report_subscription_execution_input_invalid', 0, $exception);
        }
    }
}
