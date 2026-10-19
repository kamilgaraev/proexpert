<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use App\Models\Supplier;
use App\Modules\Core\AccessController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\Support\CreatesCanonicalProcurementSelection;
use Tests\Support\EnablesImmutableAuditWriter;
use Tests\TestCase;

class SupplierProposalListFilterTest extends TestCase
{
    use CreatesCanonicalProcurementSelection;
    use EnablesImmutableAuditWriter;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableImmutableAuditWriter();
        $this->mock(AccessController::class, function (MockInterface $mock): void {
            $mock->shouldReceive('hasModuleAccess')->andReturn(true);
        });
        $this->mock(AuthorizationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('canAccessInterface', 'can', 'canCurrent', 'hasRole')->andReturn(true);
            $mock->shouldReceive('getUserRoleSlugs')->andReturn(['web_admin']);
            $mock->shouldReceive('getUserRoles')->andReturnUsing(
                static fn (User $user, ?AuthorizationContext $context = null) => $user->roleAssignments()
                    ->where('is_active', true)
                    ->when($context !== null, static fn ($query) => $query->where('context_id', $context->id))
                    ->get()
            );
        });
    }

    public function test_supplier_filter_precedes_pagination_and_combines_with_existing_filters(): void
    {
        $context = AdminApiTestContext::create();
        $request = $this->createCanonicalSelectionSupplierRequest($context->organization, 1000, 'PR-A', 'SR-A');
        $submitted = $this->createCanonicalSelectionProposal($context->organization, $request, 'CP-A1', 100);
        $submitted->forceFill(['created_at' => now()->subMinutes(2)])->save();
        $draft = $this->createCanonicalSelectionProposal($context->organization, $request, 'CP-A2', 200, status: 'draft');
        $otherRequest = $this->createCanonicalSelectionSupplierRequest($context->organization, 1000, 'PR-B', 'SR-B');
        $this->createCanonicalSelectionProposal($context->organization, $otherRequest, 'CP-B1', 300);
        $foreign = AdminApiTestContext::create();
        $foreignRequest = $this->createCanonicalSelectionSupplierRequest($foreign->organization, 1000, 'PR-C', 'SR-C');
        $this->createCanonicalSelectionProposal($foreign->organization, $foreignRequest, 'CP-C1', 400);

        $this->withHeaders($context->authHeaders())->getJson('/api/v1/admin/procurement/proposals')
            ->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('meta.total', 3);

        $query = ['supplier_id' => $request->supplier_id, 'per_page' => 1];
        $first = $this->withHeaders($context->authHeaders())->getJson('/api/v1/admin/procurement/proposals?'.http_build_query($query));
        $first->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 2)->assertJsonPath('meta.last_page', 2);
        $this->assertSame($draft->id, $first->json('data.0.id'));
        $second = $this->withHeaders($context->authHeaders())->getJson('/api/v1/admin/procurement/proposals?'.http_build_query($query + ['page' => 2]));
        $second->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.id', $submitted->id);

        $filtered = $this->withHeaders($context->authHeaders())->getJson('/api/v1/admin/procurement/proposals?'.http_build_query([
            'supplier_id' => $request->supplier_id, 'supplier_request_id' => $request->id, 'status' => 'submitted',
        ]));
        $filtered->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $submitted->id)->assertJsonPath('meta.total', 1);

        $this->withHeaders($context->authHeaders())->getJson('/api/v1/admin/procurement/proposals?'.http_build_query([
            'supplier_id' => $request->supplier_id, 'supplier_request_id' => $otherRequest->id,
        ]))->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0);

        Supplier::query()->whereKey($request->supplier_id)->update(['is_active' => false]);
        $this->withHeaders($context->authHeaders())->getJson('/api/v1/admin/procurement/proposals?'.http_build_query([
            'supplier_id' => $request->supplier_id, 'status' => 'submitted',
        ]))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $submitted->id);
    }

    public function test_foreign_or_unknown_supplier_filters_are_rejected(): void
    {
        $context = AdminApiTestContext::create();
        $request = $this->createCanonicalSelectionSupplierRequest($context->organization, 1000, 'PR-OWN', 'SR-OWN');
        $this->createCanonicalSelectionProposal($context->organization, $request, 'CP-OWN', 100);
        $foreign = AdminApiTestContext::create();
        $foreignRequest = $this->createCanonicalSelectionSupplierRequest($foreign->organization, 1000, 'PR-OTHER', 'SR-OTHER');
        $this->createCanonicalSelectionProposal($foreign->organization, $foreignRequest, 'CP-OTHER', 200);

        $this->withHeaders($context->authHeaders())->getJson('/api/v1/admin/procurement/proposals?'.http_build_query([
            'supplier_id' => $foreignRequest->supplier_id,
        ]))->assertUnprocessable()->assertJsonValidationErrors('supplier_id');
        $this->withHeaders($context->authHeaders())->getJson('/api/v1/admin/procurement/proposals?'.http_build_query([
            'supplier_id' => (int) Supplier::query()->max('id') + 1,
        ]))->assertUnprocessable()->assertJsonValidationErrors('supplier_id');
    }

    public function test_invalid_supplier_filters_are_rejected_instead_of_returning_the_entire_registry(): void
    {
        $context = AdminApiTestContext::create();
        foreach (['not-an-id', 0, -1, ['1']] as $invalidId) {
            $this->withHeaders($context->authHeaders())->getJson('/api/v1/admin/procurement/proposals?'.http_build_query([
                'supplier_id' => $invalidId,
            ]))->assertUnprocessable()->assertJsonValidationErrors('supplier_id');
        }
    }
}
