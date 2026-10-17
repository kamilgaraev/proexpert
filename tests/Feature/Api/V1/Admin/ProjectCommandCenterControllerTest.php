<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Core\Payments\Enums\PaymentMethod;
use App\BusinessModules\Core\Payments\Enums\PaymentTransactionStatus;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Core\Payments\Models\PaymentTransaction;
use App\BusinessModules\Core\Payments\Services\FinancialBalanceQuery;
use App\BusinessModules\Core\Payments\Services\PaymentDocumentForecastQuery;
use App\Enums\ProjectOrganizationRole;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\Organization;
use App\Models\Project;
use App\Services\Project\ProjectParticipantService;
use Illuminate\Support\Facades\DB;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class ProjectCommandCenterControllerTest extends TestCase
{
    public function test_it_requires_a_project_id(): void
    {
        $context = AdminApiTestContext::create();

        $response = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/project-command-center');

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('project_id');
    }

    public function test_it_forbids_a_foreign_project(): void
    {
        $context = AdminApiTestContext::create();
        $foreignOrganization = Organization::factory()->verified()->create();
        $foreignProject = Project::factory()->create([
            'organization_id' => $foreignOrganization->id,
        ]);

        $foreignResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/project-command-center?project_id='.$foreignProject->id);

        $missingResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/project-command-center?project_id=999999999');

        $foreignResponse->assertNotFound();
        $missingResponse->assertNotFound();
        self::assertSame($missingResponse->json(), $foreignResponse->json());
    }

    public function test_it_returns_the_default_project_period_for_a_participant_project(): void
    {
        $ownerContext = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $participantContext = AdminApiTestContext::create(
            organizationAttributes: [
                'capabilities' => ['general_contracting'],
                'primary_business_type' => 'general_contracting',
            ],
            roleSlug: 'organization_owner',
        );
        $project = Project::factory()->create([
            'organization_id' => $ownerContext->organization->id,
            'name' => 'Проект участника',
        ]);

        app(ProjectParticipantService::class)->attach(
            $project,
            $participantContext->organization->id,
            ProjectOrganizationRole::CONTRACTOR,
            $ownerContext->user,
        );

        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $response = $this->withHeaders($participantContext->authHeaders())
                ->getJson('/api/v1/admin/project-command-center?project_id='.$project->id);
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        $systemLookups = array_filter($queries, static fn (array $query): bool => str_starts_with($query['query'], 'select * from "authorization_contexts" where ("type" = ? and "resource_id" is null'));
        self::assertLessThanOrEqual(1, count($systemLookups), 'Organization and project lookups must not each reload the system context');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.project.id', $project->id);
        $response->assertJsonPath('data.period.preset', 'project');
        $response->assertJsonStructure([
            'data' => [
                'project',
                'period',
                'generated_at',
                'problems',
                'finance',
                'delivery',
                'analytics',
            ],
        ]);
    }

    public function test_it_requires_both_dates_for_a_custom_period(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create([
            'organization_id' => $context->organization->id,
        ]);

        $response = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/project-command-center?project_id='.$project->id.'&period=custom');

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['date_from', 'date_to']);
    }

    public function test_it_rejects_custom_period_dates_in_reverse_order(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create([
            'organization_id' => $context->organization->id,
        ]);

        $response = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/project-command-center?project_id='.$project->id.'&period=custom&date_from=2026-08-01&date_to=2026-07-01');

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('date_to');
    }

    public function test_it_rejects_dates_outside_a_custom_period(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create([
            'organization_id' => $context->organization->id,
        ]);

        $response = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/project-command-center?project_id='.$project->id.'&period=month&date_from=2026-07-01&date_to=2026-07-31');

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['date_from', 'date_to']);
    }

    public function test_it_counts_a_request_and_its_order_as_one_outgoing_forecast(): void
    {
        [$context, $project] = $this->linkedForecast();

        $response = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/project-command-center?project_id='.$project->id);
        $response->assertOk()->assertJsonPath('data.finance.cash_flow.accounts_payable', 101000)
            ->assertJsonPath('data.finance.cash_flow.projections.0.outgoing', 101000)
            ->assertJsonPath('data.finance.cash_flow.projections.0.net', -101000);
    }

    public function test_forecast_preserves_parent_and_child_payments_and_refunds(): void
    {
        [$context, $project, $request, $order] = $this->linkedForecast();
        $this->recordForecastTransaction($request, 125);
        $this->recordForecastTransaction($order, 250);
        $this->recordForecastTransaction($order, -25);
        $request->update(['status' => 'partially_paid', 'paid_amount' => 125, 'remaining_amount' => 875]);
        $order->update(['status' => 'partially_paid', 'paid_amount' => 225, 'remaining_amount' => 775]);
        $cashFlow = $this->forecast($context, $project);
        self::assertSame(100650.0, (float) $cashFlow['accounts_payable']);
        self::assertSame(-100650.0, (float) $cashFlow['projections'][0]['net']);
        self::assertSame('100650.00', app(FinancialBalanceQuery::class)
            ->forOrganization($context->organization->id, 'outgoing', $project->id)->debtAmount);

        $this->recordForecastTransaction($request, -50);
        $request->update(['paid_amount' => 75, 'remaining_amount' => 925]);
        self::assertSame(100700.0, (float) $this->forecast($context, $project)['accounts_payable']);
        $this->recordForecastTransaction($request, 925);
        $request->update(['status' => 'paid', 'paid_amount' => 1000, 'remaining_amount' => 0]);
        self::assertSame(100000.0, (float) $this->forecast($context, $project)['accounts_payable']);
    }

    public function test_forecast_keeps_partial_request_residual_and_child_due_dates(): void
    {
        [$context, $project, $request, $order] = $this->linkedForecast('600.00');
        $this->recordForecastTransaction($request, 300);
        $this->recordForecastTransaction($order, 200);
        $request->update(['status' => 'partially_paid', 'paid_amount' => 300, 'remaining_amount' => 700,
            'due_date' => now()->addDays(10)->toDateString()]);
        $order->update(['status' => 'partially_paid', 'paid_amount' => 200, 'remaining_amount' => 400,
            'due_date' => now()->addDays(45)->toDateString()]);
        $cashFlow = $this->forecast($context, $project);
        self::assertSame(100500.0, (float) $cashFlow['accounts_payable']);
        self::assertSame(100100.0, (float) $cashFlow['projections'][0]['outgoing']);
        self::assertSame(400.0, (float) $cashFlow['projections'][1]['outgoing']);
    }

    public function test_forecast_applies_request_credit_to_earlier_orders_then_undated_orders(): void
    {
        [$context, $project, $request, $order] = $this->linkedForecast('600.00');
        $this->recordForecastTransaction($request, 700);
        $request->update(['status' => 'partially_paid', 'paid_amount' => 700, 'remaining_amount' => 300]);
        $order->update(['due_date' => now()->addDays(10)->toDateString()]);
        $later = $order->replicate();
        $later->fill(['document_number' => 'FORECAST-LATER-'.$project->id,
            'amount' => 400, 'remaining_amount' => 400, 'due_date' => now()->addDays(45)->toDateString()])->save();
        $cashFlow = $this->forecast($context, $project);
        self::assertSame(100300.0, (float) $cashFlow['accounts_payable']);
        self::assertSame(100000.0, (float) $cashFlow['projections'][0]['outgoing']);
        self::assertSame(300.0, (float) $cashFlow['projections'][1]['outgoing']);

        $order->update(['due_date' => null]);
        $cashFlow = $this->forecast($context, $project);
        self::assertSame(100300.0, (float) $cashFlow['accounts_payable']);
        self::assertSame(100000.0, (float) $cashFlow['projections'][0]['outgoing']);
        self::assertSame(0.0, (float) $cashFlow['projections'][1]['outgoing']);
    }

    public function test_forecast_preserves_draft_eligibility_and_ignores_inactive_orders(): void
    {
        foreach (['draft', 'approved', 'cancelled', 'rejected', 'deleted', 'paid'] as $state) {
            [$context, $project, $request, $order] = $this->linkedForecast('600.00');
            $request->update(['due_date' => now()->addDays(10)->toDateString()]);
            $order->update(['status' => $state === 'deleted' ? 'approved' : $state,
                'due_date' => now()->addDays(45)->toDateString(),
                'remaining_amount' => $state === 'paid' ? 0 : 600,
                'paid_amount' => $state === 'paid' ? 600 : 0]);
            if ($state === 'deleted') {
                $order->delete();
            } elseif ($state === 'paid') {
                $this->recordForecastTransaction($order, 600);
            }
            $cashFlow = $this->forecast($context, $project);
            $active = in_array($state, ['draft', 'approved', 'paid'], true);
            self::assertSame($active ? 100400.0 : 101000.0, (float) $cashFlow['projections'][0]['outgoing'], $state);
            self::assertSame(in_array($state, ['draft', 'approved'], true) ? 600.0 : 0.0,
                (float) $cashFlow['projections'][1]['outgoing'], $state);
        }
    }

    public function test_forecast_does_not_net_conflicting_or_invisible_orders(): void
    {
        foreach (['payee', 'invoiceable', 'request_type', 'other_project', 'other_org'] as $case) {
            [$context, $project, $request, $order] = $this->linkedForecast();
            if ($case === 'payee') {
                $order->update(['payee_contractor_id' => null]);
            } elseif ($case === 'invoiceable') {
                $order->update(['invoiceable_type' => null, 'invoiceable_id' => null]);
            } elseif ($case === 'request_type') {
                $request->update(['document_type' => 'invoice']);
            } elseif ($case === 'other_project') {
                $order->update(['project_id' => Project::factory()->create(['organization_id' => $context->organization->id])->id]);
            } else {
                $order->update(['organization_id' => Organization::factory()->create()->id]);
            }
            $facts = app(PaymentDocumentForecastQuery::class)->forProject($project->id, $context->organization->id);
            self::assertSame(in_array($case, ['other_project', 'other_org'], true) ? 101000.0 : 102000.0,
                (float) array_sum(array_column($facts, 'amount')), $case);
            if ($case === 'other_org') {
                $query = app(PaymentDocumentForecastQuery::class);
                self::assertSame(102000.0, (float) array_sum(array_column($query->forProject($project->id, null), 'amount')));
                self::assertSame(1000.0, (float) array_sum(array_column($query->forProject($project->id, $order->organization_id), 'amount')));
            }
        }
    }

    public function test_it_limits_contractor_finance_to_its_own_organization(): void
    {
        $ownerContext = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $contractorContext = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $project = Project::factory()->create([
            'organization_id' => $ownerContext->organization->id,
        ]);

        app(ProjectParticipantService::class)->attach(
            $project,
            $contractorContext->organization->id,
            ProjectOrganizationRole::CONTRACTOR,
            $ownerContext->user,
        );

        $otherOrganization = Organization::factory()->verified()->create();
        foreach ([[$contractorContext->organization, 1000, 100, 200], [$otherOrganization, 9000, 900, 1800]] as [$organization, $contractAmount, $paid, $remaining]) {
            $contractor = Contractor::query()->create([
                'organization_id' => $ownerContext->organization->id,
                'source_organization_id' => $organization->id,
                'name' => 'Contractor '.$organization->id,
                'contractor_type' => 'invited_organization',
                'connected_at' => now(),
            ]);
            $contract = Contract::query()->create([
                'organization_id' => $ownerContext->organization->id,
                'project_id' => $project->id,
                'contractor_id' => $contractor->id,
                'contract_side_type' => 'general_contractor_to_contractor',
                'number' => 'C-'.$organization->id,
                'date' => now()->toDateString(),
                'base_amount' => $contractAmount,
                'total_amount' => $contractAmount,
                'gp_percentage' => 0,
                'is_fixed_amount' => true,
                'is_multi_project' => false,
                'status' => 'active',
            ]);
            PaymentDocument::query()->create([
                'organization_id' => $organization->id,
                'project_id' => $project->id,
                'document_type' => 'invoice',
                'document_number' => 'PD-'.$contract->id,
                'document_date' => now()->toDateString(),
                'direction' => 'incoming',
                'invoiceable_type' => Contract::class,
                'invoiceable_id' => $contract->id,
                'amount' => $paid + $remaining,
                'paid_amount' => $paid,
                'remaining_amount' => $remaining,
                'status' => 'submitted',
                'due_date' => now()->addDays(7)->toDateString(),
            ]);
        }

        $response = $this->withHeaders($contractorContext->authHeaders())
            ->getJson('/api/v1/admin/project-command-center?project_id='.$project->id);

        $response->assertOk();
        $response->assertJsonPath('data.finance.available', true);
        self::assertSame(1000.0, (float) $response->json('data.finance.evm.metrics.bac'));
        self::assertSame(100.0, (float) $response->json('data.finance.evm.metrics.ac'));
        self::assertSame(1000.0, (float) $response->json('data.finance.margin.contracted_revenue'));
        self::assertSame(200.0, (float) $response->json('data.finance.cash_flow.accounts_receivable'));
        self::assertSame(0.0, (float) $response->json('data.finance.cash_flow.accounts_payable'));
    }

    private function forecast(AdminApiTestContext $context, Project $project): array
    {
        return $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/project-command-center?project_id='.$project->id)
            ->assertOk()->json('data.finance.cash_flow');
    }

    private function recordForecastTransaction(PaymentDocument $document, float $amount): void
    {
        PaymentTransaction::query()->create([
            'payment_document_id' => $document->id, 'organization_id' => $document->organization_id,
            'project_id' => $document->project_id, 'amount' => $amount, 'currency' => 'RUB',
            'payment_method' => PaymentMethod::BANK_TRANSFER, 'transaction_date' => now()->toDateString(),
            'status' => $amount < 0 ? PaymentTransactionStatus::REFUNDED : PaymentTransactionStatus::COMPLETED,
        ]);
    }

    private function linkedForecast(string $orderAmount = '1000.00'): array
    {
        $context = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $contractor = Contractor::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Forecast contractor',
            'contractor_type' => 'manual',
        ]);
        $contract = Contract::query()->create([
            'organization_id' => $context->organization->id, 'project_id' => $project->id,
            'contractor_id' => $contractor->id, 'number' => 'FORECAST-LINKED',
            'date' => now()->toDateString(), 'total_amount' => 100000,
        ]);
        $identity = [
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'document_date' => now()->toDateString(),
            'direction' => 'outgoing', 'currency' => 'RUB',
            'payer_organization_id' => $context->organization->id,
            'payee_contractor_id' => $contractor->id, 'contractor_id' => $contractor->id,
            'invoiceable_type' => Contract::class, 'invoiceable_id' => $contract->id,
            'status' => 'approved', 'paid_amount' => 0,
            'due_date' => now()->addDays(7)->toDateString(),
        ];
        PaymentDocument::query()->create([
            ...$identity, 'document_type' => 'invoice', 'document_number' => 'FORECAST-INVOICE-'.$project->id,
            'amount' => 100000, 'remaining_amount' => 100000,
        ]);
        $request = PaymentDocument::query()->create([
            ...$identity, 'document_type' => 'payment_request', 'document_number' => 'FORECAST-REQUEST-'.$project->id,
            'amount' => 1000, 'remaining_amount' => 1000,
            'source_type' => Contract::class, 'source_id' => $contract->id,
        ]);
        $order = PaymentDocument::query()->create([
            ...$identity, 'document_type' => 'payment_order', 'document_number' => 'FORECAST-ORDER-'.$project->id,
            'amount' => $orderAmount, 'remaining_amount' => $orderAmount,
            'source_type' => PaymentDocument::class, 'source_id' => $request->id,
        ]);

        return [$context, $project, $request, $order];
    }
}
