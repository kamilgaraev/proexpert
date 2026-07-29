<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Validation;

use Opis\JsonSchema\CompliantValidator;
use Opis\JsonSchema\ValidationResult;

final class Draft202012SchemaValidator
{
    public function __construct(private CompliantValidator $validator)
    {
    }

    public function validate(object $document, object $schema): ValidationResult
    {
        return $this->validator->validate($document, $schema);
    }

    public function assertValid(object $document, object $schema, string $schemaId): void
    {
        if (!$this->validate($document, $schema)->isValid()) {
            throw new ReportSchemaValidationException($schemaId);
        }
    }
}
