<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Operations\AdminSessionOperation;
use App\BusinessModules\Addons\EstimateGeneration\Operations\AdminSessionOperationAuthorizer;
use App\BusinessModules\Addons\EstimateGeneration\Operations\AdminSessionOperationCommand;
use App\BusinessModules\Addons\EstimateGeneration\Operations\AdminSessionOperationExecutor;
use App\BusinessModules\Addons\EstimateGeneration\Operations\AdminSessionOperationResult;
use App\BusinessModules\Addons\EstimateGeneration\Operations\AdminSessionOperationSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Operations\AdminSessionOperationTransaction;
use App\BusinessModules\Addons\EstimateGeneration\Operations\OperateEstimateGenerationSession;
use App\Filament\Resources\EstimateGeneration\SessionResource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class EstimateGenerationSessionResourceTest extends TestCase
{
    public function test_operation_rejects_missing_permission_before_loading_session(): void
    {
        $transaction = new RecordingOperationTransaction($this->snapshot('failed', 'generating', true));
        $executor = new RecordingOperationExecutor;
        $service = new OperateEstimateGenerationSession(
            new FixedOperationAuthorizer(false),
            $transaction,
            $executor,
        );

        $result = $service->handle($this->command(AdminSessionOperation::Retry));

        self::assertFalse($result->successful);
        self::assertSame('estimate_generation.admin_operation_forbidden', $result->messageKey);
        self::assertFalse($transaction->called);
        self::assertSame([], $executor->operations);
    }

    /**
     * @return iterable<string, array{AdminSessionOperation, string, ?string, bool, bool}>
     */
    public static function stateMatrix(): iterable
    {
        yield 'retry recoverable failure' => [AdminSessionOperation::Retry, 'failed', 'generating', true, true];
        yield 'retry terminal failure' => [AdminSessionOperation::Retry, 'failed', 'generating', false, false];
        yield 'retry active session' => [AdminSessionOperation::Retry, 'generating', null, true, false];
        yield 'cancel active session' => [AdminSessionOperation::Cancel, 'generating', null, false, true];
        yield 'cancel applying session' => [AdminSessionOperation::Cancel, 'applying', null, false, false];
        yield 'cancel applied session' => [AdminSessionOperation::Cancel, 'applied', null, false, false];
        yield 'archive failed session' => [AdminSessionOperation::Archive, 'failed', 'generating', true, true];
        yield 'archive cancelled session' => [AdminSessionOperation::Archive, 'cancelled', null, false, true];
        yield 'archive applied session' => [AdminSessionOperation::Archive, 'applied', null, false, true];
        yield 'archive active session' => [AdminSessionOperation::Archive, 'generating', null, false, false];
    }

    #[DataProvider('stateMatrix')]
    public function test_operation_enforces_state_guards(
        AdminSessionOperation $operation,
        string $status,
        ?string $resumeStatus,
        bool $hasRecoverableFailure,
        bool $allowed,
    ): void {
        $executor = new RecordingOperationExecutor;
        $service = new OperateEstimateGenerationSession(
            new FixedOperationAuthorizer(true),
            new RecordingOperationTransaction($this->snapshot($status, $resumeStatus, $hasRecoverableFailure)),
            $executor,
        );

        $result = $service->handle($this->command($operation));

        self::assertSame($allowed, $result->successful);
        self::assertCount($allowed ? 1 : 0, $executor->operations);
    }

    public function test_idempotent_result_does_not_execute_operation_twice(): void
    {
        $executor = new RecordingOperationExecutor;
        $transaction = new RecordingOperationTransaction(
            $this->snapshot('failed', 'generating', true),
            AdminSessionOperationResult::success('estimate_generation.session_retried', 'generating', 8, true),
        );
        $service = new OperateEstimateGenerationSession(
            new FixedOperationAuthorizer(true),
            $transaction,
            $executor,
        );

        $result = $service->handle($this->command(AdminSessionOperation::Retry));

        self::assertTrue($result->successful);
        self::assertTrue($result->idempotentReplay);
        self::assertSame([], $executor->operations);
    }

    public function test_resource_is_read_only_and_relation_managers_exclude_sensitive_columns(): void
    {
        $files = [
            (new ReflectionClass(SessionResource::class))->getFileName(),
            dirname((new ReflectionClass(SessionResource::class))->getFileName()).'/SessionResource/RelationManagers/DocumentsRelationManager.php',
            dirname((new ReflectionClass(SessionResource::class))->getFileName()).'/SessionResource/RelationManagers/CheckpointsRelationManager.php',
            dirname((new ReflectionClass(SessionResource::class))->getFileName()).'/SessionResource/RelationManagers/AuditEventsRelationManager.php',
            dirname((new ReflectionClass(SessionResource::class))->getFileName()).'/SessionResource/RelationManagers/FailuresRelationManager.php',
            dirname((new ReflectionClass(SessionResource::class))->getFileName()).'/SessionResource/RelationManagers/UsageRelationManager.php',
            dirname((new ReflectionClass(SessionResource::class))->getFileName()).'/SessionResource/RelationManagers/ProcessingUnitsRelationManager.php',
        ];
        $source = '';

        foreach ($files as $file) {
            self::assertFileExists($file);
            $contents = file_get_contents($file);
            self::assertIsString($contents);
            $source .= $contents;
        }

        foreach (['extracted_text', 'structured_payload', 'output_payload', 'price_snapshot', 'safe_context', "TextEntry::make('input_payload'", "TextEntry::make('draft_payload'"] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $source);
        }

        self::assertStringContainsString('public static function canCreate(): bool', $source);
        self::assertStringContainsString('public static function canEdit(Model $record): bool', $source);
        self::assertStringContainsString('public static function canDelete(Model $record): bool', $source);
        self::assertStringContainsString('OperateEstimateGenerationSession::class', $source);
        self::assertStringNotContainsString('->save()', $source);
        self::assertStringNotContainsString('->update([', $source);
        self::assertStringNotContainsString('DeleteAction::make', $source);
    }

    private function command(AdminSessionOperation $operation): AdminSessionOperationCommand
    {
        return new AdminSessionOperationCommand(
            actorId: 5,
            sessionId: 11,
            organizationId: 17,
            projectId: 31,
            expectedStateVersion: 7,
            operation: $operation,
            idempotencyKey: '01J2X5B8YWFK9YD8Q6V1VZ4H3K',
        );
    }

    private function snapshot(string $status, ?string $resumeStatus, bool $recoverable): AdminSessionOperationSnapshot
    {
        return new AdminSessionOperationSnapshot(11, 17, 31, 7, $status, $resumeStatus, $recoverable);
    }
}

final readonly class FixedOperationAuthorizer implements AdminSessionOperationAuthorizer
{
    public function __construct(private bool $allowed) {}

    public function canOperate(int $actorId): bool
    {
        return $this->allowed;
    }
}

final class RecordingOperationTransaction implements AdminSessionOperationTransaction
{
    public bool $called = false;

    public function __construct(
        private readonly AdminSessionOperationSnapshot $snapshot,
        private readonly ?AdminSessionOperationResult $replay = null,
    ) {}

    public function execute(AdminSessionOperationCommand $command, callable $operation): AdminSessionOperationResult
    {
        $this->called = true;

        return $this->replay ?? $operation($this->snapshot);
    }
}

final class RecordingOperationExecutor implements AdminSessionOperationExecutor
{
    /** @var list<AdminSessionOperation> */
    public array $operations = [];

    public function execute(
        AdminSessionOperationCommand $command,
        AdminSessionOperationSnapshot $snapshot,
    ): AdminSessionOperationResult {
        $this->operations[] = $command->operation;

        return AdminSessionOperationResult::success(
            'estimate_generation.admin_operation_completed',
            $command->operation === AdminSessionOperation::Archive ? 'archived' : $snapshot->status,
            $snapshot->stateVersion + 1,
        );
    }
}
