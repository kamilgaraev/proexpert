<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Publication;

use InvalidArgumentException;

final readonly class ReportPublicationAdmissionProfile
{
    /**
     * @param  list<string>  $requiredChecks
     * @param  array<string, array{schema_sha256: string, renderer_class: class-string}>  $exports
     */
    public function __construct(
        public string $code,
        public array $requiredChecks,
        public string $drillDownSchemaHash,
        public array $exports,
    ) {
        if ($code === '' || $requiredChecks === [] || $drillDownSchemaHash === '' || $exports === []) {
            throw new InvalidArgumentException('report_publication_admission_profile_invalid');
        }

        $previous = null;
        foreach ($requiredChecks as $check) {
            if (! is_string($check) || $check === '' || ($previous !== null && $previous >= $check)) {
                throw new InvalidArgumentException('report_publication_admission_profile_invalid');
            }
            $previous = $check;
        }

        foreach ($exports as $format => $contract) {
            if (! is_string($format) || $format === ''
                || ! is_array($contract)
                || array_keys($contract) !== ['schema_sha256', 'renderer_class']
                || ! is_string($contract['schema_sha256']) || $contract['schema_sha256'] === ''
                || ! is_string($contract['renderer_class']) || $contract['renderer_class'] === '') {
                throw new InvalidArgumentException('report_publication_admission_profile_invalid');
            }
        }
    }
}
