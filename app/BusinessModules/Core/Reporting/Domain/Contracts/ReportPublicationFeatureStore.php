<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\Contracts;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPublicationFeatureConfiguration;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPublicationIdentity;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportPublicationFeatureMode;

interface ReportPublicationFeatureStore
{
    public function current(string $code): ?ReportPublicationFeatureConfiguration;

    public function configure(
        ReportPublicationIdentity $publication,
        ReportPublicationFeatureMode $mode,
        array $organizationAllowlist,
        array $userAllowlist,
    ): ReportPublicationFeatureConfiguration;
}
