<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Operations\AdminFailureResolutionAuthorizer;
use App\BusinessModules\Addons\EstimateGeneration\Operations\AdminFailureResolutionCommand;
use App\BusinessModules\Addons\EstimateGeneration\Operations\AdminFailureResolutionRegistryClaim;
use App\BusinessModules\Addons\EstimateGeneration\Operations\AdminFailureResolutionResult;
use App\BusinessModules\Addons\EstimateGeneration\Operations\AdminFailureResolutionSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Operations\AdminFailureResolutionTransaction;
use App\BusinessModules\Addons\EstimateGeneration\Operations\ResolveEstimateGenerationFailure;
use App\Filament\Resources\EstimateGeneration\FailureResource;
use App\Filament\Support\EstimateGeneration\FailureDiagnosticsPresenter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class EstimateGenerationFailureResourceTest extends TestCase
{
    public function test_command_fingerprint_is_canonical_and_scope_complete(): void
    {
        self::assertSame(
            'sha256:'.hash('sha256', 'resolve_failure|organization_id=7|project_id=9|actor_id=5|failure_id=0190d0b8-91c1-7b25-9384-271f050f5c89|session_id=11|expected_occurrence_sequence=13'),
            $this->command()->fingerprint(),
        );
    }

    public function test_registry_claim_distinguishes_owner_replay_conflict_and_pending_waiter(): void
    {
        $fingerprint = $this->command()->fingerprint();
        $result = AdminFailureResolutionResult::success()->toArray();

        self::assertSame('execute', AdminFailureResolutionRegistryClaim::decide($fingerprint, $fingerprint, 'pending', null, true)->decision);
        self::assertSame('pending', AdminFailureResolutionRegistryClaim::decide($fingerprint, $fingerprint, 'pending', null, false)->decision);
        $replay = AdminFailureResolutionRegistryClaim::decide($fingerprint, $fingerprint, 'completed', $result, false);
        self::assertSame('replay', $replay->decision);
        self::assertSame($result, $replay->result);
        self::assertSame('conflict', AdminFailureResolutionRegistryClaim::decide($fingerprint, 'sha256:'.str_repeat('a', 64), 'completed', $result, false)->decision);
    }

    public function test_idempotency_registry_migration_has_exact_db_enforced_contract(): void
    {
        $source = file_get_contents(dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_14_000200_create_estimate_generation_admin_operations.php');
        self::assertIsString($source);

        foreach (['organization_id', 'operation', 'idempotency_key', 'command_fingerprint', 'status', 'result', 'created_at', 'updated_at', 'completed_at'] as $column) {
            self::assertStringContainsString("'{$column}'", $source);
        }
        self::assertStringContainsString("unique(['organization_id', 'operation', 'idempotency_key'], 'eg_admin_operations_idempotency_uq')", $source);
        self::assertStringContainsString("CHECK (status IN ('pending','completed'))", $source);
        self::assertStringContainsString('command_fingerprint ~', $source);
        self::assertStringContainsString("status = 'pending' AND result IS NULL AND completed_at IS NULL", $source);
        self::assertStringContainsString("status = 'completed' AND result IS NOT NULL AND completed_at IS NOT NULL", $source);
    }

    public function test_resolution_transaction_claims_registry_before_mutation_and_never_replays_from_audit_json(): void
    {
        $source = $this->source(\App\BusinessModules\Addons\EstimateGeneration\Operations\EloquentAdminFailureResolutionTransaction::class);

        self::assertStringContainsString("table('estimate_generation_admin_operations')->insertOrIgnore", $source);
        self::assertStringContainsString("table('estimate_generation_admin_operations')", $source);
        self::assertStringContainsString('->lockForUpdate()', $source);
        self::assertStringContainsString('AdminFailureResolutionRegistryClaim::decide', $source);
        self::assertStringContainsString("'command_fingerprint' => \$command->fingerprint()", $source);
        self::assertStringContainsString("'status' => 'completed'", $source);
        self::assertStringContainsString("'command_fingerprint' => \$command->fingerprint()", $source);
        self::assertStringContainsString("throw new \\LogicException('Admin operation registry claim was lost.');", $source);
        self::assertStringNotContainsString("where('payload->idempotency_key'", $source);
        self::assertLessThan(
            strpos($source, "table('estimate_generation_failure_identities')"),
            strpos($source, "table('estimate_generation_admin_operations')->insertOrIgnore"),
        );
    }

    public function test_failure_query_selects_only_closed_diagnostics_projection(): void
    {
        self::assertSame([
            'id', 'organization_id', 'project_id', 'session_id', 'document_id', 'page_id',
            'unit_id', 'checkpoint_id', 'usage_attempt_id', 'stage', 'operation', 'provider',
            'model', 'category', 'code', 'attempt', 'occurrence_count', 'first_seen_at',
            'last_seen_at', 'resolved_at', 'resolution_code', 'latest_occurrence_sequence',
        ], $this->safeColumns());

        $source = $this->source(FailureResource::class);
        self::assertStringContainsString("->with('session:id,organization_id,project_id,status')", $source);
        self::assertStringContainsString("safe_context->>'{\$key}' as diagnostic_{\$key}", $source);
        self::assertStringContainsString("->defaultSort('last_seen_at', 'desc')", $source);
        self::assertStringContainsString('paginationPageOptions([25, 50, 100])', $source);
        foreach (['period', 'organization_id', 'stage', 'category', 'resolved_at'] as $filter) {
            self::assertMatchesRegularExpression("/(?:Filter|SelectFilter|TernaryFilter)::make\\('{$filter}'\\)/", $source);
        }
    }

    public function test_closed_presenter_discards_production_shaped_sensitive_and_unknown_keys(): void
    {
        $diagnostics = FailureDiagnosticsPresenter::present([
            'Authorization' => 'Bearer eyJmalicious.payload.signature',
            'api_key' => 'sk-production-secret',
            'headers' => ['cookie' => 'session=secret'],
            'prompt' => 'full confidential estimate prompt',
            'request_body' => ['documents' => ['secret text']],
            'response_body' => ['raw' => 'provider answer'],
            'stack_trace' => 'C:\\app\\provider.php:52',
            'arbitrary_context' => '<script>alert(1)</script>',
            'provider_code' => 'timeout',
            'http_class' => '5xx',
            'http_code' => 504,
            'attempt' => 3,
            'safe_code' => 'sk-production-secret',
        ]);
        $encoded = json_encode($diagnostics, JSON_THROW_ON_ERROR);

        self::assertSame([
            'provider_code' => 'timeout',
            'http_class' => '5xx',
            'http_code' => '504',
            'attempt' => '3',
        ], $diagnostics);
        foreach (['Authorization', 'api_key', 'headers', 'prompt', 'request', 'response', 'stack', 'arbitrary', 'secret'] as $forbidden) {
            self::assertStringNotContainsStringIgnoringCase($forbidden, $encoded);
        }
    }

    public function test_failure_resource_never_selects_or_renders_raw_context(): void
    {
        $source = $this->source(FailureResource::class);

        self::assertStringNotContainsString("TextEntry::make('safe_context')", $source);
        foreach (['payload', 'prompt', 'document_text', 'request_body', 'response_body', 'credentials', 'headers', 'stack_trace'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, strtolower($source));
        }
        self::assertStringContainsString('FailureDiagnosticsPresenter::present', $source);
        self::assertStringContainsString("safe_context->>'{\$key}' as diagnostic_{\$key}", $source);
    }

    /** @return iterable<string, array{bool, bool, bool}> */
    public static function resolutionMatrix(): iterable
    {
        yield 'permission denied' => [false, true, false];
        yield 'already resolved' => [true, false, false];
        yield 'active occurrence' => [true, true, true];
    }

    #[DataProvider('resolutionMatrix')]
    public function test_mark_resolved_enforces_permission_and_active_occurrence(
        bool $allowed,
        bool $hasActiveOccurrence,
        bool $successful,
    ): void {
        $transaction = new RecordingFailureResolutionTransaction(new AdminFailureResolutionSnapshot(
            failureId: '0190d0b8-91c1-7b25-9384-271f050f5c89',
            organizationId: 7,
            projectId: 9,
            sessionId: 11,
            latestOccurrenceSequence: 13,
            hasActiveOccurrence: $hasActiveOccurrence,
        ));
        $service = new ResolveEstimateGenerationFailure(
            new FixedFailureResolutionAuthorizer($allowed),
            $transaction,
        );

        $result = $service->handle($this->command());

        self::assertSame($successful, $result->successful);
        self::assertSame($allowed, $transaction->called);
        self::assertSame($successful ? 1 : 0, $transaction->resolved);
    }

    public function test_mark_resolved_is_idempotent_and_does_not_append_twice(): void
    {
        $transaction = new RecordingFailureResolutionTransaction(
            new AdminFailureResolutionSnapshot('0190d0b8-91c1-7b25-9384-271f050f5c89', 7, 9, 11, 13, true),
            AdminFailureResolutionResult::success(true),
        );
        $service = new ResolveEstimateGenerationFailure(new FixedFailureResolutionAuthorizer(true), $transaction);

        $result = $service->handle($this->command());

        self::assertTrue($result->successful);
        self::assertTrue($result->idempotentReplay);
        self::assertSame(0, $transaction->resolved);
    }

    public function test_mark_resolved_rejects_foreign_tenant_snapshot(): void
    {
        $transaction = new RecordingFailureResolutionTransaction(
            new AdminFailureResolutionSnapshot('0190d0b8-91c1-7b25-9384-271f050f5c89', 8, 9, 11, 13, true),
        );
        $service = new ResolveEstimateGenerationFailure(new FixedFailureResolutionAuthorizer(true), $transaction);

        $result = $service->handle($this->command());

        self::assertFalse($result->successful);
        self::assertSame(0, $transaction->resolved);
    }

    public function test_failure_action_delegates_to_application_service_without_model_mutation(): void
    {
        $source = $this->source(FailureResource::class);

        self::assertStringContainsString('ResolveEstimateGenerationFailure::class', $source);
        self::assertStringContainsString('FilamentPermission::ESTIMATE_GENERATION_OPERATE', $source);
        foreach (['->save()', '->update([', '->delete()', 'DB::', 'FailureStore::'] as $mutation) {
            self::assertStringNotContainsString($mutation, $source);
        }
    }

    public function test_failure_resource_is_otherwise_read_only_and_uses_navigation_contract(): void
    {
        $source = $this->source(FailureResource::class);

        self::assertSame(4, FailureResource::getNavigationSort());
        self::assertStringContainsString('return NavigationGroups::aiEstimator();', $source);
        self::assertStringContainsString('FilamentPermission::ESTIMATE_GENERATION_MONITOR', $source);
        self::assertStringContainsString('public static function canCreate(): bool', $source);
        self::assertStringContainsString('public static function canEdit(Model $record): bool', $source);
        self::assertStringContainsString('public static function canDelete(Model $record): bool', $source);
        self::assertStringNotContainsString('DeleteAction::make', $source);
        self::assertStringNotContainsString('BulkAction', $source);
    }

    private function command(): AdminFailureResolutionCommand
    {
        return new AdminFailureResolutionCommand(
            actorId: 5,
            failureId: '0190d0b8-91c1-7b25-9384-271f050f5c89',
            organizationId: 7,
            projectId: 9,
            sessionId: 11,
            expectedOccurrenceSequence: 13,
            idempotencyKey: '01J2X5B8YWFK9YD8Q6V1VZ4H3K',
        );
    }

    /** @param class-string $class */
    private function source(string $class): string
    {
        $source = file_get_contents((new ReflectionClass($class))->getFileName());
        self::assertIsString($source);

        return $source;
    }

    /** @return list<string> */
    private function safeColumns(): array
    {
        $columns = (new ReflectionClass(FailureResource::class))->getMethod('safeColumns')->invoke(null);
        self::assertIsArray($columns);

        return $columns;
    }
}

final readonly class FixedFailureResolutionAuthorizer implements AdminFailureResolutionAuthorizer
{
    public function __construct(private bool $allowed) {}

    public function canOperate(int $actorId): bool
    {
        return $this->allowed;
    }
}

final class RecordingFailureResolutionTransaction implements AdminFailureResolutionTransaction
{
    public bool $called = false;

    public int $resolved = 0;

    public function __construct(
        private readonly AdminFailureResolutionSnapshot $snapshot,
        private readonly ?AdminFailureResolutionResult $replay = null,
    ) {}

    public function execute(AdminFailureResolutionCommand $command, callable $resolution): AdminFailureResolutionResult
    {
        $this->called = true;
        if ($this->replay !== null) {
            return $this->replay;
        }

        return $resolution($this->snapshot, function (): void {
            $this->resolved++;
        });
    }
}
