<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Award\Services;

use App\BusinessModules\Features\Procurement\Reporting\Award\Contracts\ProcurementAwardSelectionSource;
use App\BusinessModules\Features\Procurement\Reporting\Award\Support\ProcurementAwardVersionProjection;
use DateTimeImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

final class EloquentProcurementAwardSelectionSource implements ProcurementAwardSelectionSource
{
    public function candidateRows(
        int $organizationId,
        int $supplierRequestId,
        DateTimeImmutable $occurredAt,
    ): array {
        $proposalIds = DB::table('supplier_proposals as proposal')
            ->where('proposal.organization_id', $organizationId)
            ->where('proposal.supplier_request_id', $supplierRequestId)
            ->whereNull('proposal.deleted_at')
            ->orderBy('proposal.id')
            ->limit(ProcurementAwardManifestBuilder::CANDIDATE_LIMIT + 1)
            ->lock('FOR UPDATE OF proposal')
            ->pluck('proposal.id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        if ($proposalIds === []) {
            throw new DomainException('procurement_award_candidates_required');
        }
        if (count($proposalIds) > ProcurementAwardManifestBuilder::CANDIDATE_LIMIT) {
            throw new DomainException('procurement_award_candidate_limit_exceeded');
        }

        $rows = DB::table('supplier_proposals as proposal')
            ->join('supplier_requests as supplier_request', 'supplier_request.id', '=', 'proposal.supplier_request_id')
            ->join('purchase_requests as purchase_request', 'purchase_request.id', '=', 'supplier_request.purchase_request_id')
            ->leftJoin('site_requests as site_request', 'site_request.id', '=', 'purchase_request.site_request_id')
            ->leftJoin('supplier_request_versions as request_version', 'request_version.id', '=', 'proposal.supplier_request_version_id')
            ->leftJoin('supplier_proposal_versions as proposal_version', function ($join): void {
                $join->on('proposal_version.supplier_proposal_id', '=', 'proposal.id')
                    ->whereRaw('proposal_version.version_number = (SELECT MAX(latest.version_number) '
                        .'FROM supplier_proposal_versions latest '
                        .'WHERE latest.supplier_proposal_id = proposal.id)');
            })
            ->whereIn('proposal.id', $proposalIds)
            ->whereNull('supplier_request.deleted_at')
            ->orderBy('proposal.id')
            ->orderBy('proposal_version.id')
            ->get([
                'proposal.organization_id',
                'site_request.project_id',
                'purchase_request.id as purchase_request_id',
                'proposal.supplier_request_id',
                'proposal.supplier_request_version_id',
                'request_version.content_hash as supplier_request_version_hash',
                'request_version.line_snapshot as request_lines',
                'proposal.id as proposal_id',
                'proposal_version.id as proposal_version_id',
                'proposal.supplier_party_id',
                'proposal.status as proposal_status',
                'proposal.valid_until as proposal_valid_until',
                'proposal_version.content_hash as version_content_hash',
                'proposal_version.commercial_snapshot',
            ]);

        if ($rows->isEmpty()) {
            throw new DomainException('procurement_award_exact_candidate_versions_required');
        }

        return $rows->map(static fn (object $row): array => [
            'organization_id' => (int) $row->organization_id,
            'project_id' => $row->project_id === null ? null : (int) $row->project_id,
            'purchase_request_id' => (int) $row->purchase_request_id,
            'supplier_request_id' => (int) $row->supplier_request_id,
            'supplier_request_version_id' => $row->supplier_request_version_id === null
                ? null
                : (int) $row->supplier_request_version_id,
            'supplier_request_version_hash' => $row->supplier_request_version_hash,
            'proposal_id' => (int) $row->proposal_id,
            'proposal_version_id' => $row->proposal_version_id === null ? null : (int) $row->proposal_version_id,
            'supplier_party_id' => (int) $row->supplier_party_id,
            'proposal_status' => (string) $row->proposal_status,
            'proposal_valid_until' => $row->proposal_valid_until === null
                ? null
                : (string) $row->proposal_valid_until,
            'selection_date' => $occurredAt->format('Y-m-d'),
            'version_content_hash' => $row->version_content_hash,
            'request_lines' => ProcurementAwardVersionProjection::requestLines(self::jsonArrayOrEmpty($row->request_lines)),
            'commercial_snapshot' => ProcurementAwardVersionProjection::proposal(self::jsonArrayOrEmpty($row->commercial_snapshot)),
        ])->all();
    }

    public function supplierRequestIds(
        int $organizationId,
        int $purchaseRequestId,
        DateTimeImmutable $occurredAt,
    ): array {
        return DB::table('supplier_requests as supplier_request')
            ->where('supplier_request.organization_id', $organizationId)
            ->where('supplier_request.purchase_request_id', $purchaseRequestId)
            ->whereNull('supplier_request.deleted_at')
            ->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('supplier_proposals as proposal')
                    ->whereColumn('proposal.supplier_request_id', 'supplier_request.id')
                    ->whereNull('proposal.deleted_at');
            })
            ->orderBy('supplier_request.id')
            ->lockForUpdate()
            ->pluck('supplier_request.id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    private static function jsonArrayOrEmpty(mixed $value): array
    {
        if ($value === null) {
            return [];
        }
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value)) {
            throw new DomainException('procurement_award_exact_version_payload_invalid');
        }
        $decoded = json_decode($value, true, flags: JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : throw new DomainException('procurement_award_exact_version_payload_invalid');
    }
}
