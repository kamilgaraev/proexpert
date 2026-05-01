<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = [
        'supplier_requests',
        'supplier_proposals',
        'purchase_orders',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                if (!Schema::hasColumn($tableName, 'supplier_party_id')) {
                    $table->foreignId('supplier_party_id')
                        ->nullable()
                        ->constrained('supplier_parties')
                        ->nullOnDelete();
                }

                if (!Schema::hasColumn($tableName, 'supplier_snapshot')) {
                    $table->json('supplier_snapshot')->nullable()->default('{}');
                }

                $indexName = $this->supplierPartyIndexName($tableName);
                if (!$this->indexExists($tableName, $indexName)) {
                    $table->index(['organization_id', 'supplier_party_id'], $indexName);
                }
            });

            $this->backfillDocumentTable($tableName);
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLES) as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                $indexName = $this->supplierPartyIndexName($tableName);

                if ($this->indexExists($tableName, $indexName)) {
                    $table->dropIndex($indexName);
                }

                if (Schema::hasColumn($tableName, 'supplier_party_id')) {
                    $table->dropConstrainedForeignId('supplier_party_id');
                }

                if (Schema::hasColumn($tableName, 'supplier_snapshot')) {
                    $table->dropColumn('supplier_snapshot');
                }
            });
        }
    }

    private function backfillDocumentTable(string $tableName): void
    {
        DB::table($tableName)
            ->whereNull('supplier_party_id')
            ->where(function ($query): void {
                $query->whereNotNull('external_supplier_contact_id')
                    ->orWhereNotNull('supplier_id');
            })
            ->orderBy('id')
            ->chunkById(200, function ($documents) use ($tableName): void {
                foreach ($documents as $document) {
                    $party = $this->resolvePartyForDocument($document);

                    if ($party === null) {
                        continue;
                    }

                    DB::table($tableName)
                        ->where('id', $document->id)
                        ->update([
                            'supplier_party_id' => $party['id'],
                            'supplier_snapshot' => $this->encodeSnapshot($this->snapshotForParty($party)),
                            'updated_at' => $document->updated_at,
                        ]);
                }
            });
    }

    private function resolvePartyForDocument(object $document): ?array
    {
        $organizationId = (int) $document->organization_id;

        if ($document->external_supplier_contact_id !== null) {
            return $this->resolveExternalParty($organizationId, (int) $document->external_supplier_contact_id);
        }

        if ($document->supplier_id !== null) {
            return $this->resolveRegisteredParty($organizationId, (int) $document->supplier_id);
        }

        return null;
    }

    private function resolveExternalParty(int $organizationId, int $contactId): ?array
    {
        $contact = DB::table('external_supplier_contacts')
            ->where('organization_id', $organizationId)
            ->where('id', $contactId)
            ->first();

        if ($contact === null) {
            return null;
        }

        $existing = DB::table('supplier_parties')
            ->where('organization_id', $organizationId)
            ->where('type', 'external')
            ->where('external_supplier_contact_id', $contactId)
            ->first();

        if ($existing !== null) {
            return (array) $existing;
        }

        $normalizedEmail = $this->normalizeEmail($contact->email ?? null);

        if ($normalizedEmail !== null) {
            $existing = DB::table('supplier_parties')
                ->where('organization_id', $organizationId)
                ->where('type', 'external')
                ->where('normalized_email', $normalizedEmail)
                ->whereNotIn('status', ['linked', 'rejected'])
                ->first();

            if ($existing !== null) {
                return (array) $existing;
            }
        }

        $attributes = [
            'organization_id' => $organizationId,
            'type' => 'external',
            'status' => 'draft',
            'registered_supplier_id' => null,
            'external_supplier_contact_id' => $contactId,
            'display_name' => $contact->name,
            'contact_name' => $contact->contact_person ?? null,
            'email' => $contact->email ?? null,
            'normalized_email' => $normalizedEmail,
            'phone' => $contact->phone ?? null,
            'tax_id' => $contact->tax_number ?? null,
            'linked_at' => null,
        ];

        return $this->createParty($attributes);
    }

    private function resolveRegisteredParty(int $organizationId, int $supplierId): ?array
    {
        $supplier = DB::table('suppliers')
            ->where('organization_id', $organizationId)
            ->where('id', $supplierId)
            ->first();

        if ($supplier === null) {
            return null;
        }

        $existing = DB::table('supplier_parties')
            ->where('organization_id', $organizationId)
            ->where('type', 'registered')
            ->where('registered_supplier_id', $supplierId)
            ->first();

        if ($existing !== null) {
            return (array) $existing;
        }

        $attributes = [
            'organization_id' => $organizationId,
            'type' => 'registered',
            'status' => 'linked',
            'registered_supplier_id' => $supplierId,
            'external_supplier_contact_id' => null,
            'display_name' => $supplier->name,
            'contact_name' => $supplier->contact_person ?? null,
            'email' => $supplier->email ?? null,
            'normalized_email' => $this->normalizeEmail($supplier->email ?? null),
            'phone' => $supplier->phone ?? null,
            'tax_id' => $supplier->tax_number ?: ($supplier->inn ?? null),
            'linked_at' => now(),
        ];

        return $this->createParty($attributes);
    }

    private function createParty(array $attributes): array
    {
        $now = now();
        $attributes['snapshot'] = $this->encodeSnapshot($this->snapshotFromAttributes($attributes));
        $attributes['created_at'] = $now;
        $attributes['updated_at'] = $now;

        $id = DB::table('supplier_parties')->insertGetId($attributes);

        return (array) DB::table('supplier_parties')->where('id', $id)->first();
    }

    private function snapshotForParty(array $party): array
    {
        return $this->snapshotFromAttributes([
            'type' => $party['type'] ?? null,
            'status' => $party['status'] ?? null,
            'display_name' => $party['display_name'] ?? null,
            'contact_name' => $party['contact_name'] ?? null,
            'email' => $party['email'] ?? null,
            'phone' => $party['phone'] ?? null,
            'tax_id' => $party['tax_id'] ?? null,
            'registered_supplier_id' => $party['registered_supplier_id'] ?? null,
            'external_supplier_contact_id' => $party['external_supplier_contact_id'] ?? null,
        ]);
    }

    private function snapshotFromAttributes(array $attributes): array
    {
        return [
            'type' => $attributes['type'] ?? null,
            'status' => $attributes['status'] ?? null,
            'display_name' => $attributes['display_name'] ?? null,
            'contact_name' => $attributes['contact_name'] ?? null,
            'email' => $attributes['email'] ?? null,
            'phone' => $attributes['phone'] ?? null,
            'tax_id' => $attributes['tax_id'] ?? null,
            'registered_supplier_id' => $attributes['registered_supplier_id'] ?? null,
            'external_supplier_contact_id' => $attributes['external_supplier_contact_id'] ?? null,
        ];
    }

    private function encodeSnapshot(array $snapshot): string
    {
        return json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function normalizeEmail(?string $email): ?string
    {
        $email = trim((string) $email);

        if ($email === '') {
            return null;
        }

        return mb_strtolower($email);
    }

    private function supplierPartyIndexName(string $tableName): string
    {
        return "{$tableName}_organization_supplier_party_index";
    }

    private function indexExists(string $tableName, string $indexName): bool
    {
        foreach (Schema::getIndexes($tableName) as $index) {
            if (($index['name'] ?? null) === $indexName) {
                return true;
            }
        }

        return false;
    }
};
