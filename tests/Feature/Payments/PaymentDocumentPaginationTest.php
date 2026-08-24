<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\BusinessModules\Core\Payments\Services\PaymentDocumentQueryService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PaymentDocumentPaginationTest extends TestCase
{
    public function refreshDatabase(): void
    {
        Schema::dropIfExists('payment_documents');
        Schema::create('payment_documents', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('payer_organization_id')->nullable();
            $table->unsignedBigInteger('payee_organization_id')->nullable();
            $table->unsignedBigInteger('payer_contractor_id')->nullable();
            $table->unsignedBigInteger('payee_contractor_id')->nullable();
            $table->uuid('budget_article_id')->nullable();
            $table->uuid('responsibility_center_id')->nullable();
            $table->string('document_type');
            $table->string('document_number');
            $table->date('document_date');
            $table->decimal('amount', 18, 2);
            $table->decimal('paid_amount', 18, 2)->default(0);
            $table->decimal('remaining_amount', 18, 2);
            $table->string('currency')->default('RUB');
            $table->decimal('vat_rate', 5, 2)->default(0);
            $table->decimal('vat_amount', 18, 2)->default(0);
            $table->decimal('amount_without_vat', 18, 2)->default(0);
            $table->string('status');
            $table->timestamps();
            $table->softDeletes();
        });

        foreach ([1, 2, 3] as $number) {
            DB::table('payment_documents')->insert([
                'organization_id' => 5,
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
    }

    public function test_list_returns_real_pages_without_duplicates(): void
    {
        $query = app(PaymentDocumentQueryService::class);
        $first = $query->listForOrganization(5, [
            'per_page' => 2,
            'page' => 1,
            'sort_by' => 'created_at',
            'sort_order' => 'asc',
        ]);
        $second = $query->listForOrganization(5, [
            'per_page' => 2,
            'page' => 2,
            'sort_by' => 'created_at',
            'sort_order' => 'asc',
        ]);

        self::assertInstanceOf(LengthAwarePaginator::class, $first);
        self::assertSame(3, $first->total());
        self::assertSame(['PAGE-1', 'PAGE-2'], $first->getCollection()->pluck('document_number')->all());
        self::assertSame(['PAGE-3'], $second->getCollection()->pluck('document_number')->all());
    }
}
