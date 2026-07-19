<?php

declare(strict_types=1);

namespace Tests\Feature\Contract;

use App\DTOs\SupplementaryAgreementDTO;
use App\Enums\Contract\ContractStateEventTypeEnum;
use App\Enums\Contract\ContractStatusEnum;
use App\Models\Contract;
use App\Models\ContractStateEvent;
use App\Models\SupplementaryAgreement;
use App\Services\Contract\SupplementaryAgreementService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SupplementaryAgreementIdempotencyTest extends TestCase
{
    public function refreshDatabase(): void
    {
        Schema::dropIfExists('contract_current_state');
        Schema::dropIfExists('contract_state_events');
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
            $table->timestampTz('applied_at')->nullable();
            $table->unsignedBigInteger('applied_by_user_id')->nullable();
            $table->string('application_key')->nullable()->unique();
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
        self::assertNotNull($agreement->fresh()->applied_at);
        self::assertSame($actorId, $agreement->fresh()->applied_by_user_id);
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

    private function agreementDto(Contract $contract, string $number): SupplementaryAgreementDTO
    {
        return new SupplementaryAgreementDTO(
            contract_id: $contract->id,
            number: $number,
            agreement_date: now()->toDateString(),
            change_amount: 250.0,
            subject_changes: [],
            subcontract_changes: null,
            gp_changes: null,
            advance_changes: null,
        );
    }

    private function agreementFinancialEvents(SupplementaryAgreement $agreement): Builder
    {
        return ContractStateEvent::query()
            ->where('triggered_by_type', SupplementaryAgreement::class)
            ->where('triggered_by_id', $agreement->id);
    }
}
