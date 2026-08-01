<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Publication;

use InvalidArgumentException;

final readonly class ReportPublicationAdmissionProfileCatalog
{
    /** @var array<string, ReportPublicationAdmissionProfile> */
    private array $profilesByCode;

    /** @param list<ReportPublicationAdmissionProfile> $profiles */
    public function __construct(array $profiles)
    {
        if ($profiles === []) {
            throw new InvalidArgumentException('report_publication_admission_profile_catalog_invalid');
        }

        $profilesByCode = [];
        foreach ($profiles as $profile) {
            if (! $profile instanceof ReportPublicationAdmissionProfile || isset($profilesByCode[$profile->code])) {
                throw new InvalidArgumentException('report_publication_admission_profile_catalog_invalid');
            }
            $profilesByCode[$profile->code] = $profile;
        }
        $this->profilesByCode = $profilesByCode;
    }

    public function forCode(string $code): ReportPublicationAdmissionProfile
    {
        $profile = $this->profilesByCode[$code] ?? null;
        if (! $profile instanceof ReportPublicationAdmissionProfile) {
            throw new InvalidArgumentException('report_publication_ineligible');
        }

        return $profile;
    }

    /** @return array<string, list<string>> */
    public function requiredChecksByCode(): array
    {
        $result = [];
        foreach ($this->profilesByCode as $code => $profile) {
            $result[$code] = $profile->requiredChecks;
        }

        return $result;
    }

    /** @return array<string, array{drill_down_schema_sha256: string, exports: array<string, array{schema_sha256: string, renderer_class: class-string}>}> */
    public function deliveryContractsByCode(): array
    {
        $result = [];
        foreach ($this->profilesByCode as $code => $profile) {
            $result[$code] = [
                'drill_down_schema_sha256' => $profile->drillDownSchemaHash,
                'exports' => $profile->exports,
            ];
        }

        return $result;
    }
}
