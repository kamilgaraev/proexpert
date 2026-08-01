<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Publication;

use InvalidArgumentException;

final readonly class ReportPublicationReleaseDispatchProfileCatalog
{
    /** @var array<string, ReportPublicationReleaseDispatch> */
    private array $dispatchesByCode;

    /** @param list<ReportPublicationReleaseDispatch> $dispatches */
    public function __construct(array $dispatches)
    {
        if ($dispatches === []) {
            throw new InvalidArgumentException('report_publication_release_profile_catalog_invalid');
        }

        $dispatchesByCode = [];
        foreach ($dispatches as $dispatch) {
            if (! $dispatch instanceof ReportPublicationReleaseDispatch || isset($dispatchesByCode[$dispatch->profile->code])) {
                throw new InvalidArgumentException('report_publication_release_profile_catalog_invalid');
            }
            $dispatchesByCode[$dispatch->profile->code] = $dispatch;
        }
        $this->dispatchesByCode = $dispatchesByCode;
    }

    public function forCode(string $code): ReportPublicationReleaseDispatch
    {
        $dispatch = $this->dispatchesByCode[$code] ?? null;
        if (! $dispatch instanceof ReportPublicationReleaseDispatch) {
            throw new InvalidArgumentException('report_publication_release_input_invalid');
        }

        return $dispatch;
    }

    public function forArtifactName(string $artifactName): ReportPublicationReleaseDispatch
    {
        foreach ($this->dispatchesByCode as $dispatch) {
            if (preg_match('/^'.preg_quote('report-publication-'.$dispatch->profile->code.'-', '/').'[a-f0-9]{64}$/D', $artifactName) === 1) {
                return $dispatch;
            }
        }

        throw new InvalidArgumentException('report_publication_release_input_invalid');
    }
}
