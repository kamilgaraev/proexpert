<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Services;

use App\BusinessModules\Features\Procurement\Enums\ProcurementAuditEventTypeEnum;
use App\BusinessModules\Features\Procurement\Enums\SupplierRequestStatusEnum;
use App\BusinessModules\Features\Procurement\Models\SupplierRequest;
use App\BusinessModules\Features\Procurement\Models\SupplierRequestVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

use function trans_message;

class SupplierRequestVersionService
{
    public function __construct(
        private readonly ProcurementAuditService $auditService
    ) {}

    public function createSentVersion(SupplierRequest $supplierRequest, ?int $actorId = null): SupplierRequestVersion
    {
        $supplierRequest->loadMissing(['lines', 'supplierParty', 'purchaseRequest']);

        $nextVersionNumber = ((int) SupplierRequestVersion::query()
            ->where('organization_id', $supplierRequest->organization_id)
            ->where('supplier_request_id', $supplierRequest->id)
            ->max('version_number')) + 1;

        $version = SupplierRequestVersion::query()->create([
            'organization_id' => $supplierRequest->organization_id,
            'supplier_request_id' => $supplierRequest->id,
            'version_number' => $nextVersionNumber,
            'request_snapshot' => [
                'id' => $supplierRequest->id,
                'request_number' => $supplierRequest->request_number,
                'status' => $supplierRequest->status->value,
                'sent_at' => $supplierRequest->sent_at?->toIso8601String(),
                'comment' => $supplierRequest->comment,
                'metadata' => $supplierRequest->metadata,
                'purchase_request_id' => $supplierRequest->purchase_request_id,
                'purchase_request_number' => $supplierRequest->purchaseRequest?->request_number,
            ],
            'line_snapshot' => $supplierRequest->lines
                ->map(static fn ($line): array => [
                    'id' => $line->id,
                    'purchase_request_line_id' => $line->purchase_request_line_id,
                    'material_id' => $line->material_id,
                    'name' => $line->name,
                    'quantity' => (float) $line->quantity,
                    'unit' => $line->unit,
                    'specification' => $line->specification,
                    'metadata' => $line->metadata,
                ])
                ->values()
                ->all(),
            'supplier_snapshot' => $supplierRequest->supplier_snapshot ?? [],
            'sent_by' => $actorId,
            'sent_at' => $supplierRequest->sent_at ?? now(),
        ]);

        $this->auditService->record(
            ProcurementAuditEventTypeEnum::SUPPLIER_REQUEST_VERSION_CREATED->value,
            $supplierRequest,
            (int) $supplierRequest->organization_id,
            $actorId,
            $supplierRequest->supplier_party_id,
            [
                'supplier_request_version_id' => $version->id,
                'version_number' => $version->version_number,
                'request_number' => $supplierRequest->request_number,
                'lines_count' => count($version->line_snapshot ?? []),
            ]
        );

        return $version;
    }

    public function currentSentVersion(SupplierRequest $supplierRequest): ?SupplierRequestVersion
    {
        return SupplierRequestVersion::query()
            ->where('organization_id', $supplierRequest->organization_id)
            ->where('supplier_request_id', $supplierRequest->id)
            ->orderByDesc('version_number')
            ->first();
    }

    public function resolveForProposal(SupplierRequest $supplierRequest, ?int $actorId = null): SupplierRequestVersion
    {
        return DB::transaction(function () use ($supplierRequest, $actorId): SupplierRequestVersion {
            $lockedRequest = SupplierRequest::query()
                ->where('organization_id', $supplierRequest->organization_id)
                ->whereKey($supplierRequest->id)
                ->lockForUpdate()
                ->firstOrFail();

            $version = $this->currentSentVersion($lockedRequest);

            if ($version !== null) {
                return $version;
            }

            if ($lockedRequest->status !== SupplierRequestStatusEnum::SENT) {
                throw ValidationException::withMessages([
                    'supplier_request_id' => [trans_message('procurement_enterprise.supplier_requests.must_be_sent_for_proposal')],
                ]);
            }

            return $this->createSentVersion($lockedRequest, $actorId);
        });
    }
}
