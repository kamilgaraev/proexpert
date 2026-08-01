<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Publication;

use App\BusinessModules\Core\Reporting\Application\Exports\ReportExportRenderer;
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
        if ($code === '' || $requiredChecks === [] || ! self::isSha256($drillDownSchemaHash) || $exports === []) {
            throw new InvalidArgumentException('report_publication_admission_profile_invalid');
        }

        $previous = null;
        foreach ($requiredChecks as $check) {
            if (! is_string($check)
                || ! in_array($check, self::allowedChecks(), true)
                || ($previous !== null && $previous >= $check)) {
                throw new InvalidArgumentException('report_publication_admission_profile_invalid');
            }
            $previous = $check;
        }

        $formats = array_keys($exports);
        $sortedFormats = $formats;
        sort($sortedFormats, SORT_STRING);
        if ($formats !== $sortedFormats) {
            throw new InvalidArgumentException('report_publication_admission_profile_invalid');
        }
        foreach ($exports as $format => $contract) {
            if (! is_string($format) || $format === ''
                || ! is_array($contract)
                || array_keys($contract) !== ['schema_sha256', 'renderer_class']
                || ! self::isSha256($contract['schema_sha256'] ?? null)
                || ! is_string($contract['renderer_class'] ?? null)
                || ! is_subclass_of($contract['renderer_class'], ReportExportRenderer::class)
                || ! hash_equals($format, $contract['renderer_class']::format())) {
                throw new InvalidArgumentException('report_publication_admission_profile_invalid');
            }
        }

        $this->assertCheckCoverage($formats);
    }

    /** @param list<string> $formats */
    public function assertCompatibleFormats(array $formats): void
    {
        $expected = $formats;
        sort($expected, SORT_STRING);
        if ($expected === [] || array_keys($this->exports) !== $expected) {
            throw new InvalidArgumentException('report_publication_ineligible');
        }
    }

    /** @param list<string> $formats */
    private function assertCheckCoverage(array $formats): void
    {
        $required = [
            'binding_contract',
            'drill_down_contract',
            'formula_contract',
            'rbac_contract',
            'source_contract',
        ];
        foreach ($formats as $format) {
            $required[] = 'export_'.$format.'_contract';
        }
        sort($required, SORT_STRING);
        $configured = $this->requiredChecks;
        $withoutPostgresql = array_values(array_filter(
            $configured,
            static fn (string $check): bool => $check !== 'postgresql_contract',
        ));
        if ($withoutPostgresql !== $required) {
            throw new InvalidArgumentException('report_publication_admission_profile_invalid');
        }
    }

    /** @return list<string> */
    private static function allowedChecks(): array
    {
        return [
            'binding_contract',
            'drill_down_contract',
            'export_csv_contract',
            'export_pdf_contract',
            'export_xlsx_contract',
            'formula_contract',
            'postgresql_contract',
            'rbac_contract',
            'source_contract',
        ];
    }

    private static function isSha256(mixed $value): bool
    {
        return is_string($value) && preg_match('/\\A[a-f0-9]{64}\\z/D', $value) === 1;
    }
}
