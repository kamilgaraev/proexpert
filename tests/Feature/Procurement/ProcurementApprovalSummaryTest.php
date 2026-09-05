<?php

declare(strict_types=1);

namespace Tests\Feature\Procurement;

use App\BusinessModules\Features\Procurement\Http\Controllers\ProcurementApprovalController;
use App\BusinessModules\Features\Procurement\Models\ProcurementApproval;
use App\BusinessModules\Features\Procurement\Models\SupplierProposalDecision;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\Request;
use Tests\TestCase;

final class ProcurementApprovalSummaryTest extends TestCase
{
    public function test_summary_ignores_status_and_pagination_but_preserves_organization_and_reason_scope(): void
    {
        $organization = Organization::factory()->create();
        $foreign = Organization::factory()->create();
        $actor = User::factory()->create(['current_organization_id' => $organization->id]);
        $sequence = 0;
        foreach ([
            [$organization->id, 'budget_exceeded', 'pending', 4],
            [$organization->id, 'budget_exceeded', 'approved', 2],
            [$organization->id, 'budget_exceeded', 'rejected', 1],
            [$organization->id, 'budget_exceeded', 'cancelled', 1],
            [$organization->id, 'non_lowest_price', 'approved', 3],
            [$foreign->id, 'budget_exceeded', 'pending', 2],
        ] as [$organizationId, $reason, $status, $count]) {
            for ($index = 0; $index < $count; $index++) {
                ProcurementApproval::query()->create([
                    'organization_id' => $organizationId,
                    'approvable_type' => SupplierProposalDecision::class,
                    'approvable_id' => ++$sequence,
                    'reason_code' => $reason,
                    'status' => $status,
                    'requested_at' => now(),
                ]);
            }
        }

        $request = Request::create('/approvals', 'GET', [
            'status' => 'pending', 'reason_code' => 'budget_exceeded', 'per_page' => 1,
        ]);
        $request->attributes->set('current_organization_id', $organization->id);
        $request->setUserResolver(static fn () => $actor);
        $response = app(ProcurementApprovalController::class)->index($request);
        $body = $response->getData(true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(1, $body['data']);
        $this->assertSame(4, $body['meta']['total']);
        $this->assertSame(['pending' => 4, 'approved' => 2, 'rejected' => 1, 'cancelled' => 1], $body['summary'] ?? null);
    }
}
