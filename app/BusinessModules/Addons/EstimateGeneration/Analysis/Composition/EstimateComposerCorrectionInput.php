<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\EstimateAuditInput;
use InvalidArgumentException;

final readonly class EstimateComposerCorrectionInput
{
    /** @param list<array<string,mixed>> $findings */
    public function __construct(
        public EstimateAuditInput $audit,
        public array $findings,
    ) {
        if ($findings === [] || ! array_is_list($findings) || count($findings) > 1000) {
            throw new InvalidArgumentException('estimate_composer_correction_input_invalid');
        }
    }

    public function fingerprint(): string
    {
        $payload = ['audit_input' => $this->audit->canonicalPayload(false), 'findings' => $this->findings];
        $payload['audit_input']['derived_quantities'] = $this->audit->derivedQuantities;
        if ($this->audit->wireScope?->creditOperationId !== null) {
            $payload['approved_operation_id'] = $this->audit->wireScope->creditOperationId;
        }

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** @return array<string,mixed> */
    public function canonicalPayload(): array
    {
        return [
            'audit_input' => $this->audit->canonicalPayload(),
            'findings' => $this->findings,
        ];
    }
}
