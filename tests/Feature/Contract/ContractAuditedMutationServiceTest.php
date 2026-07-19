<?php

declare(strict_types=1);

namespace Tests\Feature\Contract;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\Models\Contract;
use App\Models\User;
use App\Services\Contract\ContractAuditedMutationService;
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
            $table->timestamps();
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
