<?php

declare(strict_types=1);

namespace Tests\Feature\Contract;

use App\Enums\Contract\ContractSideTypeEnum;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Supplier;
use App\Services\Contract\ContractSideResolverService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class ContractCanonicalCompatibilityTest extends TestCase
{
    #[DataProvider('sideTypes')]
    public function test_saved_legacy_and_canonical_sides_remain_readable_and_editable(string $stored, string $canonical, bool $supply): void
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $contractor = Contractor::create(['organization_id' => $organization->id, 'name' => 'Executor']);
        $supplier = Supplier::create(['organization_id' => $organization->id, 'name' => 'Supplier']);
        $id = DB::table('contracts')->insertGetId([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'contractor_id' => $supply ? null : $contractor->id,
            'supplier_id' => $supply ? $supplier->id : null,
            'contract_side_type' => $stored,
            'number' => 'COMPAT-1',
            'date' => '2026-09-14',
            'status' => 'draft',
            'total_amount' => 100,
            'is_self_execution' => false,
        ]);

        $contract = Contract::findOrFail($id);
        $side = app(ContractSideResolverService::class)->resolve($contract);
        self::assertSame($canonical, $side['type']);
        $contract->contract_side_type = ContractSideTypeEnum::from($canonical);
        $contract->save();
        self::assertSame($canonical, $contract->fresh()->getRawOriginal('contract_side_type'));

        try {
            DB::transaction(fn () => DB::table('contracts')->where('id', $id)->update([
                'supplier_id' => $supply ? null : $supplier->id,
            ]));
            self::fail('The database accepted incompatible contract parties.');
        } catch (\Illuminate\Database\QueryException $exception) {
            self::assertSame('23514', $exception->errorInfo[0]);
        }
    }

    public static function sideTypes(): array
    {
        return [
            ['customer_to_general_contractor', 'general_contract', false],
            ['general_contract', 'general_contract', false],
            ['general_contractor_to_contractor', 'contract', false],
            ['contract', 'contract', false],
            ['general_contractor_to_supplier', 'general_contractor_supply', true],
            ['general_contractor_supply', 'general_contractor_supply', true],
            ['contractor_to_subcontractor', 'subcontract', false],
            ['subcontract', 'subcontract', false],
            ['contractor_to_supplier', 'contractor_supply', true],
            ['contractor_supply', 'contractor_supply', true],
            ['subcontractor_to_supplier', 'subcontractor_supply', true],
            ['subcontractor_supply', 'subcontractor_supply', true],
        ];
    }
}
