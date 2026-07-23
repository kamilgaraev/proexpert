<?php

declare(strict_types=1);

namespace Tests\Feature\Contract;

use App\DTOs\SupplementaryAgreementDTO;
use App\Enums\Contract\ContractStateEventTypeEnum;
use App\Enums\Contract\ContractStatusEnum;
use App\Enums\Contract\GpCalculationTypeEnum;
use App\Models\Contract;
use App\Models\ContractStateEvent;
use App\Models\SupplementaryAgreement;
use App\Services\Contract\SupplementaryAgreementService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SupplementaryAgreementIdempotencyTest extends TestCase
{
    public function refreshDatabase(): void
    {
        Schema::dropIfExists('contract_current_state');
        Schema::dropIfExists('contract_state_events');
        Schema::dropIfExists('payment_documents');
        Schema::dropIfExists('supplementary_agreements');
        Schema::dropIfExists('contracts');

        Schema::create('contracts', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('project_id')->nullable();
            $table->string('number');
            $table->date('date');
            $table->string('subject')->nullable();
            $table->decimal('base_amount', 18, 2)->nullable();
            $table->decimal('total_amount', 18, 2)->nullable();
            $table->decimal('subcontract_amount', 18, 2)->nullable();
            $table->decimal('gp_percentage', 5, 3)->nullable();
            $table->string('gp_calculation_type')->nullable();
            $table->decimal('gp_coefficient', 10, 4)->nullable();
            $table->string('status');
            $table->boolean('is_fixed_amount')->default(true);
            $table->boolean('is_multi_project')->default(false);
            $table->boolean('is_self_execution')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('supplementary_agreements', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('contract_id');
            $table->string('number');
            $table->date('agreement_date');
            $table->decimal('change_amount', 18, 2)->nullable();
            $table->json('subject_changes');
            $table->json('subcontract_changes')->nullable();
            $table->json('gp_changes')->nullable();
            $table->json('advance_changes')->nullable();
            $table->json('supersede_agreement_ids')->nullable();
            $table->timestampTz('financial_applied_at')->nullable();
            $table->timestampTz('applied_at')->nullable();
            $table->unsignedBigInteger('applied_by_user_id')->nullable();
            $table->string('application_key')->nullable()->unique();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('payment_documents', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->string('document_type');
            $table->string('document_number');
            $table->date('document_date');
            $table->string('invoice_type')->nullable();
            $table->string('invoiceable_type')->nullable();
            $table->unsignedBigInteger('invoiceable_id')->nullable();
            $table->decimal('amount', 18, 2);
            $table->decimal('paid_amount', 18, 2)->default(0);
            $table->decimal('remaining_amount', 18, 2)->nullable();
            $table->string('status');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('contract_state_events', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('contract_id');
            $table->string('event_type');
            $table->string('triggered_by_type')->nullable();
            $table->unsignedBigInteger('triggered_by_id')->nullable();
            $table->unsignedBigInteger('specification_id')->nullable();
            $table->decimal('amount_delta', 18, 2)->default(0);
            $table->date('effective_from')->nullable();
            $table->unsignedBigInteger('supersedes_event_id')->nullable();
            $table->json('metadata')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();
        });

        Schema::create('contract_current_state', static function (Blueprint $table): void {
            $table->unsignedBigInteger('contract_id')->primary();
            $table->unsignedBigInteger('active_specification_id')->nullable();
            $table->decimal('current_total_amount', 18, 2)->default(0);
            $table->json('active_events')->nullable();
            $table->timestamp('calculated_at')->nullable();
            $table->timestamps();
        });

        Contract::flushEventListeners();
    }

    public function test_create_then_apply_uses_one_financial_application_path(): void
    {
        $actorId = 7;
        $contract = $this->createContract();

        ContractStateEvent::create([
            'contract_id' => $contract->id,
            'event_type' => ContractStateEventTypeEnum::CREATED,
            'triggered_by_type' => Contract::class,
            'triggered_by_id' => $contract->id,
            'amount_delta' => '1000.00',
            'effective_from' => now(),
            'created_by_user_id' => $actorId,
        ]);

        $service = app(SupplementaryAgreementService::class);
        $agreement = $service->create($this->agreementDto($contract, 'ДС-1'));

        self::assertSame('1000.00', $contract->fresh()->total_amount);
        self::assertSame(0, $this->agreementFinancialEvents($agreement)->count());
        self::assertNull($agreement->applied_at);

        $firstResult = $service->applyOnce($agreement, $actorId);
        $secondResult = $service->applyOnce($agreement, $actorId);

        self::assertSame($contract->id, $firstResult->id);
        self::assertSame($contract->id, $secondResult->id);
        self::assertSame('1250.00', $contract->fresh()->total_amount);
        self::assertSame(1, $this->agreementFinancialEvents($agreement)->count());

        $agreement->refresh();

        self::assertNotNull($agreement->financial_applied_at);
        self::assertNotNull($agreement->applied_at);
        self::assertSame($actorId, $agreement->applied_by_user_id);
        self::assertSame("supplementary-agreement:{$agreement->id}", $agreement->application_key);
    }

    public function test_historical_financial_event_marks_agreement_applied_without_reapplying_amount(): void
    {
        $actorId = 8;
        $contract = $this->createContract();

        ContractStateEvent::create([
            'contract_id' => $contract->id,
            'event_type' => ContractStateEventTypeEnum::CREATED,
            'triggered_by_type' => Contract::class,
            'triggered_by_id' => $contract->id,
            'amount_delta' => '1000.00',
            'effective_from' => now(),
            'created_by_user_id' => $actorId,
        ]);

        $service = app(SupplementaryAgreementService::class);
        $agreement = $service->create($this->agreementDto($contract, 'ДС-ИСТ-1'));

        $this->agreementFinancialEvents($agreement)->delete();
        Contract::query()->whereKey($contract->id)->update(['total_amount' => '1250.00']);
        ContractStateEvent::create([
            'contract_id' => $contract->id,
            'event_type' => ContractStateEventTypeEnum::SUPPLEMENTARY_AGREEMENT_CREATED,
            'triggered_by_type' => SupplementaryAgreement::class,
            'triggered_by_id' => $agreement->id,
            'amount_delta' => '250.00',
            'effective_from' => now(),
            'created_by_user_id' => $actorId,
        ]);

        $result = $service->applyOnce($agreement->fresh(), $actorId);

        self::assertSame($contract->id, $result->id);
        self::assertSame('1250.00', $contract->fresh()->total_amount);
        self::assertSame(1, $this->agreementFinancialEvents($agreement)->count());
        self::assertNotNull($agreement->fresh()->financial_applied_at);
        self::assertNotNull($agreement->fresh()->applied_at);
        self::assertSame($actorId, $agreement->fresh()->applied_by_user_id);
    }

    public function test_historical_financial_event_applies_subcontract_changes_once(): void
    {
        Carbon::setTestNow('2026-07-19 10:00:00');
        $actorId = 9;
        $contract = $this->createContract();
        $agreement = $this->createAgreementWithHistoricalFinancialEvent(
            $contract,
            $actorId,
            $this->agreementDto($contract, 'DS-HIST-SUB', ['amount' => 300])
        );

        $service = app(SupplementaryAgreementService::class);
        $service->applyOnce($agreement, $actorId);

        self::assertSame('1250.00', $contract->fresh()->total_amount);
        self::assertSame('300.00', $contract->fresh()->subcontract_amount);
        self::assertSame(1, $this->agreementFinancialEvents($agreement)->count());
        self::assertNotNull($agreement->fresh()->financial_applied_at);
        self::assertNotNull($agreement->fresh()->applied_at);

        $contractUpdatedAt = $contract->fresh()->updated_at;
        $agreementUpdatedAt = $agreement->fresh()->updated_at;
        Carbon::setTestNow('2026-07-19 10:01:00');
        $service->applyOnce($agreement->fresh(), $actorId);

        self::assertTrue($contractUpdatedAt->equalTo($contract->fresh()->updated_at));
        self::assertTrue($agreementUpdatedAt->equalTo($agreement->fresh()->updated_at));
        self::assertSame(1, $this->agreementFinancialEvents($agreement)->count());
        Carbon::setTestNow();
    }

    public function test_historical_financial_event_applies_gp_changes_once(): void
    {
        Carbon::setTestNow('2026-07-19 11:00:00');
        $actorId = 10;
        $contract = $this->createContract();
        $agreement = $this->createAgreementWithHistoricalFinancialEvent(
            $contract,
            $actorId,
            $this->agreementDto($contract, 'DS-HIST-GP', null, ['percentage' => -2.5])
        );

        $service = app(SupplementaryAgreementService::class);
        $service->applyOnce($agreement, $actorId);

        self::assertSame('1250.00', $contract->fresh()->total_amount);
        self::assertSame('-2.500', $contract->fresh()->gp_percentage);
        self::assertSame(GpCalculationTypeEnum::PERCENTAGE, $contract->fresh()->gp_calculation_type);
        self::assertSame(1, $this->agreementFinancialEvents($agreement)->count());
        self::assertNotNull($agreement->fresh()->financial_applied_at);
        self::assertNotNull($agreement->fresh()->applied_at);

        $contractUpdatedAt = $contract->fresh()->updated_at;
        $agreementUpdatedAt = $agreement->fresh()->updated_at;
        Carbon::setTestNow('2026-07-19 11:01:00');
        $service->applyOnce($agreement->fresh(), $actorId);

        self::assertTrue($contractUpdatedAt->equalTo($contract->fresh()->updated_at));
        self::assertTrue($agreementUpdatedAt->equalTo($agreement->fresh()->updated_at));
        self::assertSame(1, $this->agreementFinancialEvents($agreement)->count());
        Carbon::setTestNow();
    }

    public function test_historical_financial_event_applies_advance_changes_once(): void
    {
        Carbon::setTestNow('2026-07-19 12:00:00');
        $actorId = 11;
        $contract = $this->createContract();
        $paymentId = DB::table('payment_documents')->insertGetId([
            'organization_id' => $contract->organization_id,
            'document_type' => 'invoice',
            'document_number' => 'ADV-1',
            'document_date' => now()->toDateString(),
            'invoice_type' => 'advance',
            'invoiceable_type' => Contract::class,
            'invoiceable_id' => $contract->id,
            'amount' => 100,
            'paid_amount' => 100,
            'remaining_amount' => 0,
            'status' => 'paid',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $agreement = $this->createAgreementWithHistoricalFinancialEvent(
            $contract,
            $actorId,
            $this->agreementDto($contract, 'DS-HIST-ADV', null, null, [
                ['payment_id' => $paymentId, 'new_amount' => 175],
            ])
        );

        $service = app(SupplementaryAgreementService::class);
        $service->applyOnce($agreement, $actorId);

        $payment = DB::table('payment_documents')->find($paymentId);
        self::assertSame('1250.00', $contract->fresh()->total_amount);
        self::assertSame(175.0, (float) $payment->amount);
        self::assertSame(175.0, (float) $payment->paid_amount);
        self::assertSame(0.0, (float) $payment->remaining_amount);
        self::assertSame(1, $this->agreementFinancialEvents($agreement)->count());
        self::assertNotNull($agreement->fresh()->financial_applied_at);
        self::assertNotNull($agreement->fresh()->applied_at);

        $paymentUpdatedAt = $payment->updated_at;
        $agreementUpdatedAt = $agreement->fresh()->updated_at;
        Carbon::setTestNow('2026-07-19 12:01:00');
        $service->applyOnce($agreement->fresh(), $actorId);

        self::assertSame($paymentUpdatedAt, DB::table('payment_documents')->find($paymentId)->updated_at);
        self::assertTrue($agreementUpdatedAt->equalTo($agreement->fresh()->updated_at));
        self::assertSame(1, $this->agreementFinancialEvents($agreement)->count());
        Carbon::setTestNow();
    }

    private function createContract(): Contract
    {
        return Contract::create([
            'organization_id' => 1,
            'project_id' => null,
            'number' => 'Д-100',
            'date' => now()->toDateString(),
            'subject' => 'Тестовый договор',
            'base_amount' => 1000,
            'total_amount' => 1000,
            'status' => ContractStatusEnum::ACTIVE->value,
            'is_fixed_amount' => true,
            'is_multi_project' => false,
            'is_self_execution' => false,
        ]);
    }

    private function agreementDto(
        Contract $contract,
        string $number,
        ?array $subcontractChanges = null,
        ?array $gpChanges = null,
        ?array $advanceChanges = null
    ): SupplementaryAgreementDTO {
        return new SupplementaryAgreementDTO(
            contract_id: $contract->id,
            number: $number,
            agreement_date: now()->toDateString(),
            change_amount: 250.0,
            subject_changes: [],
            subcontract_changes: $subcontractChanges,
            gp_changes: $gpChanges,
            advance_changes: $advanceChanges,
        );
    }

    private function createAgreementWithHistoricalFinancialEvent(
        Contract $contract,
        int $actorId,
        SupplementaryAgreementDTO $dto
    ): SupplementaryAgreement {
        $agreement = app(SupplementaryAgreementService::class)->create($dto);
        Contract::query()->whereKey($contract->id)->update(['total_amount' => '1250.00']);
        ContractStateEvent::create([
            'contract_id' => $contract->id,
            'event_type' => ContractStateEventTypeEnum::SUPPLEMENTARY_AGREEMENT_CREATED,
            'triggered_by_type' => SupplementaryAgreement::class,
            'triggered_by_id' => $agreement->id,
            'amount_delta' => '250.00',
            'effective_from' => now(),
            'created_by_user_id' => $actorId,
        ]);

        return $agreement;
    }

    private function agreementFinancialEvents(SupplementaryAgreement $agreement): Builder
    {
        return ContractStateEvent::query()
            ->where('triggered_by_type', SupplementaryAgreement::class)
            ->where('triggered_by_id', $agreement->id);
    }
}
