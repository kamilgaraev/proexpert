<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Validation;

use RuntimeException;

final class ReportSchemaValidationException extends RuntimeException
{
    public function __construct(public readonly string $schemaId)
    {
        parent::__construct('report_schema_invalid');
    }
}
