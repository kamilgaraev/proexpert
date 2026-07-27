<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Errors;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class ReportContractException extends RuntimeException
{
    public readonly ReportErrorCode $errorCode;

    public readonly array $safeFields;

    public function __construct(
        ReportErrorCode $errorCode,
        array $safeFields = [],
        ?Throwable $previous = null,
    ) {
        $this->assertSafeFields($safeFields);

        $this->errorCode = $errorCode;
        $this->safeFields = $safeFields;

        parent::__construct($errorCode->value, 0, $previous);
    }

    public static function fromCode(
        ReportErrorCode $errorCode,
        array $safeFields = [],
        ?Throwable $previous = null,
    ): self {
        return new self($errorCode, $safeFields, $previous);
    }

    private function assertSafeFields(array $safeFields): void
    {
        if ($safeFields === []) {
            return;
        }

        if (array_keys($safeFields) !== ['fields']) {
            throw new InvalidArgumentException('report_safe_fields_invalid');
        }

        $fields = $safeFields['fields'];

        if (is_array($fields) && !array_is_list($fields)) {
            throw new InvalidArgumentException('report_safe_fields_invalid');
        }

        $values = is_array($fields) ? $fields : [$fields];

        foreach ($values as $field) {
            if (!is_scalar($field) && $field !== null) {
                throw new InvalidArgumentException('report_safe_fields_invalid');
            }

            if (is_float($field) && !is_finite($field)) {
                throw new InvalidArgumentException('report_safe_fields_invalid');
            }

            if (is_string($field) && !$this->isSafeFieldName($field)) {
                throw new InvalidArgumentException('report_safe_fields_invalid');
            }
        }
    }

    private function isSafeFieldName(string $field): bool
    {
        if (preg_match('/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)*$/', $field) !== 1) {
            return false;
        }

        $segments = explode('.', $field);

        foreach ($segments as $segment) {
            if (in_array($segment, ['delete', 'filter', 'from', 'insert', 'join', 'raw', 'select', 'sql', 'update', 'url', 'where'], true)
                || str_starts_with($segment, 'raw_')) {
                return false;
            }
        }

        return true;
    }
}
