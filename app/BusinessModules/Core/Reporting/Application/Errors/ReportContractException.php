<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Errors;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class ReportContractException extends RuntimeException
{
    private const SAFE_FIELD_NAMES = [
        'as_of',
        'organization_id',
        'user_id',
        'permission',
        'permissions',
        'formula_version',
        'source_hash',
        'snapshot_id',
        'definition_hash',
        'query_hash',
        'columns',
        'format',
        'filters',
        'comparison',
        'locale',
        'saved_view_id',
        'run_id',
        'export_id',
        'cursor',
        'limit',
        'sort_by',
        'sort_dir',
        'token',
        'timezone',
    ];

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

        if (is_string($fields)) {
            $fields = [$fields];
        }

        if (!is_array($fields) || !array_is_list($fields) || $fields === []) {
            throw new InvalidArgumentException('report_safe_fields_invalid');
        }

        foreach ($fields as $field) {
            if (!is_string($field) || !in_array($field, self::SAFE_FIELD_NAMES, true)) {
                throw new InvalidArgumentException('report_safe_fields_invalid');
            }
        }
    }
}
