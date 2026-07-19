<?php

declare(strict_types=1);

namespace Tests\Feature\Contract;

use App\Enums\Contract\ContractStateEventTypeEnum;
use App\Enums\Contract\ContractStatusEnum;
use App\Models\Contract;
use App\Models\ContractStateEvent;
use App\Models\SupplementaryAgreement;
use App\Services\Contract\SupplementaryAgreementService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SupplementaryAgreementIdempotencyTest extends TestCase
{
    public function refreshDatabase(): void
    {
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

        Contract::flushEventListeners();
    }

    public function test_agreement_is_applied_only_once(): void
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

        $agreement = SupplementaryAgreement::create([
            'contract_id' => $contract->id,
            'number' => 'ДС-1',
            'agreement_date' => now()->toDateString(),
            'change_amount' => '250.00',
            'subject_changes' => [],
        ]);

        $service = app(SupplementaryAgreementService::class);

        $firstResult = $service->applyOnce($agreement, $actorId);
        $secondResult = $service->applyOnce($agreement, $actorId);

        self::assertSame($contract->id, $firstResult->id);
        self::assertSame($contract->id, $secondResult->id);
        self::assertSame('1250.00', $contract->fresh()->total_amount);
        self::assertSame(1, ContractStateEvent::query()
            ->where('triggered_by_type', SupplementaryAgreement::class)
            ->where('triggered_by_id', $agreement->id)
            ->count());

        $agreement->refresh();

        self::assertNotNull($agreement->applied_at);
        self::assertSame($actorId, $agreement->applied_by_user_id);
        self::assertSame("supplementary-agreement:{$agreement->id}", $agreement->application_key);
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
}
