<?php

declare(strict_types=1);

namespace Tests\Feature\BusinessModules\Core\Payments;

use App\BusinessModules\Core\Payments\DTOs\PaymentCalendarSourceFilters;
use App\BusinessModules\Core\Payments\Enums\InvoiceDirection;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentType;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Core\Payments\Services\PaymentCalendarSourceService;
use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\Support\PortfolioDecimal;
use App\Models\Organization;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class PaymentCalendarRequestOrderNettingTest extends TestCase
{
    use RefreshDatabase;

    public function test_approved_order_replaces_its_overdue_request_in_forecast(): void
    {
        Queue::fake();
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $payee = Organization::factory()->verified()->create();
        $shared = [
            'organization_id' => $context->organization->id, 'project_id' => $project->id,
            'payer_organization_id' => $context->organization->id, 'payee_organization_id' => $payee->id,
            'direction' => InvoiceDirection::OUTGOING, 'status' => PaymentDocumentStatus::APPROVED,
            'amount' => '100000.00', 'paid_amount' => '0.00', 'remaining_amount' => '100000.00',
            'currency' => 'RUB', 'created_by_user_id' => $context->user->id,
        ];
        $request = PaymentDocument::query()->create($shared + [
            'document_type' => PaymentDocumentType::PAYMENT_REQUEST,
            'document_number' => 'УЧ-ЗАЯВКА-'.uniqid(), 'document_date' => '2026-09-01', 'due_date' => '2026-09-20',
        ]);
        $order = PaymentDocument::query()->create($shared + [
            'document_type' => PaymentDocumentType::PAYMENT_ORDER,
            'document_number' => 'УЧ-ПОРУЧЕНИЕ-'.uniqid(), 'document_date' => '2026-10-01', 'due_date' => '2026-10-20',
            'source_type' => PaymentDocument::class, 'source_id' => $request->id,
        ]);

        $items = app(PaymentCalendarSourceService::class)->collect(new PaymentCalendarSourceFilters(
            organizationId: $context->organization->id, periodStart: '2026-10-01', periodEnd: '2026-10-31',
            projectId: $project->id, currency: 'RUB', direction: 'outflow',
        ), CarbonImmutable::parse('2026-10-15'));
        $total = '0.00';
        foreach ($items as $item) {
            $total = PortfolioDecimal::add($total, $item->remainingAmount);
        }

        $this->assertSame('100000.00', $total);
        $this->assertSame([$order->id], array_column($items, 'sourceId'));
        $this->assertSame('100000.00', $request->fresh()->remaining_amount);
        $this->assertSame('100000.00', $order->fresh()->remaining_amount);
    }
}
