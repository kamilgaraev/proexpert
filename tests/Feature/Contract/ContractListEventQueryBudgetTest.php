<?php

declare(strict_types=1);

namespace Tests\Feature\Contract;

use App\Enums\Contract\ContractStateEventTypeEnum;
use App\Http\Resources\Api\V1\Admin\Contract\ContractResource;
use App\Models\Contract;
use App\Models\Organization;
use App\Models\Project;
use App\Models\SupplementaryAgreement;
use App\Repositories\ContractRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ContractListEventQueryBudgetTest extends TestCase
{
    public function test_list_event_queries_are_bounded_and_supersession_is_fresh_and_scoped(): void
    {
        $owner = Organization::factory()->create();
        $other = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $owner->id]);
        $otherProject = Project::factory()->create(['organization_id' => $other->id]);
        $eventContract = $this->contract((int) $owner->id, (int) $project->id, 'EVENT-LIST', 1300);
        $legacy = $this->contract((int) $owner->id, (int) $project->id, 'LEGACY-LIST', 100);
        $foreign = $this->contract((int) $other->id, (int) $otherProject->id, 'FOREIGN-LIST', 9999);
        $this->event($eventContract, ContractStateEventTypeEnum::CREATED, 1000);
        $old = $this->event($eventContract, ContractStateEventTypeEnum::SUPPLEMENTARY_AGREEMENT_CREATED, 500);
        $replacement = $this->event($eventContract, ContractStateEventTypeEnum::AMENDED, 300, $old);
        for ($i = 0; $i < 25; $i++) {
            $this->event($eventContract, ContractStateEventTypeEnum::PAYMENT_CREATED, 100);
        }
        $this->event($foreign, ContractStateEventTypeEnum::CREATED, 9999);
        $repository = app(ContractRepository::class);
        $filters = ['project_id' => $project->id];
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $page = $repository->getContractsForOrganizationPaginated((int) $owner->id, 15, $filters);
            $eventQueries = $this->eventQueries();
            self::assertCount(1, $eventQueries);
            self::assertCount(2, $page->items());
            self::assertNotContains($foreign->id, $page->getCollection()->modelKeys());
            $loaded = $page->getCollection()->firstWhere('id', $eventContract->id);
            self::assertNotNull($loaded);
            self::assertTrue((bool) $loaded->stateEvents->firstWhere('id', $old)->getAttribute('superseded_by_events_exists'));
            self::assertFalse((bool) $loaded->stateEvents->firstWhere('id', $replacement)->getAttribute('superseded_by_events_exists'));
            DB::flushQueryLog();
            $request = Request::create('/api/v1/admin/projects/'.$project->id.'/contracts', 'GET');
            $request->attributes->set('current_organization_id', $owner->id);
            $payloads = $page->getCollection()->mapWithKeys(static fn (Contract $contract) => [
                $contract->id => (new ContractResource($contract))->resolve($request),
            ]);
            self::assertCount(0, $this->eventQueries());
            self::assertSame(1300.0, $payloads[$eventContract->id]['total_amount']);
            self::assertSame(300, (int) $payloads[$eventContract->id]['financial_summary']['agreements_total_change']);
            self::assertSame(100.0, $payloads[$legacy->id]['total_amount']);
            $this->event($eventContract, ContractStateEventTypeEnum::SUPERSEDED, 0, $replacement);
            $fresh = $repository->getContractsForOrganizationPaginated((int) $owner->id, 15, $filters)
                ->getCollection()->firstWhere('id', $eventContract->id);
            self::assertNotNull($fresh);
            self::assertSame(1000.0, (new ContractResource($fresh))->resolve($request)['total_amount']);
            self::assertSame(0, $repository->getContractsForOrganizationPaginated((int) $other->id, 15, $filters)->total());
        } finally {
            DB::disableQueryLog();
        }
    }

    private function contract(int $organizationId, int $projectId, string $number, int $amount): Contract
    {
        $contract = Contract::create([
            'organization_id' => $organizationId, 'project_id' => $projectId, 'number' => $number,
            'date' => '2026-10-07', 'status' => 'active', 'base_amount' => $amount === 1300 ? 1000 : $amount,
            'total_amount' => $amount, 'is_fixed_amount' => true,
        ]);
        $contract->stateEvents()->delete();

        return $contract;
    }

    private function event(Contract $contract, ContractStateEventTypeEnum $type, int $amount, ?int $supersedes = null): int
    {
        return (int) DB::table('contract_state_events')->insertGetId([
            'contract_id' => $contract->id, 'event_type' => $type->value, 'amount_delta' => $amount,
            'triggered_by_type' => $type === ContractStateEventTypeEnum::CREATED ? Contract::class : SupplementaryAgreement::class,
            'supersedes_event_id' => $supersedes, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function eventQueries(): array
    {
        return array_values(array_filter(DB::getQueryLog(), static fn (array $query) => str_contains($query['query'], 'contract_state_events')));
    }
}
