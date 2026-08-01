<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Publication;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPublicationFeatureConfiguration;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPublicationIdentity;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportPublicationFeatureMode;

final class ReportPublicationFeatureGate
{
    private const ACTIONS = ['download', 'export', 'run', 'subscription'];

    public function allows(
        ReportPublicationFeatureConfiguration $configuration,
        ReportPublicationIdentity $publication,
        int $organizationId,
        int $userId,
        string $action,
    ): bool {
        if (! in_array($action, self::ACTIONS, true)
            || $organizationId < 1
            || $userId < 1
            || ! $this->samePublication($configuration, $publication)) {
            return false;
        }

        return match ($configuration->mode) {
            ReportPublicationFeatureMode::OFF,
            ReportPublicationFeatureMode::DISABLED => false,
            ReportPublicationFeatureMode::ON => true,
            ReportPublicationFeatureMode::CANARY => $action !== 'subscription'
                && (in_array($organizationId, $configuration->organizationAllowlist, true)
                    || in_array($userId, $configuration->userAllowlist, true)),
        };
    }

    public function allowsAudit(
        ReportPublicationFeatureConfiguration $configuration,
        ReportPublicationIdentity $publication,
    ): bool {
        return hash_equals($configuration->code, $publication->code);
    }

    private function samePublication(
        ReportPublicationFeatureConfiguration $configuration,
        ReportPublicationIdentity $publication,
    ): bool {
        return hash_equals($configuration->code, $publication->code)
            && hash_equals($configuration->publicationId, $publication->publicationId)
            && hash_equals($configuration->proofHash->value, $publication->proofHash->value);
    }
}
