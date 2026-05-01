<?php

declare(strict_types=1);

namespace Tests\Feature\Procurement;

use App\BusinessModules\Features\Procurement\Models\ExternalSupplierContact;
use App\BusinessModules\Features\Procurement\Services\SupplierPartyService;
use App\Models\Organization;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Tests\TestCase;

class SupplierPartyWorkflowTest extends TestCase
{
    public function test_external_supplier_contact_creates_party_with_document_snapshot(): void
    {
        $organization = Organization::factory()->create();
        $contact = ExternalSupplierContact::query()->create([
            'organization_id' => $organization->id,
            'name' => 'External Steel LLC',
            'contact_person' => 'Alex Contact',
            'phone' => '+7 900 100-20-30',
            'email' => ' Sales@External-Steel.test ',
            'tax_number' => '7701000000',
            'address' => 'Industrial st. 1',
        ]);

        $party = app(SupplierPartyService::class)->resolveExternalParty($organization->id, $contact);

        $this->assertSame($organization->id, $party->organization_id);
        $this->assertSame('external', $party->type->value);
        $this->assertSame('draft', $party->status->value);
        $this->assertSame($contact->id, $party->external_supplier_contact_id);
        $this->assertNull($party->registered_supplier_id);
        $this->assertSame('External Steel LLC', $party->display_name);
        $this->assertSame('alex contact', mb_strtolower($party->contact_name));
        $this->assertSame('sales@external-steel.test', $party->normalized_email);

        $snapshot = app(SupplierPartyService::class)->snapshotForDocument($party);

        $this->assertSame([
            'type' => 'external',
            'status' => 'draft',
            'display_name' => 'External Steel LLC',
            'contact_name' => 'Alex Contact',
            'email' => ' Sales@External-Steel.test ',
            'phone' => '+7 900 100-20-30',
            'tax_id' => '7701000000',
            'registered_supplier_id' => null,
            'external_supplier_contact_id' => $contact->id,
        ], $snapshot);
    }

    public function test_external_party_is_reused_by_contact_and_normalized_email_inside_organization(): void
    {
        $organization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();

        $firstContact = ExternalSupplierContact::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Shared Email First',
            'email' => 'offer@example.test',
        ]);

        $secondContact = ExternalSupplierContact::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Shared Email Second',
            'email' => ' OFFER@example.test ',
        ]);

        $otherContact = ExternalSupplierContact::query()->create([
            'organization_id' => $otherOrganization->id,
            'name' => 'Shared Email Other Organization',
            'email' => 'offer@example.test',
        ]);

        $service = app(SupplierPartyService::class);

        $firstParty = $service->resolveExternalParty($organization->id, $firstContact);
        $sameContactParty = $service->resolveExternalParty($organization->id, $firstContact);
        $sameEmailParty = $service->resolveExternalParty($organization->id, $secondContact);
        $otherOrganizationParty = $service->resolveExternalParty($otherOrganization->id, $otherContact);

        $this->assertSame($firstParty->id, $sameContactParty->id);
        $this->assertSame($firstParty->id, $sameEmailParty->id);
        $this->assertNotSame($firstParty->id, $otherOrganizationParty->id);
    }

    public function test_registered_supplier_creates_and_reuses_registered_party(): void
    {
        $organization = Organization::factory()->create();
        $supplier = Supplier::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Registered Steel LLC',
            'contact_person' => 'Maria Buyer',
            'phone' => '+7 900 200-30-40',
            'email' => 'registered@example.test',
            'tax_number' => '7702000000',
            'is_active' => true,
        ]);

        $service = app(SupplierPartyService::class);

        $party = $service->resolveRegisteredParty($organization->id, $supplier->id);
        $sameParty = $service->resolveRegisteredParty($organization->id, $supplier->id);

        $this->assertSame($party->id, $sameParty->id);
        $this->assertSame('registered', $party->type->value);
        $this->assertSame('linked', $party->status->value);
        $this->assertSame($supplier->id, $party->registered_supplier_id);
        $this->assertNull($party->external_supplier_contact_id);
        $this->assertSame('Registered Steel LLC', $party->display_name);
        $this->assertSame('registered@example.test', $party->normalized_email);
    }

    public function test_registered_supplier_must_belong_to_organization_and_be_active(): void
    {
        $organization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();

        $inactiveSupplier = Supplier::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Inactive Supplier',
            'is_active' => false,
        ]);

        $foreignSupplier = Supplier::query()->create([
            'organization_id' => $otherOrganization->id,
            'name' => 'Foreign Supplier',
            'is_active' => true,
        ]);

        $service = app(SupplierPartyService::class);

        try {
            $service->resolveRegisteredParty($organization->id, $inactiveSupplier->id);
            $this->fail('Inactive supplier should not be resolved.');
        } catch (ModelNotFoundException) {
            $this->assertTrue(true);
        }

        $this->expectException(ModelNotFoundException::class);

        $service->resolveRegisteredParty($organization->id, $foreignSupplier->id);
    }

    public function test_external_party_can_be_linked_to_registered_supplier_inside_same_organization(): void
    {
        $organization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();
        $contact = ExternalSupplierContact::query()->create([
            'organization_id' => $organization->id,
            'name' => 'External Link Candidate',
            'email' => 'candidate@example.test',
            'tax_number' => '7703000000',
        ]);

        $supplier = Supplier::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Registered Link Target',
            'contact_person' => 'Linked Person',
            'phone' => '+7 900 300-40-50',
            'email' => 'target@example.test',
            'tax_number' => '7703999999',
            'is_active' => true,
        ]);

        $foreignSupplier = Supplier::query()->create([
            'organization_id' => $otherOrganization->id,
            'name' => 'Foreign Link Target',
            'is_active' => true,
        ]);

        $service = app(SupplierPartyService::class);
        $party = $service->resolveExternalParty($organization->id, $contact);

        try {
            $service->linkExternalToRegistered($party, $foreignSupplier->id);
            $this->fail('Supplier from another organization should not be linked.');
        } catch (ModelNotFoundException) {
            $this->assertTrue(true);
        }

        $linkedParty = $service->linkExternalToRegistered($party, $supplier->id);

        $this->assertSame($party->id, $linkedParty->id);
        $this->assertSame('external', $linkedParty->type->value);
        $this->assertSame('linked', $linkedParty->status->value);
        $this->assertSame($supplier->id, $linkedParty->registered_supplier_id);
        $this->assertNotNull($linkedParty->linked_at);

        $snapshot = $service->snapshotForDocument($linkedParty);

        $this->assertSame('Registered Link Target', $snapshot['display_name']);
        $this->assertSame('Linked Person', $snapshot['contact_name']);
        $this->assertSame('target@example.test', $snapshot['email']);
        $this->assertSame('7703999999', $snapshot['tax_id']);
        $this->assertSame($supplier->id, $snapshot['registered_supplier_id']);
        $this->assertSame($contact->id, $snapshot['external_supplier_contact_id']);
    }
}
