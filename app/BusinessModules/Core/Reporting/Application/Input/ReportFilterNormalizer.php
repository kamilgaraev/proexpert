<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Input;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportFilterSet;
use DateTimeImmutable;
use DateTimeZone;

final class ReportFilterNormalizer
{
    private const TYPES = [
        'string',
        'integer',
        'decimal',
        'boolean',
        'date',
        'datetime',
        'reference',
    ];

    private const OPERATORS = [
        'eq',
        'neq',
        'in',
        'not_in',
        'gt',
        'gte',
        'lt',
        'lte',
        'between',
        'contains',
    ];

    public function __construct(
        private readonly ReportFilterReferenceResolver $referenceResolver,
    ) {}

    public function normalize(
        ReportExecutionContext $context,
        ReportDefinition $definition,
        array $input,
    ): ReportFilterSet {
        if (array_is_list($input) && $input !== []) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_REQUEST_INVALID);
        }

        $definitions = $this->definitionsById($definition);
        $normalized = [];

        foreach ($input as $filter => $payload) {
            if (!is_string($filter) || !isset($definitions[$filter])) {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_FILTER_UNSUPPORTED);
            }

            if (!is_array($payload) || array_is_list($payload)) {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_REQUEST_INVALID);
            }

            $payloadKeys = array_keys($payload);
            sort($payloadKeys);

            if ($payloadKeys !== ['operator', 'value'] || !is_string($payload['operator'])) {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_REQUEST_INVALID);
            }

            $metadata = $definitions[$filter];
            $operator = $payload['operator'];

            if (!in_array($metadata['type'], self::TYPES, true)
                || !in_array($operator, self::OPERATORS, true)
                || !in_array($operator, $metadata['operators'], true)) {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_FILTER_UNSUPPORTED);
            }

            $normalized[$filter] = [
                'operator' => $operator,
                'value' => $this->normalizeOperatorValue(
                    $context,
                    $filter,
                    $metadata['type'],
                    $operator,
                    $payload['value'],
                ),
            ];
        }

        return new ReportFilterSet($normalized);
    }

    private function definitionsById(ReportDefinition $definition): array
    {
        $definitions = [];

        foreach ($definition->filters as $metadata) {
            if (!isset($metadata['type'], $metadata['operators'])
                || !is_string($metadata['type'])
                || !is_array($metadata['operators'])
                || !array_is_list($metadata['operators'])
                || $metadata['operators'] === []
                || (isset($metadata['multiple']) && !is_bool($metadata['multiple']))) {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_FILTER_UNSUPPORTED);
            }

            foreach ($metadata['operators'] as $operator) {
                if (!is_string($operator) || !in_array($operator, self::OPERATORS, true)) {
                    throw ReportContractException::fromCode(ReportErrorCode::REPORT_FILTER_UNSUPPORTED);
                }
            }

            $definitions[$metadata['id']] = $metadata;
        }

        return $definitions;
    }

    private function normalizeOperatorValue(
        ReportExecutionContext $context,
        string $filter,
        string $type,
        string $operator,
        mixed $value,
    ): mixed {
        if (in_array($operator, ['in', 'not_in'], true)) {
            if (!is_array($value) || !array_is_list($value) || $value === []) {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_FILTER_RANGE_INVALID);
            }

            return array_map(
                fn (mixed $item): mixed => $this->normalizeScalar($context, $filter, $type, $item),
                $value,
            );
        }

        if ($operator === 'between') {
            if (!is_array($value) || !array_is_list($value) || count($value) !== 2) {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_FILTER_RANGE_INVALID);
            }

            return [
                $this->normalizeScalar($context, $filter, $type, $value[0]),
                $this->normalizeScalar($context, $filter, $type, $value[1]),
            ];
        }

        return $this->normalizeScalar($context, $filter, $type, $value);
    }

    private function normalizeScalar(
        ReportExecutionContext $context,
        string $filter,
        string $type,
        mixed $value,
    ): int|string|bool {
        return match ($type) {
            'string' => $this->normalizeString($value),
            'integer' => $this->normalizeInteger($value),
            'decimal' => $this->normalizeDecimal($value),
            'boolean' => $this->normalizeBoolean($value),
            'date' => $this->normalizeDate($value),
            'datetime' => $this->normalizeDateTime($value),
            'reference' => $this->normalizeReference($context, $filter, $value),
            default => throw ReportContractException::fromCode(ReportErrorCode::REPORT_FILTER_UNSUPPORTED),
        };
    }

    private function normalizeString(mixed $value): string
    {
        if (!is_string($value) || trim($value) === '') {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_REQUEST_INVALID);
        }

        return trim($value);
    }

    private function normalizeInteger(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (!is_string($value) || preg_match('/^-?(?:0|[1-9][0-9]*)$/', $value) !== 1) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_REQUEST_INVALID);
        }

        $normalized = filter_var($value, FILTER_VALIDATE_INT);

        if ($normalized === false) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_REQUEST_INVALID);
        }

        return $normalized;
    }

    private function normalizeDecimal(mixed $value): string
    {
        if (!is_int($value) && !is_string($value)) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_REQUEST_INVALID);
        }

        $decimal = (string) $value;

        if (preg_match('/^-?[0-9]+(?:\.[0-9]+)?$/', $decimal) !== 1) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_REQUEST_INVALID);
        }

        $negative = str_starts_with($decimal, '-');
        $unsigned = ltrim($decimal, '-');
        [$integer, $fraction] = array_pad(explode('.', $unsigned, 2), 2, '');
        $integer = ltrim($integer, '0');
        $integer = $integer === '' ? '0' : $integer;
        $fraction = rtrim($fraction, '0');
        $normalized = $integer . ($fraction === '' ? '' : '.' . $fraction);

        return $negative && $normalized !== '0' ? '-' . $normalized : $normalized;
    }

    private function normalizeBoolean(mixed $value): bool
    {
        if (!is_bool($value)) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_REQUEST_INVALID);
        }

        return $value;
    }

    private function normalizeDate(mixed $value): string
    {
        if (!is_string($value)) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_REQUEST_INVALID);
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();

        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d') !== $value) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_REQUEST_INVALID);
        }

        return $date->format('Y-m-d');
    }

    private function normalizeDateTime(mixed $value): string
    {
        if (!is_string($value)) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_REQUEST_INVALID);
        }

        $date = DateTimeImmutable::createFromFormat(DATE_ATOM, $value);
        $errors = DateTimeImmutable::getLastErrors();

        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_REQUEST_INVALID);
        }

        return $date->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM);
    }

    private function normalizeReference(
        ReportExecutionContext $context,
        string $filter,
        mixed $value,
    ): int|string {
        if ((!is_int($value) && !is_string($value)) || (is_string($value) && trim($value) === '')) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_REQUEST_INVALID);
        }

        try {
            return $this->referenceResolver->resolve($context->scope, $filter, $value);
        } catch (ReportContractException $exception) {
            if ($exception->errorCode !== ReportErrorCode::REPORT_FILTER_VALUE_NOT_FOUND) {
                throw $exception;
            }

            throw ReportContractException::fromCode(ReportErrorCode::REPORT_FILTER_VALUE_NOT_FOUND);
        }
    }
}
