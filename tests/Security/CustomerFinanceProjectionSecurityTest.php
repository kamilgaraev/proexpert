<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Enums\Contract\ContractSideTypeEnum;
use App\Enums\Contract\ContractStatusEnum;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Customer\CustomerPortalService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

final class CustomerFinanceProjectionSecurityTest extends TestCase
{
    public function test_observer_project_and_workspace_do_not_return_finance_projection(): void
    {
        [$organization, $project] = $this->financeFixture();
        $observer = $this->customer($organization, 'customer_observer');
        $this->login($observer);

        self::assertFalse(app(AuthorizationService::class)->can($observer, 'customer.finance.view', [
            'organization_id' => $organization->id,
        ]));
        $this->getJson('/api/v1/customer/finance/summary')->assertForbidden();
        $this->getJson('/api/v1/customer/projects/'.$project->id.'/finance')->assertForbidden();
        $this->getJson('/api/v1/customer/dashboard')->assertOk()->assertJsonPath('data.finance_summary', null);

        $show = $this->getJson('/api/v1/customer/projects/'.$project->id)->assertOk();
        $workspace = $this->getJson('/api/v1/customer/projects/'.$project->id.'/workspace')->assertOk();

        $show->assertJsonPath('data.project.finance_summary', null);
        $workspace->assertJsonPath('data.workspace.project.finance_summary', null);
        $show->assertJsonPath('data.project.name', $project->name);
        self::assertNotEmpty($show->json('data.project.budgetLabel'));
        self::assertNotEmpty($show->json('data.project.key_contracts'));
        self::assertNotNull($show->json('data.project.problem_flags'));
        self::assertNotNull($workspace->json('data.workspace.risk_center'));
    }

    public function test_observer_contract_projections_redact_ledger_and_payment_history(): void
    {
        [$organization, $project, $contract] = $this->financeFixture();
        $this->login($this->customer($organization, 'customer_observer'));

        $registry = $this->getJson('/api/v1/customer/contracts')->assertOk();
        $projectRegistry = $this->getJson('/api/v1/customer/projects/'.$project->id.'/contracts')->assertOk();
        $detail = $this->getJson('/api/v1/customer/contracts/'.$contract->id)->assertOk();

        foreach (['invoiced_amount', 'paid_amount', 'refunded_amount', 'debt_amount', 'overpayment_amount'] as $field) {
            $registry->assertJsonPath('data.items.0.'.$field, null);
            $projectRegistry->assertJsonPath('data.items.0.'.$field, null);
            $detail->assertJsonPath('data.contract.'.$field, null);
            $detail->assertJsonPath('data.contract.financial_summary.'.$field, null);
        }
        $detail->assertJsonPath('data.contract.financial_summary.advance_amount', null);
        $detail->assertJsonPath('data.contract.payments_summary', null);
        $detail->assertJsonPath('data.contract.total_amount', '100000.00');
        $detail->assertJsonPath('data.contract.financial_summary.planned_advance_amount', '120.00');
        self::assertArrayHasKey('agreements_summary', $detail->json('data.contract'));
        self::assertArrayHasKey('acts_summary', $detail->json('data.contract'));
        self::assertNotContains('payment', array_column($detail->json('data.contract.timeline'), 'type'));
    }

    public function test_financier_retains_exact_ledger_values_in_all_projections(): void
    {
        [$organization, $project, $contract] = $this->financeFixture();
        $financier = $this->customer($organization, 'customer_financier');
        $this->login($financier);
        self::assertTrue(app(AuthorizationService::class)->can($financier, 'customer.finance.view', [
            'organization_id' => $organization->id,
        ]));

        foreach (['/finance/summary', '/projects/'.$project->id.'/finance'] as $path) {
            $this->getJson('/api/v1/customer'.$path)->assertOk()
                ->assertJsonPath('data.summary.totals.paid_amount', '300.00')
                ->assertJsonPath('data.summary.totals.refunded_amount', '50.00')
                ->assertJsonPath('data.summary.totals.debt_amount', '250.00');
        }
        $this->getJson('/api/v1/customer/projects/'.$project->id)->assertOk()
            ->assertJsonPath('data.project.finance_summary.totals.paid_amount', '300.00');
        $this->getJson('/api/v1/customer/projects/'.$project->id.'/workspace')->assertOk()
            ->assertJsonPath('data.workspace.project.finance_summary.totals.paid_amount', '300.00');
        foreach (['/contracts', '/projects/'.$project->id.'/contracts'] as $path) {
            $this->getJson('/api/v1/customer'.$path)->assertOk()
                ->assertJsonPath('data.items.0.paid_amount', '300.00')
                ->assertJsonPath('data.items.0.refunded_amount', '50.00')
                ->assertJsonPath('data.items.0.debt_amount', '250.00');
        }
        $this->getJson('/api/v1/customer/contracts/'.$contract->id)->assertOk()
            ->assertJsonPath('data.contract.financial_summary.paid_amount', '300.00')
            ->assertJsonPath('data.contract.financial_summary.advance_amount', '90.00')
            ->assertJsonPath('data.contract.payments_summary.count', 1)
            ->assertJsonPath('data.contract.payments_summary.items.0.reference', 'PRIVATE-PAYMENT-REFERENCE');
    }

