<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\BusinessModules\Core\Payments\Services\PaymentValidationService;
use App\Models\Contract;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Tests\TestCase;

class PaymentContractLimitValidationTest extends TestCase
{
    public function refreshDatabase(): void
    {
        Schema::dropIfExists('payment_documents');
        Schema::dropIfExists('contracts');

        Schema::create('contracts', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('project_id')->nullable();
            $table->string('number');
            $table->date('date');
            $table->decimal('total_amount', 18, 2);
            $table->decimal('planned_advance_amount', 18, 2)->nullable();
            $table->string('status');
            $table->boolean('is_fixed_amount')->default(true);
            $table->boolean('is_multi_project')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('payment_documents', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('invoiceable_type')->nullable();
            $table->unsignedBigInteger('invoiceable_id')->nullable();
            $table->string('invoice_type')->nullable();
            $table->decimal('amount', 18, 2);
            $table->string('status');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Contract::flushEventListeners();
    }

    public function test_contract_limit_counts_only_effective_documents_and_blocks_final_overpayment(): void
    {
        $contract = Contract::query()->create([
            'organization_id' => 10,
            'number' => 'FIN-018',
            'date' => now()->toDateString(),
            'total_amount' => '1000.00',
            'status' => 'active',
            'is_fixed_amount' => true,
            'is_multi_project' => false,
        ]);

        $this->insertDocument($contract, '600.00', 'approved', true);
        $this->insertDocument($contract, '500.00', 'cancelled', false);
        $this->insertDocument($contract, '400.00', 'draft', false);

        $this->validateContractSource($contract, '400.00');
        self::assertTrue(true);

        $this->expectException(\DomainException::class);
        $this->validateContractSource($contract, '400.01');
    }

    private function insertDocument(
        Contract $contract,
        string $amount,
        string $status,
        bool $canonicalRelation
    ): void {
        DB::table('payment_documents')->insert([
            'organization_id' => $contract->organization_id,
            'source_type' => $canonicalRelation ? null : Contract::class,
            'source_id' => $canonicalRelation ? null : $contract->id,
            'invoiceable_type' => $canonicalRelation ? Contract::class : null,
            'invoiceable_id' => $canonicalRelation ? $contract->id : null,
            'invoice_type' => 'final',
            'amount' => $amount,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function validateContractSource(Contract $contract, string $amount): void
    {
        $method = new ReflectionMethod(PaymentValidationService::class, 'validateSource');
        $method->invoke(app(PaymentValidationService::class), Contract::class, $contract->id, [
            'organization_id' => $contract->organization_id,
            'amount' => $amount,
            'invoice_type' => 'final',
        ]);
    }
}
