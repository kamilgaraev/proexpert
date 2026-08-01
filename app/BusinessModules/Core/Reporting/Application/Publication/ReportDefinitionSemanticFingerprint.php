<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Publication;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionConformanceEvidence;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;

final class ReportDefinitionSemanticFingerprint
{
    public function formula(ReportDefinitionConformanceEvidence $conformance): string
    {
        $components = [];
        foreach ($conformance->componentClassHashes as $class => $hash) {
            $components[] = [
                'class' => $class,
                'sha256' => $hash->value,
            ];
        }

        return $this->hash([
            'component_class_hashes' => $components,
            'totals_hash' => $conformance->formula->totalsHash->value,
        ]);
    }

    public function source(
        array $definition,
        ReportDefinitionConformanceEvidence $conformance,
    ): string {
        return $this->hash([
            'filters' => $definition['filters'] ?? null,
            'grain' => $definition['grain'] ?? null,
            'source_hash' => $conformance->source->sourceHash->value,
        ]);
    }

    private function hash(array $payload): string
    {
        return hash('sha256', CanonicalJson::encode($payload));
    }
}
