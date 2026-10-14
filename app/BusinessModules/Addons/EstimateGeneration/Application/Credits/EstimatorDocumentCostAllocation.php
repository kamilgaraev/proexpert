<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Credits;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use Brick\Math\BigDecimal;
use DomainException;
use Illuminate\Database\Connection;

/** Source pins are frozen before approval; immutable usages are never moved or duplicated. */
final readonly class EstimatorDocumentCostAllocation
{
    public function __construct(private Connection $database) {}

    public function capture(EstimateGenerationSession $session): array
    {
        $rows = $this->database->table('estimate_generation_credit_attempts as a')
            ->join('estimate_generation_credit_operations as o', 'o.operation_id', '=', 'a.operation_id')
            ->join('ai_credit_provider_usages as u', function ($join): void {
                $join->on('u.ai_credit_reservation_id', '=', 'a.reservation_id')->whereRaw('u.usage_key = a.attempt_id::text');
            })
            ->join('estimate_generation_ai_usage as v', 'v.attempt_id', '=', 'a.attempt_id')
            ->join('estimate_generation_vision_physical_attempts as p', 'p.attempt_id', '=', 'a.attempt_id')
            ->join('estimate_generation_processing_units as t', 't.id', '=', 'v.unit_id')
            ->join('estimate_generation_documents as d', 'd.id', '=', 'v.document_id')
            ->join('estimate_generation_document_pages as g', 'g.id', '=', 'v.page_id')
            ->where('a.organization_id', (int) $session->organization_id)->where('a.project_id', (int) $session->project_id)
            ->where('a.session_id', (int) $session->id)->where('o.kind', 'documents')->where('o.status', 'settled')
            ->where('o.consumed_units_minor', 0)->where('a.status', 'recorded')->whereNotNull('a.usage_transferred_at')
            ->where('u.is_successful', true)->where('u.cost_micro_rub', '>', 0)->where('v.status', 'succeeded')
            ->where('v.usage_status', 'measured')->where('v.pricing_status', 'available')->where('v.currency', 'RUB')
            ->whereIn('a.role', ['vision', 'ocr'])->where('t.status', 'completed')->whereNull('g.excluded_at')
            ->whereIn('d.status', ['ready', 'needs_review'])->where('d.processing_control_status', '<>', 'cancelled')
            ->whereRaw('o.organization_id=a.organization_id AND o.project_id=a.project_id AND o.session_id=a.session_id')
            ->whereRaw('u.organization_id=a.organization_id AND v.organization_id=a.organization_id AND v.project_id=a.project_id AND v.session_id=a.session_id')
            ->whereRaw('p.credit_operation_id=a.operation_id AND p.credit_role=a.role AND p.unit_id=t.id')
            ->whereRaw('v.operation=a.role')
            ->whereRaw('t.organization_id=a.organization_id AND t.project_id=a.project_id AND t.session_id=a.session_id AND t.document_id=d.id')
            ->whereRaw('d.organization_id=a.organization_id AND d.project_id=a.project_id AND d.session_id=a.session_id')
            ->whereRaw('g.organization_id=a.organization_id AND g.project_id=a.project_id AND g.session_id=a.session_id AND g.document_id=d.id AND g.processing_unit_id=t.id')
            ->whereRaw("t.source_version = 'sha256:' || lower(d.checksum_sha256) AND g.source_version=t.source_version")
            ->whereRaw("t.metadata->>'processing_attempt_id'=p.processing_lineage_id::text AND t.metadata->>'credit_operation_id'=o.operation_id::text")
            ->whereRaw("u.metadata->>'estimator_usage_fingerprint'=a.usage_fingerprint AND v.immutable_fingerprint='sha256:' || a.usage_fingerprint")
            ->whereRaw("u.metadata->>'cost_available'='true' AND u.metadata->>'provider_usage_available'='true'")
            ->whereRaw("u.metadata->>'cost_is_estimate' IS DISTINCT FROM 'true'")
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')->from('ai_credit_provider_cost_allocations as allocation')->whereColumn('allocation.source_provider_usage_id', 'u.id');
            })
            ->orderBy('a.attempt_id')->get(['a.attempt_id', 'a.operation_id', 'a.usage_fingerprint', 'u.id as provider_usage_id', 'u.cost_micro_rub',
                'd.id as document_id', 'g.id as page_id', 't.id as unit_id', 't.source_version', 'p.processing_lineage_id']);
        $items = [];
        $cost = BigDecimal::zero();
        foreach ($rows as $row) {
            $items[] = ['source_attempt_id' => $row->attempt_id, 'source_operation_id' => $row->operation_id,
                'source_provider_usage_id' => (int) $row->provider_usage_id, 'cost_micro_rub' => (int) $row->cost_micro_rub,
                'document_id' => (int) $row->document_id, 'page_id' => (int) $row->page_id, 'unit_id' => (int) $row->unit_id,
                'source_version' => $row->source_version, 'processing_lineage_id' => $row->processing_lineage_id,
                'usage_fingerprint' => $row->usage_fingerprint];
            $cost = $cost->plus((string) $row->cost_micro_rub);
        }

        return ['contract' => 'estimator-document-costs:v1', 'items' => $items, 'cost_micro_rub' => $cost->toInt()];
    }

    public function validate(EstimateGenerationSession $session, array $plan): array
    {
        if (! array_key_exists('document_costs', $plan)) {
            return ['items' => [], 'cost_micro_rub' => 0]; // Historical approvals keep their original charge.
        }
        $frozen = $plan['document_costs'];
        if (! is_array($frozen) || CanonicalPipelineJson::encode($frozen) !== CanonicalPipelineJson::encode($this->capture($session))) {
            throw new DomainException('estimator_credit_document_costs_changed');
        }

        return $frozen;
    }
}
