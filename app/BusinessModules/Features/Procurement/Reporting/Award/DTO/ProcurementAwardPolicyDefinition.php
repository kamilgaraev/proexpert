<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Award\DTO;

use App\BusinessModules\Features\Procurement\Reporting\Award\Services\ProcurementAwardManifestBuilder;
use App\BusinessModules\Features\Procurement\Reporting\Award\Support\ProcurementAwardCanonicalizer;

final readonly class ProcurementAwardPolicyDefinition
{
    public function __construct(
        public string $policyId,
        public int $version,
        public string $schemaVersion,
    ) {}

    public static function v1(): self
    {
        return new self(
            policyId: '00000000-0000-4000-8000-000000000016',
            version: 1,
            schemaVersion: 'procurement-award-policy.v1',
        );
    }

    public function canonicalPayload(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
            'competition_mode' => 'supplier_request',
            'currency_mode' => 'exact_only',
            'amount_rule' => 'positive_total_else_subtotal_delivery_vat',
            'vat_mode' => 'exact_basis',
            'coverage_mode' => 'all_request_lines_exact_quantity_uom',
            'delivery_promise_mode' => 'required_date_or_lead_time',
            'reason_representation' => 'presence_length_sha256_only',
            'candidate_limit' => ProcurementAwardManifestBuilder::CANDIDATE_LIMIT,
            'capture_slo_milliseconds' => ProcurementAwardManifestBuilder::CAPTURE_SLO_MILLISECONDS,
        ];
    }

    public function canonicalHash(): string
    {
        return ProcurementAwardCanonicalizer::hash($this->canonicalPayload());
    }
}
