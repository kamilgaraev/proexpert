<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Cycle\CiEvidence;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionConformanceEvidence;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;

final readonly class R15CiConformanceArtifact
{
    public function __construct(public ReportDefinitionConformanceEvidence $evidence) {}

    /** @return array<string,mixed> */
    public function canonicalPayload(): array
    {
        return [
            'artifact_id' => 'r15_runtime_conformance',
            'code' => $this->evidence->code,
            'conformance' => $this->evidence->canonicalPayload(),
            'conformance_digest' => $this->evidence->digest()->value,
            'fixture_hash' => $this->evidence->fixtureHash->value,
            'schema_version' => '1.0.0',
            'status' => $this->evidence->status,
        ];
    }

    public function canonicalJson(): string
    {
        return CanonicalJson::encode($this->canonicalPayload());
    }
}
