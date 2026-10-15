<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\BusinessModules\Core\Payments\Services\PaymentDocumentQueryService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

class PaymentDocumentPaginationTest extends TestCase
{
    public function test_list_returns_real_pages_without_duplicates(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create();
        $actor = $fixture->addMember(['payments' => ['payments.invoice.view']]);
        foreach ([1, 2, 3] as $number) {
            DB::table('payment_documents')->insert([
                'organization_id' => $fixture->organization->id,
                'document_type' => 'invoice',
                'document_number' => "PAGE-{$number}",
                'document_date' => now()->toDateString(),
                'amount' => '100.00',
                'remaining_amount' => '100.00',
                'status' => 'approved',
                'created_at' => now()->addSeconds($number),
                'updated_at' => now(),
            ]);
        }

        $query = app(PaymentDocumentQueryService::class);
        $first = $query->listForOrganization($fixture->organization->id, [
            'per_page' => 2,
            'page' => 1,
            'sort_by' => 'created_at',
            'sort_order' => 'asc',
        ], $actor);
        $second = $query->listForOrganization($fixture->organization->id, [
            'per_page' => 2,
            'page' => 2,
            'sort_by' => 'created_at',
            'sort_order' => 'asc',
        ], $actor);

        self::assertInstanceOf(LengthAwarePaginator::class, $first);
        self::assertSame(3, $first->total());
        self::assertSame(['PAGE-1', 'PAGE-2'], $first->getCollection()->pluck('document_number')->all());
        self::assertSame(['PAGE-3'], $second->getCollection()->pluck('document_number')->all());
    }
}
