<?php

declare(strict_types=1);

namespace Tests\Unit\LegalArchive;

use App\BusinessModules\Features\LegalArchive\Models\LegalDocumentOutboxMessage;
use App\Services\LegalArchive\Audit\LegalDocumentOutbox;
use App\Services\LegalArchive\Audit\LegalDocumentOutboxPublisher;
use DomainException;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class LegalDocumentOutboxTest extends TestCase
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

        $this->database->schema()->create('legal_document_outbox', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('organization_id');
            $table->string('aggregate_type');
            $table->string('aggregate_id');
            $table->string('event');
            $table->json('payload');
            $table->string('payload_hash', 64);
            $table->string('idempotency_key');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('available_at');
            $table->timestamp('published_at')->nullable();
            $table->text('last_error')->nullable();
            $table->uuid('claim_token')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('dead_lettered_at')->nullable();
            $table->timestamp('reconciliation_required_at')->nullable();
            $table->timestamps();
            $table->unique(
                ['organization_id', 'aggregate_type', 'aggregate_id', 'idempotency_key'],
                'legal_document_outbox_idempotency_unique',
            );
        });
    }

    public function test_enqueue_is_deterministic_idempotent_and_tenant_scoped(): void
    {
        $outbox = new LegalDocumentOutbox(dispatchJobs: false, connection: $this->database->getConnection());
        $payloadA = ['organization_id' => 15, 'b' => 2, 'a' => 1, 'content' => 'pdf bytes'];
        $payloadB = ['content' => 'pdf bytes', 'a' => 1, 'b' => 2, 'organization_id' => 15];

        $first = $outbox->enqueue('legal_document.version_created', 'legal_document', '42', $payloadA, 'version:91');
        $second = $outbox->enqueue('legal_document.version_created', 'legal_document', '42', $payloadB, 'version:91');
        $foreign = $outbox->enqueue(
            'legal_document.version_created',
            'legal_document',
            '42',
            ['organization_id' => 16],
            'version:91',
        );

        self::assertSame($first->id, $second->id);
        self::assertNotSame($first->id, $foreign->id);
        self::assertSame(2, LegalDocumentOutboxMessage::query()->count());
        self::assertSame('[скрыто]', $first->payload['content']);
        self::assertSame($first->payload_hash, $second->payload_hash);
    }

    public function test_claim_prevents_double_publication_and_marks_success(): void
    {
        $outbox = new LegalDocumentOutbox(dispatchJobs: false, connection: $this->database->getConnection());
        $message = $outbox->enqueue(
            'legal_document.created',
            'legal_document',
            '42',
            ['organization_id' => 15],
            'document:42:create',
        );
        $publisher = new RecordingPublisher;

        $first = $outbox->publish((string) $message->id, $publisher);
        $second = $outbox->publish((string) $message->id, $publisher);

        self::assertSame('published', $first->status);
        self::assertSame('already_published', $second->status);
        self::assertCount(1, $publisher->published);
        self::assertSame($message->id, $publisher->published[0]['message_id']);
        self::assertNotNull($message->refresh()->published_at);
        self::assertSame(1, $message->attempts);
    }

    public function test_failure_uses_backoff_and_eventually_dead_letters_for_reconciliation(): void
    {
        $outbox = new LegalDocumentOutbox(
            dispatchJobs: false,
            maximumAttempts: 2,
            connection: $this->database->getConnection(),
        );
        $message = $outbox->enqueue(
            'legal_document.signed',
            'legal_document',
            '42',
            ['organization_id' => 15],
            'signature:71',
        );
        $publisher = new FailingPublisher;

        $first = $outbox->publish((string) $message->id, $publisher);
        self::assertSame('retry_scheduled', $first->status);
        self::assertNotNull($message->refresh()->available_at);
        self::assertNull($message->dead_lettered_at);

        $message->forceFill(['available_at' => now()->subSecond()])->save();
        $second = $outbox->publish((string) $message->id, $publisher);

        self::assertSame('dead_lettered', $second->status);
        self::assertNotNull($message->refresh()->dead_lettered_at);
        self::assertNotNull($message->reconciliation_required_at);
        self::assertSame(RuntimeException::class, $message->last_error);
        self::assertSame([$message->id], $outbox->reconciliationCandidates(15)->pluck('id')->all());
        self::assertFalse($outbox->retryReconciled(16, (string) $message->id));
        self::assertTrue($outbox->retryReconciled(15, (string) $message->id));
        self::assertNull($message->refresh()->dead_lettered_at);
        self::assertNull($message->reconciliation_required_at);
        self::assertSame(0, $message->attempts);
    }

    public function test_enqueue_rolls_back_with_domain_transaction_and_rejects_idempotency_conflicts(): void
    {
        $outbox = new LegalDocumentOutbox(dispatchJobs: false, connection: $this->database->getConnection());

        try {
            $this->database->getConnection()->transaction(function () use ($outbox): void {
                $outbox->enqueue(
                    'legal_document.created',
                    'legal_document',
                    '42',
                    ['organization_id' => 15],
                    'document:42:create',
                );

                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException) {
        }

        self::assertSame(0, LegalDocumentOutboxMessage::query()->count());

        $outbox->enqueue(
            'legal_document.created',
            'legal_document',
            '42',
            ['organization_id' => 15, 'status' => 'draft'],
            'document:42:create',
        );

        $this->expectException(DomainException::class);
        $outbox->enqueue(
            'legal_document.created',
            'legal_document',
            '42',
            ['organization_id' => 15, 'status' => 'active'],
            'document:42:create',
        );
    }

    public function test_stale_claim_is_recovered_with_same_stable_message_id(): void
    {
        $outbox = new LegalDocumentOutbox(
            dispatchJobs: false,
            claimTimeoutSeconds: 30,
            connection: $this->database->getConnection(),
        );
        $message = $outbox->enqueue(
            'legal_document.updated',
            'legal_document',
            '42',
            ['organization_id' => 15],
            'document:42:update:5',
        );
        $message->forceFill([
            'claim_token' => 'd3e114f2-b7bf-4aaa-94d7-18c371ab90b6',
            'claimed_at' => now()->subMinute(),
        ])->save();
        $publisher = new RecordingPublisher;

        self::assertSame('published', $outbox->publish((string) $message->id, $publisher)->status);
        self::assertSame($message->id, $publisher->published[0]['message_id']);
    }

    public function test_receiver_can_deduplicate_retry_after_publisher_crashes_after_delivery(): void
    {
        $outbox = new LegalDocumentOutbox(dispatchJobs: false, connection: $this->database->getConnection());
        $message = $outbox->enqueue(
            'legal_document.updated',
            'legal_document',
            '42',
            ['organization_id' => 15],
            'document:42:update:6',
        );
        $publisher = new CrashAfterDeliveryPublisher;

        self::assertSame('retry_scheduled', $outbox->publish((string) $message->id, $publisher)->status);
        $message->refresh()->forceFill(['available_at' => now()->subSecond()])->save();
        self::assertSame('published', $outbox->publish((string) $message->id, $publisher)->status);

        self::assertSame([$message->id, $message->id], $publisher->deliveryAttempts);
        self::assertSame([$message->id], array_keys($publisher->received));
    }

    public function test_pending_dispatch_scan_recovers_unclaimed_and_stale_messages(): void
    {
        $outbox = new LegalDocumentOutbox(
            dispatchJobs: false,
            claimTimeoutSeconds: 30,
            connection: $this->database->getConnection(),
        );
        $pending = $outbox->enqueue(
            'legal_document.created',
            'legal_document',
            '42',
            ['organization_id' => 15],
            'document:42:create',
        );
        $stale = $outbox->enqueue(
            'legal_document.updated',
            'legal_document',
            '43',
            ['organization_id' => 15],
            'document:43:update:1',
        );
        $stale->forceFill([
            'claim_token' => '0af99bbd-291c-4694-9574-538d344c0533',
            'claimed_at' => now()->subMinute(),
        ])->save();
        $future = $outbox->enqueue(
            'legal_document.updated',
            'legal_document',
            '44',
            ['organization_id' => 15],
            'document:44:update:1',
        );
        $future->forceFill(['available_at' => now()->addMinute()])->save();

        self::assertSame(
            [$pending->id, $stale->id],
            $outbox->pendingMessageIdsForDispatch()->all(),
        );
    }

    public function test_operational_reconciliation_is_tenant_scoped_limited_and_scheduled(): void
    {
        $command = file_get_contents(
            __DIR__.'/../../../app/Console/Commands/LegalArchive/ReconcileLegalDocumentOutbox.php',
        );
        $monitor = file_get_contents(
            __DIR__.'/../../../app/Jobs/LegalArchive/MonitorLegalDocumentOutboxDeadLetters.php',
        );
        $schedule = file_get_contents(__DIR__.'/../../../routes/console.php');

        self::assertIsString($command);
        self::assertIsString($monitor);
        self::assertIsString($schedule);
        self::assertStringContainsString('{--organization=', $command);
        self::assertStringContainsString('{--message=', $command);
        self::assertStringContainsString('{--retry ', $command);
        self::assertStringContainsString('min($limit, 100)', $command);
        self::assertStringContainsString('retryReconciled($organizationId, $messageId)', $command);
        self::assertStringContainsString('MonitorLegalDocumentOutboxDeadLetters', $schedule);
        self::assertStringContainsString('everyFiveMinutes()', $schedule);
    }
}

final class RecordingPublisher implements LegalDocumentOutboxPublisher
{
    /** @var list<array<string, mixed>> */
    public array $published = [];

    public function publish(LegalDocumentOutboxMessage $message): void
    {
        $this->published[] = [
            'message_id' => $message->id,
            'event' => $message->event,
            'payload' => $message->payload,
        ];
    }
}

final class FailingPublisher implements LegalDocumentOutboxPublisher
{
    public function publish(LegalDocumentOutboxMessage $message): void
    {
        throw new RuntimeException('provider unavailable');
    }
}

final class CrashAfterDeliveryPublisher implements LegalDocumentOutboxPublisher
{
    /** @var list<string> */
    public array $deliveryAttempts = [];

    /** @var array<string, true> */
    public array $received = [];

    public function publish(LegalDocumentOutboxMessage $message): void
    {
        $messageId = (string) $message->id;
        $this->deliveryAttempts[] = $messageId;
        $this->received[$messageId] = true;

        if (count($this->deliveryAttempts) === 1) {
            throw new RuntimeException('publisher crashed after receiver accepted message');
        }
    }
}
