<?php

declare(strict_types=1);

namespace Tests\Feature\Contract;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\Enums\Contract\ContractStatusEnum;
use App\Models\Contract;
use App\Models\User;
use App\Services\Contract\ContractAuditedMutationService;
use App\Services\Contract\ContractAuditReconciliationService;
use App\Services\LegalArchive\Audit\LegalDocumentAudit;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ContractAuditedMutationServiceTest extends TestCase
{
    private Capsule $database;

    protected function setUp(): void
    {
        parent::setUp();
        $this->database = new Capsule;
        $this->database->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $this->database->setAsGlobal();
        $this->database->setEventDispatcher(new Dispatcher(new Container));
        $this->database->bootEloquent();
        Model::clearBootedModels();
        $this->database->schema()->create('contracts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->string('number');
            $table->string('status')->default('draft');
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->boolean('is_fixed_amount')->default(true);
            $table->decimal('total_amount', 20, 4)->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
        $this->database->schema()->create('contract_performance_acts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('contract_id');
            $table->decimal('amount', 20, 4);
            $table->boolean('is_approved')->default(false);
            $table->timestamps();
        });
        $this->database->schema()->create('supplementary_agreements', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('contract_id');
            $table->decimal('change_amount', 20, 4);
            $table->timestamps();
            $table->softDeletes();
        });
        $this->database->schema()->create('contract_audit_reconciliation_debts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->unsignedBigInteger('contract_id');
            $table->string('source_type');
            $table->string('source_id');
            $table->string('change_fingerprint', 64);
            $table->decimal('expected_total_amount', 20, 4)->nullable();
            $table->json('entity_context');
            $table->text('last_error');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('available_at');
            $table->uuid('claim_token')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('dead_lettered_at')->nullable();
            $table->timestamps();
            $table->unique(['source_type', 'source_id', 'change_fingerprint']);
        });
    }

    public function test_update_records_exact_before_after_once_in_same_transaction(): void
    {
        $contract = Contract::query()->create(['organization_id' => 7, 'number' => 'D-1']);
        $audit = new RecordingContractAudit;
        $service = new ContractAuditedMutationService($audit, $this->database->getConnection());

        $service->update($contract, ['supplier_id' => 55], 'supplier_linked', 9, [
            'source_event_id' => 'order:17',
        ]);

        self::assertSame(55, $contract->refresh()->supplier_id);
        self::assertCount(1, $audit->events);
        self::assertNull($audit->events[0]['context']['before']['supplier_id']);
        self::assertSame(55, $audit->events[0]['context']['after']['supplier_id']);
        self::assertSame('order:17', $audit->events[0]['context']['source_event_id']);
    }

    public function test_audit_failure_rolls_back_contract_update(): void
    {
        $contract = Contract::query()->create(['organization_id' => 7, 'number' => 'D-1']);
        $service = new ContractAuditedMutationService(
            new FailingContractAudit,
            $this->database->getConnection(),
        );

        try {
            $service->update($contract, ['supplier_id' => 55], 'supplier_linked', 9);
            self::fail('Audit failure must abort mutation.');
        } catch (RuntimeException) {
        }

        self::assertNull($contract->refresh()->supplier_id);
    }

    public function test_save_dirty_uses_original_cast_values_for_before_and_current_values_for_after(): void
    {
        $contract = Contract::query()->create([
            'organization_id' => 7,
            'number' => 'D-1',
            'status' => ContractStatusEnum::DRAFT,
            'supplier_id' => null,
            'is_fixed_amount' => true,
        ]);
        $contract->status = ContractStatusEnum::ACTIVE;
        $contract->supplier_id = 55;
        $contract->is_fixed_amount = false;
        $audit = new RecordingContractAudit;

        (new ContractAuditedMutationService($audit, $this->database->getConnection()))
            ->saveDirty($contract, 'dirty_saved', 9);

        $context = $audit->events[0]['context'];
        self::assertSame(ContractStatusEnum::DRAFT, $context['before']['status']);
        self::assertNull($context['before']['supplier_id']);
        self::assertTrue($context['before']['is_fixed_amount']);
        self::assertSame(ContractStatusEnum::ACTIVE, $context['after']['status']);
        self::assertSame(55, $context['after']['supplier_id']);
        self::assertFalse($context['after']['is_fixed_amount']);
    }

    public function test_reconciliation_uses_current_authoritative_total_instead_of_stale_diagnostic_value(): void
    {
        $contract = Contract::query()->create(['organization_id' => 7, 'number' => 'D-2', 'is_fixed_amount' => false, 'total_amount' => 0]);
        $this->database->table('contract_performance_acts')->insert(['contract_id' => $contract->id, 'amount' => 150, 'is_approved' => true, 'created_at' => now(), 'updated_at' => now()]);
        $audit = new RecordingContractAudit;
        $service = $this->reconciliation($audit);
        $service->recordDebt($contract, (int) $contract->id, 'performance_act', 1, str_repeat('a', 64), 100, new RuntimeException('audit failed'));

        self::assertSame(1, $service->reconcile(), (string) $this->database->table('contract_audit_reconciliation_debts')->value('last_error'));
        self::assertSame(150.0, (float) $contract->refresh()->total_amount);
        self::assertNotNull($this->database->table('contract_audit_reconciliation_debts')->value('resolved_at'));
        self::assertSame(100.0, (float) $this->database->table('contract_audit_reconciliation_debts')->value('expected_total_amount'));
    }

    public function test_reconciliation_crash_rolls_back_mutation_and_retry_converges_with_same_authoritative_id(): void
    {
        $contract = Contract::query()->create(['organization_id' => 7, 'number' => 'D-3', 'is_fixed_amount' => false, 'total_amount' => 0]);
        $this->database->table('supplementary_agreements')->insert(['contract_id' => $contract->id, 'change_amount' => 75, 'created_at' => now(), 'updated_at' => now()]);
        $audit = new RecordingContractAudit;
        $service = $this->reconciliation($audit, static function (): void {
            throw new RuntimeException('crash after mutation');
        });
        $service->recordDebt($contract, (int) $contract->id, 'supplementary_agreement', 2, str_repeat('b', 64), 25, new RuntimeException('audit failed'));

        self::assertSame(0, $service->reconcile());
        self::assertSame(0.0, (float) $contract->refresh()->total_amount);
        self::assertNull($this->database->table('contract_audit_reconciliation_debts')->value('resolved_at'));
        $this->database->table('contract_audit_reconciliation_debts')->update(['available_at' => now()->subSecond()]);
        self::assertSame(1, $this->reconciliation($audit)->reconcile(), (string) $this->database->table('contract_audit_reconciliation_debts')->value('last_error'));
        self::assertSame(75.0, (float) $contract->refresh()->total_amount);
        self::assertSame($audit->events[0]['context']['source_event_id'], $audit->events[1]['context']['source_event_id']);
    }

    public function test_reconciliation_recovers_stale_lease_and_dead_letters_after_bounded_failures(): void
    {
        $audit = new RecordingContractAudit;
        $service = $this->reconciliation($audit);
        $contract = Contract::query()->create(['organization_id' => 7, 'number' => 'D-4', 'is_fixed_amount' => false, 'total_amount' => 0]);
        $service->recordDebt($contract, (int) $contract->id, 'performance_act', 3, str_repeat('d', 64), null, new RuntimeException('failed'));
        $this->database->table('contract_audit_reconciliation_debts')->update([
            'claim_token' => '00000000-0000-0000-0000-000000000001', 'claimed_at' => now()->subMinutes(11),
        ]);
        self::assertSame(1, $service->reconcile());

        $service->recordDebt(null, 999999, 'performance_act', 4, str_repeat('e', 64), null, new RuntimeException('missing contract'));
        for ($attempt = 1; $attempt <= 8; $attempt++) {
            $this->database->table('contract_audit_reconciliation_debts')->where('contract_id', 999999)->update(['available_at' => now()->subSecond()]);
            self::assertSame(0, $service->reconcile());
        }
        $dead = $this->database->table('contract_audit_reconciliation_debts')->where('contract_id', 999999)->first();
        self::assertSame(8, (int) $dead->attempts);
        self::assertNotNull($dead->dead_lettered_at);
    }

    private function reconciliation(RecordingContractAudit $audit, ?\Closure $afterMutation = null): ContractAuditReconciliationService
    {
        $mutations = new ContractAuditedMutationService($audit, $this->database->getConnection());

        return new ContractAuditReconciliationService($this->database->getConnection(), $mutations, $afterMutation);
    }
}

class RecordingContractAudit implements LegalDocumentAudit
{
    /** @var list<array{event: string, context: array<string, mixed>}> */
    public array $events = [];

    public function record(string $event, LegalArchiveDocument $document, User $actor, array $context = []): void {}

    public function recordForActorId(string $event, LegalArchiveDocument $document, ?int $actorId, array $context = []): void {}

    public function recordContractForActorId(string $event, Contract $contract, ?int $actorId, array $context = []): void
    {
        $this->events[] = ['event' => $event, 'context' => $context];
    }
}

final class FailingContractAudit extends RecordingContractAudit
{
    public function recordContractForActorId(string $event, Contract $contract, ?int $actorId, array $context = []): void
    {
        throw new RuntimeException('audit failed');
    }
}
