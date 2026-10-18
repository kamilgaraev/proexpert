<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Reports;

use App\BusinessModules\Core\Reporting\Application\Contracts\ReportFilterInputNormalizer;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Application\Input\ReportFilterNormalizer;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportFilterSet;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\BuiltinPublishedReportDefinitionRegistry;
use JsonException;

final readonly class AssistantPublishedReportFilterInput
{
    public function __construct(
        private ReportFilterInputNormalizer $specific,
        private ReportFilterNormalizer $typed,
        private BuiltinPublishedReportDefinitionRegistry $builtins,
    ) {}

    public function parse(mixed $json): array
    {
        if (! is_string($json) || strlen($json) > 16000) {
            $this->invalid();
        }
        try {
            $object = json_decode($json, false, 12, JSON_THROW_ON_ERROR);
            $filters = json_decode($json, true, 12, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->invalid();
        }
        if (! $object instanceof \stdClass || ! is_array($filters)) {
            $this->invalid();
        }

        return $filters;
    }

    public function normalize(ReportExecutionContext $context, ReportDefinition $definition, array $input): ReportFilterSet
    {
        $specific = $this->specific->normalize($context, $definition, $input);
        if ($specific !== null) {
            return $specific;
        }
        if (! in_array($definition->code, $this->builtins->publishedCodes(), true)
            || ! hash_equals($this->builtins->published($definition->code)->payload()->definitionHash->value, $definition->definitionHash->value)) {
            return $this->typed->normalize($context, $definition, $input);
        }
        foreach ($definition->filters as $filter) {
            if (array_key_exists('type', $filter) || array_key_exists('operators', $filter)) {
                return $this->typed->normalize($context, $definition, $input);
            }
        }
        if (array_is_list($input) && $input !== []) {
            $this->invalid();
        }
        $declared = array_column($definition->filters, null, 'id');
        foreach ($input as $key => $value) {
            if (! is_string($key) || ! isset($declared[$key])) {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_FILTER_UNSUPPORTED);
            }
            if (is_array($value)) {
                if (! array_is_list($value) || count($value) > 1000) {
                    $this->invalid();
                }
                foreach ($value as $item) {
                    $this->scalar($item);
                }
            } else {
                $this->scalar($value);
            }
        }
        if (isset($declared['organization_id']) && ! array_key_exists('organization_id', $input)) {
            $input['organization_id'] = $context->scope->organizationId;
        }
        if (($declared['project_id']['required'] ?? false) && ! array_key_exists('project_id', $input) && count($context->scope->projectIds) === 1) {
            $input['project_id'] = $context->scope->projectIds[0];
        }
        foreach ($declared as $key => $filter) {
            if (($filter['required'] ?? false) && (! array_key_exists($key, $input) || $input[$key] === null || $input[$key] === '')) {
                $this->invalid();
            }
        }
        foreach (['organization_id' => [$context->scope->organizationId], 'project_id' => $context->scope->projectIds] as $key => $allowed) {
            if (array_key_exists($key, $input)) {
                $input[$key] = $this->authorizedId($input[$key], $allowed);
            }
        }
        foreach (['organization_ids' => $context->scope->holdingOrganizationIds, 'project_ids' => $context->scope->projectIds] as $key => $allowed) {
            if (! array_key_exists($key, $input)) {
                continue;
            }
            if (! is_array($input[$key])) {
                $this->invalid();
            }
            $ids = [];
            foreach ($input[$key] as $id) {
                $id = $this->authorizedId($id, $allowed);
                $ids[$id] = $id;
            }
            $input[$key] = $ids === [] ? $allowed : array_values($ids);
            if (count($input[$key]) > 1000) {
                $this->invalid();
            }
            sort($input[$key], SORT_NUMERIC);
        }
        if (strlen(json_encode($input, JSON_THROW_ON_ERROR)) > 16384) {
            $this->invalid();
        }

        return new ReportFilterSet($input);
    }

    private function scalar(mixed $value): void
    {
        if ((! is_scalar($value) && $value !== null) || (is_float($value) && ! is_finite($value))
            || (is_string($value) && mb_strlen($value) > 1000)) {
            $this->invalid();
        }
    }

    private function authorizedId(mixed $id, array $allowed): int
    {
        if (is_string($id) && preg_match('/^[1-9][0-9]*$/D', $id) === 1 && (string) (int) $id === $id) {
            $id = (int) $id;
        }
        if (! is_int($id) || $id < 1) {
            $this->invalid();
        }
        if (! in_array($id, $allowed, true)) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SCOPE_FORBIDDEN);
        }

        return $id;
    }

    private function invalid(): never
    {
        throw ReportContractException::fromCode(ReportErrorCode::REPORT_REQUEST_INVALID);
    }
}
