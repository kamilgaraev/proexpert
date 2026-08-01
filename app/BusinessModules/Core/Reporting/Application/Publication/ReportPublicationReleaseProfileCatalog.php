<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Publication;

use InvalidArgumentException;

final readonly class ReportPublicationReleaseProfileCatalog
{
    /** @var array<string, ReportPublicationReleaseDispatchProfile> */
    private array $profilesByCode;

    /** @param list<ReportPublicationReleaseDispatchProfile> $profiles */
    public function __construct(array $profiles)
    {
        if ($profiles === []) {
            throw new InvalidArgumentException('report_publication_release_profile_catalog_invalid');
        }

        $profilesByCode = [];
        foreach ($profiles as $profile) {
            if (! $profile instanceof ReportPublicationReleaseDispatchProfile
                || isset($profilesByCode[$profile->code])) {
                throw new InvalidArgumentException('report_publication_release_profile_catalog_invalid');
            }

            $profilesByCode[$profile->code] = $profile;
        }

        $this->profilesByCode = $profilesByCode;
    }

    public function forCode(string $code): ReportPublicationReleaseDispatchProfile
    {
        $profile = $this->profilesByCode[$code] ?? null;
        if (! $profile instanceof ReportPublicationReleaseDispatchProfile) {
            throw new InvalidArgumentException('report_publication_release_input_invalid');
        }

        return $profile;
    }
}
