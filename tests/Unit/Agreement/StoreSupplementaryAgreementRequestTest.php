<?php

declare(strict_types=1);

namespace Tests\Unit\Agreement;

use App\Http\Requests\Api\V1\Admin\Agreement\StoreSupplementaryAgreementRequest;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\Organization;
use App\Models\SupplementaryAgreement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class StoreSupplementaryAgreementRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_superseded_agreements_must_belong_to_same_contract(): void
    {
        $organization = Organization::factory()->create();
        $contractor = Contractor::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Contractor',
            'inn' => '7700000001',
        ]);
        $contract = $this->createContract($organization->id, $contractor->id, 'C-1');
        $foreignContract = $this->createContract($organization->id, $contractor->id, 'C-2');
        $foreignAgreement = SupplementaryAgreement::query()->create([
            'contract_id' => $foreignContract->id,
            'number' => 'DS-FOREIGN',
            'agreement_date' => '2026-05-12',
            'change_amount' => 1000,
            'subject_changes' => ['Foreign scope'],
        ]);

        $request = StoreSupplementaryAgreementRequest::create('/agreements', 'POST', [
            'contract_id' => $contract->id,
        ]);

        $validator = Validator::make([
            'contract_id' => $contract->id,
            'number' => 'DS-1',
            'agreement_date' => '2026-05-12',
            'change_amount' => 100,
            'supersede_agreement_ids' => [$foreignAgreement->id],
            'subject_changes' => ['Scope update'],
        ], $request->rules(), $request->messages());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('supersede_agreement_ids.0', $validator->errors()->toArray());
    }

    private function createContract(int $organizationId, int $contractorId, string $number): Contract
    {
        return Contract::query()->create([
            'organization_id' => $organizationId,
            'contractor_id' => $contractorId,
            'number' => $number,
            'date' => '2026-05-12',
            'subject' => 'Works',
            'total_amount' => 10000,
            'status' => 'draft',
        ]);
    }
}