    public function test_current_finance_revocation_redacts_existing_native_session_without_cache_flush(): void
    {
        [$organization, $project, $contract] = $this->financeFixture();
        $user = $this->customer($organization, 'customer_observer');
        $context = AuthorizationContext::getOrganizationContext($organization->id);
        UserRoleAssignment::assignRole($user, 'customer_financier', $context);
        $this->login($user);

        $this->getJson('/api/v1/customer/projects/'.$project->id)->assertOk()
            ->assertJsonPath('data.project.finance_summary.totals.paid_amount', '300.00');
        $this->getJson('/api/v1/customer/finance/summary')->assertOk();
        $this->getJson('/api/v1/customer/contracts/'.$contract->id)->assertOk()
            ->assertJsonPath('data.contract.paid_amount', '300.00');

        self::assertTrue(app(AuthorizationService::class)->revokeRole($user, 'customer_financier', $context));

        $this->getJson('/api/v1/customer/finance/summary')->assertForbidden();
        $this->getJson('/api/v1/customer/projects/'.$project->id.'/finance')->assertForbidden();
        $this->getJson('/api/v1/customer/dashboard')->assertOk()->assertJsonPath('data.finance_summary', null);
        $this->getJson('/api/v1/customer/projects/'.$project->id)->assertOk()
            ->assertJsonPath('data.project.finance_summary', null);
        $this->getJson('/api/v1/customer/projects/'.$project->id.'/workspace')->assertOk()
            ->assertJsonPath('data.workspace.project.finance_summary', null);
        $this->getJson('/api/v1/customer/contracts')->assertOk()->assertJsonPath('data.items.0.paid_amount', null);
        $this->getJson('/api/v1/customer/contracts/'.$contract->id)->assertOk()
            ->assertJsonPath('data.contract.financial_summary.advance_amount', null)
            ->assertJsonPath('data.contract.payments_summary', null);
    }

    public function test_actorless_and_denied_service_calls_cannot_reuse_loaded_payment_history(): void
    {
        [$organization, $project, $contract] = $this->financeFixture();
        $observer = $this->customer($organization, 'customer_observer');
        $financier = $this->customer($organization, 'customer_financier');
        $service = app(CustomerPortalService::class);
        $contract->load('payments');
        $contract->payments->first()->setAttribute('payment_date', now());

        $allowed = $service->getContract($organization->id, $contract, $financier);
        self::assertSame(1, $allowed['contract']['payments_summary']['count']);
        self::assertContains('payment', array_column($allowed['contract']['timeline'], 'type'));

        foreach ([null, $observer] as $actor) {
            self::assertNull($service->getFinanceSummary($organization->id, $actor)['summary']);
            self::assertNull($service->getProjectFinanceSummary($organization->id, $project, $actor)['summary']);
            self::assertNull($service->getProject($organization->id, $project, $actor)['project']['finance_summary']);
            self::assertNull($service->getContracts($organization->id, [], null, $actor)['items'][0]['paid_amount']);
            $denied = $service->getContract($organization->id, $contract, $actor);
            self::assertNull($denied['contract']['paid_amount']);
            self::assertNull($denied['contract']['financial_summary']['advance_amount']);
            self::assertNull($denied['contract']['payments_summary']);
            self::assertNotContains('payment', array_column($denied['contract']['timeline'], 'type'));
        }
    }

