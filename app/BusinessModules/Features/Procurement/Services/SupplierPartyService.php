<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Services;

use App\BusinessModules\Features\Procurement\Enums\SupplierPartyStatusEnum;
use App\BusinessModules\Features\Procurement\Enums\SupplierPartyTypeEnum;
use App\BusinessModules\Features\Procurement\Models\ExternalSupplierContact;
use App\BusinessModules\Features\Procurement\Models\SupplierParty;
use App\Models\Supplier;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SupplierPartyService
{
    public function resolveExternalParty(int $organizationId, ExternalSupplierContact $contact): SupplierParty
    {
        return DB::transaction(function () use ($organizationId, $contact): SupplierParty {
            $contact = ExternalSupplierContact::query()
                ->where('organization_id', $organizationId)
                ->findOrFail($contact->id);

            $existingByContact = SupplierParty::query()
                ->forOrganization($organizationId)
                ->external()
                ->where('external_supplier_contact_id', $contact->id)
                ->first();

            if ($existingByContact instanceof SupplierParty) {
                return $existingByContact;
            }

            $normalizedEmail = $this->normalizeEmail($contact->email);

            if ($normalizedEmail !== null) {
                $existingByEmail = SupplierParty::query()
                    ->forOrganization($organizationId)
                    ->external()
                    ->where('normalized_email', $normalizedEmail)
                    ->whereNotIn('status', [
                        SupplierPartyStatusEnum::LINKED->value,
                        SupplierPartyStatusEnum::REJECTED->value,
                    ])
                    ->first();

                if ($existingByEmail instanceof SupplierParty) {
                    return $existingByEmail;
                }
            }

            $attributes = [
                'organization_id' => $organizationId,
                'type' => SupplierPartyTypeEnum::EXTERNAL,
                'status' => SupplierPartyStatusEnum::DRAFT,
                'registered_supplier_id' => null,
                'external_supplier_contact_id' => $contact->id,
                'display_name' => $contact->name,
                'contact_name' => $contact->contact_person,
                'email' => $contact->email,
                'normalized_email' => $normalizedEmail,
                'phone' => $contact->phone,
                'tax_id' => $contact->tax_number,
                'linked_at' => null,
            ];

            $attributes['snapshot'] = $this->snapshotFromAttributes($attributes);

            return SupplierParty::query()->create($attributes);
        });
    }

    public function resolveRegisteredParty(int $organizationId, int $supplierId): SupplierParty
    {
        return DB::transaction(function () use ($organizationId, $supplierId): SupplierParty {
            $supplier = $this->findActiveSupplier($organizationId, $supplierId);

            $existing = SupplierParty::query()
                ->forOrganization($organizationId)
                ->registered()
                ->where('registered_supplier_id', $supplier->id)
                ->first();

            if ($existing instanceof SupplierParty) {
                return $existing;
            }

            $attributes = $this->attributesFromSupplier(
                organizationId: $organizationId,
                supplier: $supplier,
                type: SupplierPartyTypeEnum::REGISTERED,
                status: SupplierPartyStatusEnum::LINKED,
                externalSupplierContactId: null,
                linkedAt: now(),
            );

            $attributes['snapshot'] = $this->snapshotFromAttributes($attributes);

            return SupplierParty::query()->create($attributes);
        });
    }

    public function linkExternalToRegistered(SupplierParty $party, int $supplierId): SupplierParty
    {
        return DB::transaction(function () use ($party, $supplierId): SupplierParty {
            $party = SupplierParty::query()
                ->forOrganization((int) $party->organization_id)
                ->external()
                ->findOrFail($party->id);

            if ($party->status === SupplierPartyStatusEnum::LINKED) {
                throw ValidationException::withMessages([
                    'supplier_party' => trans_message('procurement.supplier_parties.already_linked'),
                ]);
            }

            if ($party->status === SupplierPartyStatusEnum::REJECTED) {
                throw ValidationException::withMessages([
                    'supplier_party' => trans_message('procurement.supplier_parties.rejected_cannot_be_linked'),
                ]);
            }

            $supplier = $this->findActiveSupplier((int) $party->organization_id, $supplierId);

            $attributes = $this->attributesFromSupplier(
                organizationId: (int) $party->organization_id,
                supplier: $supplier,
                type: SupplierPartyTypeEnum::EXTERNAL,
                status: SupplierPartyStatusEnum::LINKED,
                externalSupplierContactId: $party->external_supplier_contact_id,
                linkedAt: now(),
            );

            $attributes['snapshot'] = $this->snapshotFromAttributes($attributes);

            $party->update($attributes);

            return $party->refresh();
        });
    }

    public function snapshotForDocument(SupplierParty $party): array
    {
        return $this->snapshotFromAttributes([
            'type' => $party->type,
            'status' => $party->status,
            'display_name' => $party->display_name,
            'contact_name' => $party->contact_name,
            'email' => $party->email,
            'phone' => $party->phone,
            'tax_id' => $party->tax_id,
            'registered_supplier_id' => $party->registered_supplier_id,
            'external_supplier_contact_id' => $party->external_supplier_contact_id,
        ]);
    }

    private function findActiveSupplier(int $organizationId, int $supplierId): Supplier
    {
        return Supplier::query()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->findOrFail($supplierId);
    }

    private function attributesFromSupplier(
        int $organizationId,
        Supplier $supplier,
        SupplierPartyTypeEnum $type,
        SupplierPartyStatusEnum $status,
        ?int $externalSupplierContactId,
        ?DateTimeInterface $linkedAt,
    ): array {
        return [
            'organization_id' => $organizationId,
            'type' => $type,
            'status' => $status,
            'registered_supplier_id' => $supplier->id,
            'external_supplier_contact_id' => $externalSupplierContactId,
            'display_name' => $supplier->name,
            'contact_name' => $supplier->contact_person,
            'email' => $supplier->email,
            'normalized_email' => $this->normalizeEmail($supplier->email),
            'phone' => $supplier->phone,
            'tax_id' => $supplier->tax_number ?: ($supplier->inn ?? null),
            'linked_at' => $linkedAt,
        ];
    }

    private function snapshotFromAttributes(array $attributes): array
    {
        $type = $attributes['type'] ?? null;
        $status = $attributes['status'] ?? null;

        return [
            'type' => $type instanceof SupplierPartyTypeEnum ? $type->value : $type,
            'status' => $status instanceof SupplierPartyStatusEnum ? $status->value : $status,
            'display_name' => $attributes['display_name'] ?? null,
            'contact_name' => $attributes['contact_name'] ?? null,
            'email' => $attributes['email'] ?? null,
            'phone' => $attributes['phone'] ?? null,
            'tax_id' => $attributes['tax_id'] ?? null,
            'registered_supplier_id' => $attributes['registered_supplier_id'] ?? null,
            'external_supplier_contact_id' => $attributes['external_supplier_contact_id'] ?? null,
        ];
    }

    private function normalizeEmail(?string $email): ?string
    {
        $email = trim((string) $email);

        if ($email === '') {
            return null;
        }

        return mb_strtolower($email);
    }
}
