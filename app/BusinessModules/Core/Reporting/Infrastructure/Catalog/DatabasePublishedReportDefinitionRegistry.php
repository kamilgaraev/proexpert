<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Catalog;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportPublicationFeatureStore;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportPublicationRegistry;
use App\BusinessModules\Core\Reporting\Domain\DTO\PublishedReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportPublicationFeatureMode;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;

final readonly class DatabasePublishedReportDefinitionRegistry implements ReportDefinitionRegistry
{
    public function __construct(
        private ReportPublicationRegistry $publications,
        private ReportPublicationFeatureStore $features,
    ) {}

    public function published(string $code): PublishedReportDefinition
    {
        $definition = $this->publications->current($code);
        $feature = $this->features->current($code);
        if ($definition === null
            || $definition->publicationIdentity === null
            || $feature === null
            || $feature->mode !== ReportPublicationFeatureMode::ON
            || ! hash_equals($feature->publicationId, $definition->publicationIdentity->publicationId)
            || ! hash_equals($feature->proofHash->value, $definition->publicationIdentity->proofHash->value)) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_NOT_FOUND);
        }

        return $definition;
    }

    public function publishedCodes(): array
    {
        $codes = [];
        foreach ($this->publications->publishedCodes() as $code) {
            try {
                $this->published($code);
                $codes[] = $code;
            } catch (ReportContractException) {
            }
        }

        return $codes;
    }

    public function manifestSha256(): Sha256Hash
    {
        $entries = [];
        foreach ($this->publishedCodes() as $code) {
            $published = $this->published($code);
            $entries[] = [
                'code' => $code,
                'definition_sha256' => $published->definitionHash->value,
                'publication_id' => $published->publicationIdentity?->publicationId,
                'proof_sha256' => $published->publicationIdentity?->proofHash->value,
            ];
        }

        return new Sha256Hash(hash('sha256', CanonicalJson::encode($entries)));
    }
}