    public function test_finance_permission_keeps_current_project_and_tenant_boundaries(): void
    {
        [$organization, $project, $contract] = $this->financeFixture();
        $user = $this->customer($organization, 'customer_financier');
        $user->organizations()->updateExistingPivot($organization->id, ['project_access_mode' => 'assigned_projects']);
        $project->users()->attach($user->id, ['role' => 'member', 'is_active' => true, 'assigned_at' => now()]);
        $unassigned = Project::factory()->create(['organization_id' => $organization->id]);
        $foreign = Project::factory()->create();
        $this->login($user);

        $this->getJson('/api/v1/customer/projects/'.$project->id.'/finance')->assertOk();
        foreach ([$unassigned, $foreign] as $deniedProject) {
            foreach (['', '/workspace', '/finance', '/contracts'] as $suffix) {
                $this->getJson('/api/v1/customer/projects/'.$deniedProject->id.$suffix)->assertNotFound();
            }
        }
        $project->users()->updateExistingPivot($user->id, ['is_active' => false]);
        $this->getJson('/api/v1/customer/projects/'.$project->id)->assertNotFound();
        $this->getJson('/api/v1/customer/projects/'.$project->id.'/finance')->assertNotFound();
        $this->getJson('/api/v1/customer/contracts/'.$contract->id)->assertNotFound();
        $this->getJson('/api/v1/customer/contracts')->assertOk()->assertJsonCount(0, 'data.items');
    }

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Cache::clear();
        config(['web_auth.keys.customer' => str_repeat('c', 64)]);
    }

    private function customer(Organization $organization, string $role): User
    {
        $user = User::factory()->create([
            'password' => Hash::make('Password1'),
            'current_organization_id' => $organization->id,
            'is_active' => true,
        ]);
        $user->organizations()->attach($organization->id, [
            'is_active' => true,
            'is_owner' => false,
            'project_access_mode' => 'all_projects',
        ]);
        UserRoleAssignment::assignRole($user, $role, AuthorizationContext::getOrganizationContext($organization->id));

        return $user->fresh();
    }

    private function login(User $user): void
    {
        auth()->forgetGuards();
        $this->withHeaders([
            'Origin' => (string) config('web_auth.origins.customer.0'),
            'Authorization' => '',
        ]);
        $login = $this->postJson('/api/v1/customer/auth/login', [
            'email' => $user->email,
            'password' => 'Password1',
        ])->assertOk();
        $this->withHeader('Authorization', 'Bearer '.(string) $login->json('data.token'));
    }

    private function financeFixture(): array
    {
        $organization = Organization::factory()->verified()->create();
        $project = Project::factory()->create([
            'organization_id' => $organization->id,
            'name' => 'Customer finance projection',
            'budget_amount' => '200000.00',
        ]);
        $contractor = Contractor::create([
            'organization_id' => $organization->id,
            'name' => 'Customer finance contractor',
            'contractor_type' => Contractor::TYPE_MANUAL,
        ]);
        $contract = Contract::create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'contractor_id' => $contractor->id,
            'contract_side_type' => ContractSideTypeEnum::GENERAL_CONTRACT->value,
            'number' => 'CUSTOMER-FINANCE-001',
            'date' => now()->toDateString(),
            'subject' => 'Customer project contract',
            'total_amount' => '100000.00',
            'actual_advance_amount' => '90.00',
            'planned_advance_amount' => '120.00',
            'status' => ContractStatusEnum::ACTIVE->value,
            'is_fixed_amount' => true,
            'is_multi_project' => false,
            'is_self_execution' => false,
        ]);
        $documentId = DB::table('payment_documents')->insertGetId([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'document_type' => 'invoice',
            'document_number' => 'CUSTOMER-FINANCE-INVOICE',
            'document_date' => now()->toDateString(),
            'invoiceable_type' => Contract::class,
            'invoiceable_id' => $contract->id,
            'amount' => '500.00',
            'currency' => 'RUB',
            'vat_amount' => '83.33',
            'vat_rate' => '20.00',
            'amount_without_vat' => '416.67',
            'paid_amount' => '250.00',
            'remaining_amount' => '250.00',
            'status' => 'partially_paid',
            'metadata' => json_encode(['reference_document_number' => 'PRIVATE-PAYMENT-REFERENCE']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $transaction = [
            'payment_document_id' => $documentId,
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'amount' => '300.00',
            'currency' => 'RUB',
            'payment_method' => 'bank_transfer',
            'transaction_date' => now()->toDateString(),
            'status' => 'completed',
            'created_at' => now(),
            'updated_at' => now(),
        ];
        $transactionId = DB::table('payment_transactions')->insertGetId($transaction);
        DB::table('payment_transactions')->insert(array_replace($transaction, [
            'amount' => '-50.00',
            'reverses_transaction_id' => $transactionId,
        ]));

        return [$organization, $project, $contract];
    }
}
