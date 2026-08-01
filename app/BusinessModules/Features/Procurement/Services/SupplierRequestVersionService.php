<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Services;

use App\BusinessModules\Features\Procurement\Enums\ProcurementAuditEventTypeEnum;
use App\BusinessModules\Features\Procurement\Enums\SupplierRequestStatusEnum;
use App\BusinessModules\Features\Procurement\Models\SupplierRequest;
use App\BusinessModules\Features\Procurement\Models\SupplierRequestVersion;
use App\BusinessModules\Features\Procurement\Reporting\Award\Support\ProcurementAwardCanonicalizer;
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

        $payload = $this->versionPayload($supplierRequest);
        $version = SupplierRequestVersion::query()->create([
            'organization_id' => $supplierRequest->organization_id,
            'supplier_request_id' => $supplierRequest->id,
            'version_number' => $nextVersionNumber,
            'request_snapshot' => $payload['request_snapshot'],
            'line_snapshot' => $payload['line_snapshot'],
            'supplier_snapshot' => $supplierRequest->supplier_snapshot ?? [],
            'content_hash' => $payload['content_hash'],
            'integrity_status' => 'verified',
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

    public function versionPayload(SupplierRequest $supplierRequest): array
    {
        $requestSnapshot = [
            'id' => $supplierRequest->id,
            'request_number' => $supplierRequest->request_number,
            'status' => $supplierRequest->status instanceof SupplierRequestStatusEnum
                ? $supplierRequest->status->value
                : (string) $supplierRequest->status,
            'sent_at' => $supplierRequest->sent_at?->toIso8601String(),
            'purchase_request_id' => $supplierRequest->purchase_request_id,
            'purchase_request_number' => $supplierRequest->purchaseRequest?->request_number,
        ];
        $lineSnapshot = $supplierRequest->lines
            ->sortBy([
                ['id', 'asc'],
                ['purchase_request_line_id', 'asc'],
            ])
            ->map(static fn ($line): array => [
                'id' => $line->id,
                'purchase_request_line_id' => $line->purchase_request_line_id,
                'material_id' => $line->material_id,
                'name' => $line->name,
                'quantity' => ProcurementAwardCanonicalizer::decimal($line->quantity),
                'unit' => $line->unit,
                'specification_hash' => $line->specification === null
                    ? null
                    : hash('sha256', trim((string) $line->specification)),
            ])
            ->values()
            ->all();
        $hashPayload = [
            'request_snapshot' => $requestSnapshot,
            'line_snapshot' => $lineSnapshot,
        ];

        return [
            ...$hashPayload,
            'content_hash' => ProcurementAwardCanonicalizer::hash($hashPayload),
        ];
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

            if ($lockedRequest->status !== SupplierRequestStatusEnum::SENT) {
                throw ValidationException::withMessages([
                    'supplier_request_id' => [trans_message('procurement_enterprise.supplier_requests.must_be_sent_for_proposal')],
                ]);
            }

            $version = $this->currentSentVersion($lockedRequest);

            if ($version !== null) {
                return $version;
            }

            return $this->createSentVersion($lockedRequest, $actorId);
        });
    }
}
