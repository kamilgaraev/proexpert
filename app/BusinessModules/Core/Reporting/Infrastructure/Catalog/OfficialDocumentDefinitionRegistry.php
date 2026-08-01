<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Catalog;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\DTO\LoadedReportManifest;
use App\BusinessModules\Core\Reporting\Domain\DTO\OfficialDocumentDefinition;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportPublicationReadiness;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use InvalidArgumentException;

final class OfficialDocumentDefinitionRegistry
{
    private OfficialDocumentDefinition $definition;

    public function __construct(private LoadedReportManifest $manifest)
    {
        if ($manifest->catalog !== 'official-document-catalog.v1') {
            throw new InvalidArgumentException('official_document_manifest_invalid');
        }

        $row = $manifest->definitions[0];
        $sealRequires = $row['seal_requires'] ?? null;
        if (! is_array($sealRequires) || ! array_is_list($sealRequires)) {
            throw new InvalidArgumentException('official_document_manifest_invalid');
        }

        $this->definition = new OfficialDocumentDefinition(
            code: $this->string($row, 'code'),
            titleKey: $this->string($row, 'title_key'),
            rendererVersion: $this->string($row, 'renderer_version'),
            publicationReadiness: ReportPublicationReadiness::from($this->string($row, 'publication_readiness')),
            legalRetentionPolicy: $this->string($row, 'legal_retention_policy'),
            sealRequires: $sealRequires,
        );
    }

    public function official(string $code): OfficialDocumentDefinition
    {
        if ($code !== $this->definition->code) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_NOT_FOUND);
        }

        return $this->definition;
    }

    public function codes(): array
    {
        return [$this->definition->code];
    }

    public function manifestSha256(): Sha256Hash
    {
        return $this->manifest->bytesHash;
    }

    private function string(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (! is_string($value)) {
            throw new InvalidArgumentException('official_document_manifest_invalid');
        }

        return $value;
    }
}
