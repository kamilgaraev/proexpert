<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Publication;

use App\BusinessModules\Core\Reporting\Domain\DTO\CandidateReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBinding;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionConformanceEvidence;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPublicationProof;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPublicationRecord;
use InvalidArgumentException;

final readonly class ReportPublicationReleaseAdmission
{
    public function __construct(
        public CandidateReportDefinition $candidate,
        public array $candidateDocument,
        public ReportDefinitionBinding $binding,
        public ReportDefinitionConformanceEvidence $evidence,
        public ReportPublicationProof $proofTemplate,
        public array $verifiedChecks,
        public string $candidateManifestBytes,
        public string $officialManifestBytes,
        public ?ReportPublicationRecord $previous = null,
    ) {
        if ($candidateManifestBytes === ''
            || $officialManifestBytes === ''
            || $verifiedChecks === []
            || array_values(array_unique($verifiedChecks)) !== $verifiedChecks) {
            throw new InvalidArgumentException('report_publication_release_request_invalid');
        }
        foreach ($verifiedChecks as $check) {
            if (! is_string($check) || preg_match('/^[a-z][a-z0-9_]*_contract$/D', $check) !== 1) {
                throw new InvalidArgumentException('report_publication_release_request_invalid');
            }
        }
    }

    public function assertProductionSafe(): void
    {
        $payload = $this->proofTemplate->payload();
        foreach ($this->hashValues($payload) as $hash) {
            if (preg_match('/^([a-f0-9])\1{63}$/D', $hash) === 1) {
                throw new InvalidArgumentException('report_publication_release_request_sentinel_hash');
            }
        }
        $classes = [
            $this->binding->dataProvider::class,
            $this->binding->rowQuery::class,
            $this->binding->drillDownProvider::class,
            ...array_keys($this->evidence->componentClassHashes),
        ];
        if ($this->binding->readinessProbe !== null) {
            $classes[] = $this->binding->readinessProbe::class;
        }
        foreach ($classes as $class) {
            if (str_starts_with($class, 'Tests\\')) {
                throw new InvalidArgumentException('report_publication_release_request_test_component');
            }
        }
    }

    private function hashValues(mixed $value): array
    {
        if (! is_array($value)) {
            return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1 ? [$value] : [];
        }
        $hashes = [];
        foreach ($value as $item) {
            array_push($hashes, ...$this->hashValues($item));
        }

        return $hashes;
    }
}
