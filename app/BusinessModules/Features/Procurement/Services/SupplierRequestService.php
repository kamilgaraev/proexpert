<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Services;

use App\BusinessModules\Features\Procurement\Enums\ProcurementAuditEventTypeEnum;
use App\BusinessModules\Features\Procurement\Enums\SupplierRequestStatusEnum;
use App\BusinessModules\Features\Procurement\Mail\SupplierRequestLinkMail;
use App\BusinessModules\Features\Procurement\Models\ExternalSupplierContact;
use App\BusinessModules\Features\Procurement\Models\PurchaseRequest;
use App\BusinessModules\Features\Procurement\Models\SupplierParty;
use App\BusinessModules\Features\Procurement\Models\SupplierRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SupplierRequestService
{
    public function __construct(
        private readonly SupplierPartyService $supplierPartyService,
        private readonly ProcurementAuditService $auditService,
        private readonly SupplierRequestVersionService $versionService,
        private readonly ProcurementLifecycleService $lifecycleService
    ) {
    }

    public function create(int $organizationId, array $data, ?int $actorId = null): SupplierRequest
    {
        return DB::transaction(function () use ($organizationId, $data, $actorId): SupplierRequest {
            $purchaseRequest = PurchaseRequest::query()
                ->forOrganization($organizationId)
                ->with('lines')
                ->findOrFail($data['purchase_request_id']);

            $this->lifecycleService->assertCanCreateSupplierRequest($purchaseRequest);

            $supplierId = $data['supplier_id'] ?? null;
            $externalSupplierContact = $this->resolveExternalSupplierContact($organizationId, $data);
            $externalSupplierContactId = $externalSupplierContact?->id;

            if (($supplierId === null) === ($externalSupplierContactId === null)) {
                throw ValidationException::withMessages([
                    'supplier' => trans_message('procurement.supplier_requests.single_supplier_source_required'),
                ]);
            }

            if ($supplierId !== null) {
                $supplierParty = $this->supplierPartyService->resolveRegisteredParty($organizationId, (int) $supplierId);
            } else {
                if (!$externalSupplierContact instanceof ExternalSupplierContact) {
                    throw ValidationException::withMessages([
                        'supplier' => trans_message('procurement.supplier_requests.single_supplier_source_required'),
                    ]);
                }

                $supplierParty = $this->supplierPartyService->resolveExternalParty(
                    $organizationId,
                    $externalSupplierContact
                );
            }

            $this->ensureNoActiveDuplicate($purchaseRequest->id, $supplierParty->id);

            $supplierSnapshot = $this->supplierPartyService->snapshotForDocument($supplierParty);

            $supplierRequest = SupplierRequest::query()->create([
                'organization_id' => $organizationId,
                'purchase_request_id' => $purchaseRequest->id,
                'supplier_id' => $supplierId,
                'external_supplier_contact_id' => $externalSupplierContactId,
                'supplier_party_id' => $supplierParty->id,
                'supplier_snapshot' => $supplierSnapshot,
                'request_number' => $this->generateRequestNumber(),
                'status' => SupplierRequestStatusEnum::DRAFT,
                'comment' => $data['comment'] ?? null,
                'metadata' => $data['metadata'] ?? null,
            ]);

            foreach ($purchaseRequest->lines as $line) {
                $supplierRequest->lines()->create([
                    'purchase_request_line_id' => $line->id,
                    'material_id' => $line->material_id,
                    'name' => $line->name,
                    'quantity' => $line->quantity,
                    'unit' => $line->unit,
                    'specification' => $line->specification,
                    'metadata' => $line->metadata,
                ]);
            }

            $this->auditService->record(
                ProcurementAuditEventTypeEnum::SUPPLIER_REQUEST_CREATED->value,
                $supplierRequest,
                $organizationId,
                $actorId,
                $supplierParty->id,
                [
                    'request_number' => $supplierRequest->request_number,
                    'status' => $supplierRequest->status->value,
                    'purchase_request_number' => $purchaseRequest->request_number,
                    'supplier_name' => $this->supplierName($supplierSnapshot),
                    'supplier_snapshot' => $supplierSnapshot,
                    'lines_count' => $purchaseRequest->lines->count(),
                    'comment' => $supplierRequest->comment,
                ]
            );

            return $supplierRequest->load(['lines', 'supplier', 'externalSupplierContact', 'supplierParty', 'purchaseRequest']);
        });
    }

    /**
     * @return array<int, SupplierRequest>
     */
    public function createMany(int $organizationId, array $data, ?int $actorId = null): array
    {
        return DB::transaction(function () use ($organizationId, $data, $actorId): array {
            $supplierRequests = [];
            $sendImmediately = (bool) ($data['send_immediately'] ?? true);

            foreach ($data['suppliers'] as $supplierData) {
                $supplierRequest = $this->create($organizationId, [
                    'purchase_request_id' => $data['purchase_request_id'],
                    'supplier_id' => $supplierData['supplier_id'] ?? null,
                    'external_supplier' => $supplierData['external_supplier'] ?? null,
                    'comment' => $data['comment'] ?? null,
                    'metadata' => $data['metadata'] ?? null,
                ], $actorId);

                $supplierRequests[] = $sendImmediately
                    ? $this->send($supplierRequest, $actorId)
                    : $supplierRequest;
            }

            return $supplierRequests;
        });
    }

    public function send(SupplierRequest $supplierRequest, ?int $actorId = null): SupplierRequest
    {
        $supplierRequest = $this->lifecycleService->syncSupplierRequestExpiry($supplierRequest);

        if (!$supplierRequest->canBeSent()) {
            throw ValidationException::withMessages([
                'status' => trans_message('procurement.supplier_requests.cannot_be_sent'),
            ]);
        }

        return DB::transaction(function () use ($supplierRequest, $actorId): SupplierRequest {
            $previousStatus = $supplierRequest->status->value;

            $supplierRequest->update([
                'status' => SupplierRequestStatusEnum::SENT,
                'sent_at' => now(),
                'public_token' => $supplierRequest->public_token ?? $this->generatePublicToken(),
                'public_token_expires_at' => now()->addDays((int) config('procurement.supplier_request_public_link_ttl_days', 14)),
                'public_opened_at' => null,
            ]);

            $requestedParty = $this->supplierPartyService->markRequested($supplierRequest->supplier_party_id);

            if ($requestedParty instanceof SupplierParty) {
                $supplierRequest->update([
                    'supplier_snapshot' => $this->supplierPartyService->snapshotForDocument($requestedParty),
                ]);
            }

            $supplierRequest->loadMissing('purchaseRequest');
            $version = $this->versionService->createSentVersion($supplierRequest->refresh(), $actorId);
            $snapshot = is_array($supplierRequest->supplier_snapshot) ? $supplierRequest->supplier_snapshot : [];
            $emailQueuedTo = $this->queuePublicLinkEmail($supplierRequest);

            $this->auditService->record(
                ProcurementAuditEventTypeEnum::SUPPLIER_REQUEST_SENT->value,
                $supplierRequest,
                (int) $supplierRequest->organization_id,
                $actorId,
                $supplierRequest->supplier_party_id,
                [
                    'request_number' => $supplierRequest->request_number,
                    'previous_status' => $previousStatus,
                    'status' => SupplierRequestStatusEnum::SENT->value,
                    'sent_at' => $supplierRequest->sent_at?->toIso8601String(),
                    'public_url' => $supplierRequest->publicUrl(),
                    'public_token_expires_at' => $supplierRequest->public_token_expires_at?->toIso8601String(),
                    'public_link_email_queued_to' => $emailQueuedTo,
                    'supplier_request_version_id' => $version->id,
                    'version_number' => $version->version_number,
                    'purchase_request_number' => $supplierRequest->purchaseRequest?->request_number,
                    'supplier_name' => $this->supplierName($snapshot),
                ]
            );

            return $supplierRequest->refresh()->load(['lines', 'supplier', 'externalSupplierContact', 'supplierParty', 'purchaseRequest', 'currentVersion']);
        });
    }

    public function cancel(SupplierRequest $supplierRequest, ?int $actorId = null): SupplierRequest
    {
        $supplierRequest = $this->lifecycleService->syncSupplierRequestExpiry($supplierRequest);

        if (!$supplierRequest->canBeCancelled()) {
            throw ValidationException::withMessages([
                'status' => trans_message('procurement.supplier_requests.cannot_be_cancelled'),
            ]);
        }

        return DB::transaction(function () use ($supplierRequest, $actorId): SupplierRequest {
            $previousStatus = $supplierRequest->status->value;

            $supplierRequest->update([
                'status' => SupplierRequestStatusEnum::CANCELLED,
                'cancelled_at' => now(),
            ]);

            $supplierRequest->loadMissing('purchaseRequest');
            $snapshot = is_array($supplierRequest->supplier_snapshot) ? $supplierRequest->supplier_snapshot : [];

            $this->auditService->record(
                ProcurementAuditEventTypeEnum::SUPPLIER_REQUEST_CANCELLED->value,
                $supplierRequest,
                (int) $supplierRequest->organization_id,
                $actorId,
                $supplierRequest->supplier_party_id,
                [
                    'request_number' => $supplierRequest->request_number,
                    'previous_status' => $previousStatus,
                    'status' => SupplierRequestStatusEnum::CANCELLED->value,
                    'cancelled_at' => $supplierRequest->cancelled_at?->toIso8601String(),
                    'purchase_request_number' => $supplierRequest->purchaseRequest?->request_number,
                    'supplier_name' => $this->supplierName($snapshot),
                ]
            );

            return $supplierRequest->refresh()->load(['lines', 'supplier', 'externalSupplierContact', 'supplierParty', 'purchaseRequest']);
        });
    }

    public function queryForOrganization(int $organizationId): Builder
    {
        return SupplierRequest::query()
            ->forOrganization($organizationId)
            ->with(['supplier', 'externalSupplierContact', 'supplierParty', 'purchaseRequest', 'lines', 'currentVersion'])
            ->withCount('lines')
            ->latest('id');
    }

    private function resolveExternalSupplierContact(int $organizationId, array $data): ?ExternalSupplierContact
    {
        if (!isset($data['external_supplier'])) {
            return null;
        }

        $externalSupplier = $data['external_supplier'];
        $contact = ExternalSupplierContact::query()->create([
            'organization_id' => $organizationId,
            'name' => $externalSupplier['name'],
            'contact_person' => $externalSupplier['contact_person'] ?? null,
            'phone' => $externalSupplier['phone'] ?? null,
            'email' => $externalSupplier['email'] ?? null,
            'tax_number' => $externalSupplier['tax_number'] ?? null,
            'address' => $externalSupplier['address'] ?? null,
            'metadata' => $externalSupplier['metadata'] ?? null,
        ]);

        return $contact;
    }

    private function queuePublicLinkEmail(SupplierRequest $supplierRequest): ?string
    {
        $publicUrl = $supplierRequest->publicUrl();

        if ($publicUrl === null) {
            return null;
        }

        $recipientEmail = $this->resolveRecipientEmail($supplierRequest);

        if ($recipientEmail === null) {
            return null;
        }

        Mail::to($recipientEmail)->queue(
            (new SupplierRequestLinkMail($supplierRequest, $publicUrl))->afterCommit()
        );

        return $recipientEmail;
    }

    private function resolveRecipientEmail(SupplierRequest $supplierRequest): ?string
    {
        $supplierRequest->loadMissing(['supplierParty', 'externalSupplierContact', 'supplier']);
        $snapshot = is_array($supplierRequest->supplier_snapshot) ? $supplierRequest->supplier_snapshot : [];

        foreach ([
            $supplierRequest->externalSupplierContact?->email,
            $supplierRequest->supplier?->email,
            $supplierRequest->supplierParty?->email,
            $snapshot['email'] ?? null,
        ] as $email) {
            $email = trim((string) $email);

            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
                return $email;
            }
        }

        return null;
    }

    private function generateRequestNumber(): string
    {
        $prefix = 'ЗПС-' . now()->format('Ym');
        $lastNumber = SupplierRequest::query()
            ->where('request_number', 'like', $prefix . '-%')
            ->count() + 1;

        return sprintf('%s-%04d', $prefix, $lastNumber);
    }

    private function generatePublicToken(): string
    {
        do {
            $token = Str::random(64);
        } while (SupplierRequest::query()->where('public_token', $token)->exists());

        return $token;
    }

    private function ensureNoActiveDuplicate(int $purchaseRequestId, int $supplierPartyId): void
    {
        $exists = SupplierRequest::query()
            ->where('purchase_request_id', $purchaseRequestId)
            ->where('supplier_party_id', $supplierPartyId)
            ->whereNotIn('status', [
                SupplierRequestStatusEnum::CANCELLED->value,
                SupplierRequestStatusEnum::EXPIRED->value,
            ])
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'supplier' => trans_message('procurement.supplier_requests.duplicate_active_supplier'),
            ]);
        }
    }

    private function supplierName(array $supplierSnapshot): ?string
    {
        $name = $supplierSnapshot['display_name'] ?? null;

        return $name === null ? null : (string) $name;
    }
}
