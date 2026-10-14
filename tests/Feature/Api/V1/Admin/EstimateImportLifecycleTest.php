<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Features\BudgetEstimates\Services\Import\EstimateImportService;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Exceptions\ImportSessionStateException;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\ImportAccessContext;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\ImportPipelineService;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Jobs\ProcessEstimateImportJob;
use App\Http\Resources\Api\V1\Admin\Estimate\EstimateImportHistoryResource;
use App\Models\ImportSession;
use App\Models\Project;
use App\Models\User;
use App\Services\Storage\FileService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Mockery;
use Tests\Support\EstimateImportTestContext;
use Tests\TestCase;

final class EstimateImportLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_foreign_organization_and_project_sessions_are_hidden_before_source_read_or_dispatch(): void
    {
        $context = EstimateImportTestContext::create();
        $foreign = EstimateImportTestContext::create()->session();
        $otherProject = Project::factory()->create(['organization_id' => $context->organization->id]);
        $sameOrg = $context->session(['options' => ['project_id' => $otherProject->id]]);
        $files = Mockery::mock(FileService::class);
        $files->shouldNotReceive('readCurrent');
        $this->app->instance(FileService::class, $files);
        Bus::fake();
        $service = app(EstimateImportService::class);
        foreach ([$foreign, $sameOrg] as $session) {
            foreach (['preview', 'getImportStatus'] as $method) {
                try {
                    $method === 'preview' ? $service->preview($session->id, null, $context->access()) : $service->getImportStatus($session->id, $context->access());
                    self::fail('Foreign session must be hidden');
                } catch (ModelNotFoundException) {
                    self::assertSame('uploading', $session->fresh()->status);
                }
            }
        }
        Bus::assertNothingDispatched();
    }

    public function test_canonical_project_prevents_conflicting_legacy_fallback_and_legacy_success_remains_accessible(): void
    {
        $context = EstimateImportTestContext::create();
        $other = Project::factory()->create(['organization_id' => $context->organization->id]);
        $session = $context->session(['options' => ['project_id' => $other->id, 'estimate_settings' => ['project_id' => $context->project->id]]]);
        $service = app(EstimateImportService::class);
        try {
            $service->getImportStatus($session->id, $context->access());
            self::fail('Canonical scope must take precedence');
        } catch (ModelNotFoundException) {
            self::assertCount(0, $service->getImportHistory($context->organization->id, 50, $context->access()));
        }
        $session->update(['options' => ['estimate_settings' => ['project_id' => $context->project->id]], 'status' => 'completed', 'stats' => ['progress' => 100]]);
        self::assertSame('completed', $service->getImportStatus($session->id, $context->access())['status']);
    }

    public function test_missing_and_legacy_unbound_sessions_are_not_found(): void
    {
        $context = EstimateImportTestContext::create();
        $session = $context->session();
        $session->update(['options' => []]);
        $service = app(EstimateImportService::class);
        foreach ([$session->id, '00000000-0000-4000-8000-000000000000'] as $id) {
            try {
                $service->getImportStatus($id, $context->access());
                self::fail('Missing project scope must not bind a legacy session');
            } catch (ModelNotFoundException) {
                self::assertSame([], $session->fresh()->options);
            }
        }
    }

    public function test_import_permission_is_checked_for_direct_service_calls_and_validation_only(): void
    {
        $context = EstimateImportTestContext::create();
        $session = $context->session(['options' => ['format_handler' => 'custom_excel']]);
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->shouldReceive('canCurrent')->withArgs(static fn (User $actor, string $permission, array $scope): bool =>
            $actor->id === $context->user->id && $permission === 'budget-estimates.import'
            && $scope['project_id'] === $context->project->id && $scope['strict_project_scope'] === true)->andReturn(false);
        $this->app->instance(AuthorizationService::class, $authorization);
        Bus::fake();
        $this->expectException(AuthorizationException::class);
        try {
            app(EstimateImportService::class)->execute($session->id, [], ['name' => 'Test'], true, $context->access());
        } finally {
            self::assertSame('uploading', $session->fresh()->status);
            Bus::assertNothingDispatched();
        }
    }

    public function test_inactive_membership_blocks_previously_authorized_owner(): void
    {
        $context = EstimateImportTestContext::create();
        $session = $context->session();
        $context->organization->users()->updateExistingPivot($context->user->id, ['is_active' => false]);
        $this->expectException(AuthorizationException::class);
        app(EstimateImportService::class)->getImportStatus($session->id, $context->access());
    }

    public function test_project_import_role_is_not_inherited_by_accessible_sibling_projects(): void
    {
        $context = EstimateImportTestContext::create();
        $other = Project::factory()->create(['organization_id' => $context->organization->id]);
        UserRoleAssignment::query()->where('user_id', $context->user->id)->delete();
        UserRoleAssignment::assignRole($context->user, 'project_manager', AuthorizationContext::getProjectContext($context->project->id, $context->organization->id));
        $service = app(EstimateImportService::class);
        $own = $context->session();
        self::assertSame($own->id, $service->getSession($own->id, $context->access())->id);
        $sibling = $context->session(['options' => ['project_id' => $other->id]]);
        $this->expectException(AuthorizationException::class);
        $service->getImportStatus($sibling->id, new ImportAccessContext($context->user, $context->organization->id, $other->id));
    }

    public function test_first_import_establishes_project_context_for_genuine_licensed_owner(): void
    {
        $context = EstimateImportTestContext::create();
        AuthorizationContext::query()->where('type', AuthorizationContext::TYPE_PROJECT)->where('resource_id', $context->project->id)->delete();
        self::assertContains('budget-estimates', app(\App\Services\Entitlements\OrganizationEntitlementService::class)->getEffectiveModuleSlugs($context->organization->id));
        $session = $context->session();
        self::assertSame($session->id, app(EstimateImportService::class)->getSession($session->id, $context->access())->id);
        self::assertNotNull(AuthorizationContext::findProjectContext($context->project->id, $context->organization->id));
    }

    public function test_warm_permission_cache_does_not_authorize_revoked_or_expired_project_role(): void
    {
        foreach (['revoked', 'expired'] as $change) {
            $context = EstimateImportTestContext::create();
            UserRoleAssignment::query()->where('user_id', $context->user->id)->delete();
            $assignment = UserRoleAssignment::assignRole($context->user, 'project_manager', AuthorizationContext::getProjectContext($context->project->id, $context->organization->id));
            $session = $context->session(['status' => 'mapped', 'options' => ['format_handler' => 'custom_excel']]);
            self::assertTrue(app(AuthorizationService::class)->can($context->user, 'budget-estimates.import', [
                'context_type' => 'project', 'organization_id' => $context->organization->id,
                'project_id' => $context->project->id, 'strict_project_scope' => true,
            ]));
            $service = app(EstimateImportService::class);
            self::assertSame($session->id, $service->getSession($session->id, $context->access())->id);
            $change === 'revoked' ? $assignment->revoke() : $assignment->update(['expires_at' => now()->subMinute()]);
            Bus::fake();
            try {
                $service->execute($session->id, [], [], false, $context->access());
                self::fail('Stale cached grant cannot authorize a changed role');
            } catch (AuthorizationException) {
                self::assertSame('mapped', $session->fresh()->status);
                self::assertArrayNotHasKey('run_token', $session->options);
                Bus::assertNothingDispatched();
            }
        }
    }

    public function test_authorized_colleague_can_read_same_project_session(): void
    {
        $context = EstimateImportTestContext::create();
        $session = $context->session(['user_id' => User::factory()->create()->id]);
        self::assertSame($session->id, app(EstimateImportService::class)->getSession($session->id, $context->access())->id);
    }

    public function test_import_history_exposes_persisted_progress_and_result_counts(): void
    {
        $context = EstimateImportTestContext::create();
        $session = $context->session(['status' => 'completed', 'stats' => [
            'progress' => 100, 'estimate_id' => 42, 'result' => ['total_rows' => 5, 'items_created' => 3, 'skipped_rows' => 2],
        ]]);
        $history = app(EstimateImportService::class)->getImportHistory($context->organization->id, 50, $context->access());
        $result = (new EstimateImportHistoryResource($history->first()))->resolve();
        self::assertSame($session->id, $result['id']);
        self::assertSame(100, $result['progress']);
        self::assertSame(42, $result['estimate_id']);
        self::assertSame(['items_total' => 5, 'items_imported' => 3, 'items_skipped' => 2], $result['statistics']);
    }

    public function test_legacy_manual_mapping_in_all_sheets_survives_switch_and_only_changes_primary_sheet(): void
    {
        $context = EstimateImportTestContext::create();
        Storage::fake('s3');
        $workbook = new Spreadsheet;
        foreach (['Rough finish' => [2, 10, 999], 'Clean finish' => [3, 10, 55]] as $title => $values) {
            $sheet = $title === 'Rough finish' ? $workbook->getActiveSheet() : $workbook->createSheet();
            $sheet->setTitle($title);
            $sheet->fromArray(['number', 'type', 'description', 'unit', 'qty', 'price', 'total'], null, 'A1');
            $sheet->fromArray([1, 'работы', $title, 'м2', ...$values], null, 'A2');
        }
        $temporaryPath = sys_get_temp_dir().'/estimate-import-manual-'.bin2hex(random_bytes(8)).'.xlsx';
        try {
            (new Xlsx($workbook))->save($temporaryPath);
            $path = 'org-'.$context->organization->id.'/estimate-imports/manual.xlsx';
            Storage::disk('s3')->put($path, (string) file_get_contents($temporaryPath));
            $session = $context->session(['file_path' => $path]);
            $service = app(EstimateImportService::class);
            $structure = $service->detectFormat($session->id, null, $context->access());
            $mapping = $structure['column_mapping'];
            unset($mapping['total_price'], $mapping['total_amount'], $mapping['current_total_amount']);
            $preview = $service->preview($session->id, $mapping, $context->access());
            self::assertCount(2, $preview->items);
            self::assertSame(75.0, $preview->getTotalAmount());
            $options = $session->fresh()->options;
            self::assertNull($options['worksheet_index'] ?? null);
            self::assertTrue($options['worksheet_mappings'][0]['manual_mapping']);
            self::assertSame(0, $options['worksheet_mappings'][0]['worksheet_index']);
            self::assertArrayNotHasKey('total_price', $options['worksheet_mappings'][0]['column_mapping']);
            $service->detectFormat($session->id, null, $context->access(), ['worksheet_index' => 1]);
            $service->detectFormat($session->id, null, $context->access(), ['worksheet_index' => null]);
            $restored = $service->preview($session->id, null, $context->access());
            self::assertCount(2, $restored->items);
            self::assertSame(75.0, $restored->getTotalAmount());
            self::assertNull($session->fresh()->options['worksheet_index']);
        } finally {
            $workbook->disconnectWorksheets();
            if (is_file($temporaryPath)) {
                unlink($temporaryPath);
            }
        }
    }

    public function test_execute_rejects_route_body_project_mismatch_without_mutation_or_dispatch(): void
    {
        $context = EstimateImportTestContext::create();
        $session = $context->session(['options' => ['format_handler' => 'custom_excel']]);
        Bus::fake();
        try {
            app(EstimateImportService::class)->execute($session->id, [], ['project_id' => $context->project->id + 1], false, $context->access());
            self::fail('Project mismatch must be rejected');
        } catch (ImportSessionStateException $exception) {
            self::assertSame('import_project_mismatch', $exception->reasonCode);
        }
        self::assertSame('uploading', $session->fresh()->status);
        Bus::assertNothingDispatched();
    }

    public function test_duplicate_execute_preserves_options_and_dispatches_once(): void
    {
        $context = EstimateImportTestContext::create();
        $session = $context->session(['status' => 'mapped', 'options' => ['format_handler' => 'custom_excel']]);
        Bus::fake();
        $service = app(EstimateImportService::class);
        $service->execute($session->id, [], ['name' => 'First'], false, $context->access());
        $options = $session->fresh()->options;
        $service->execute($session->id, ['skip_unmatched' => true], ['name' => 'Second'], true, $context->access());
        self::assertSame($options, $session->fresh()->options);
        Bus::assertDispatchedTimes(ProcessEstimateImportJob::class, 1);
        $this->expectException(ImportSessionStateException::class);
        $service->preview($session->id, ['item_name' => 'B'], $context->access());
    }

    public function test_duplicate_delivery_and_stale_attempt_cannot_run_pipeline_twice(): void
    {
        $context = EstimateImportTestContext::create();
        $session = $context->session(['status' => 'queued', 'options' => ['run_token' => 'attempt-one']]);
        $pipeline = Mockery::mock(ImportPipelineService::class);
        $pipeline->shouldReceive('run')->once()->andReturnUsing(static fn (ImportSession $claimed) => $claimed->update(['status' => 'completed', 'stats' => ['estimate_id' => 42, 'progress' => 100]]));
        $job = new ProcessEstimateImportJob($session->id, 'attempt-one');
        $job->handle($pipeline);
        $job->handle($pipeline);
        self::assertSame('completed', $session->refresh()->status);
        $session->update(['status' => 'queued', 'options' => ['run_token' => 'attempt-two']]);
        self::assertSame('queued', $session->refresh()->status);
        self::assertSame('attempt-two', $session->options['run_token']);
        $job->handle($pipeline);
        self::assertSame('queued', $session->fresh()->status);
    }

    public function test_old_serialized_queued_job_without_token_fails_safely_before_pipeline(): void
    {
        $context = EstimateImportTestContext::create();
        $session = $context->session(['status' => 'queued', 'stats' => ['progress' => 10]]);
        $pipeline = Mockery::mock(ImportPipelineService::class);
        $pipeline->shouldNotReceive('run');
        Cache::put("import_session_progress_{$session->id}", 45, 60);
        $this->deserializeLegacyJob($session->id)->handle($pipeline);
        self::assertSame('failed', $session->refresh()->status);
        self::assertSame(trans_message('estimate.import_worker_interrupted'), $session->error_message);
        self::assertSame('import_processing_failed', $session->stats['reason_code']);
        self::assertNotEmpty($session->stats['correlation_id']);
        self::assertFalse(Cache::has("import_session_progress_{$session->id}"));
    }

    public function test_old_serialized_job_preserves_completed_and_active_sessions(): void
    {
        $context = EstimateImportTestContext::create();
        $pipeline = Mockery::mock(ImportPipelineService::class);
        $pipeline->shouldNotReceive('run');
        foreach (['completed', 'parsing', 'processing'] as $status) {
            $session = $context->session(['status' => $status, 'stats' => ['progress' => 50]]);
            Cache::put("import_session_progress_{$session->id}", 60, 60);
            $this->deserializeLegacyJob($session->id)->handle($pipeline);
            self::assertSame($status, $session->refresh()->status);
            self::assertSame(['progress' => 50], $session->stats);
            self::assertNull($session->error_message);
            self::assertSame(60, Cache::get("import_session_progress_{$session->id}"));
        }
    }

    public function test_old_serialized_job_cannot_overwrite_newer_queued_attempt(): void
    {
        $context = EstimateImportTestContext::create();
        $session = $context->session(['status' => 'queued', 'options' => ['run_token' => 'new-attempt'], 'stats' => ['progress' => 0]]);
        $pipeline = Mockery::mock(ImportPipelineService::class);
        $pipeline->shouldNotReceive('run');
        Cache::put("import_session_progress_{$session->id}", 5, 60);
        $this->deserializeLegacyJob($session->id)->handle($pipeline);
        self::assertSame('queued', $session->refresh()->status);
        self::assertSame('new-attempt', $session->options['run_token']);
        self::assertSame(['progress' => 0], $session->stats);
        self::assertNull($session->error_message);
        self::assertSame(5, Cache::get("import_session_progress_{$session->id}"));
    }

    private function deserializeLegacyJob(string $sessionId): ProcessEstimateImportJob
    {
        $class = ProcessEstimateImportJob::class;
        $payload = sprintf('O:%d:"%s":1:{s:9:"sessionId";s:%d:"%s";}', strlen($class), $class, strlen($sessionId), $sessionId);
        $job = unserialize($payload, ['allowed_classes' => [$class]]);
        self::assertInstanceOf(ProcessEstimateImportJob::class, $job);
        self::assertNull($job->runToken);

        return $job;
    }

    public function test_terminal_success_returns_existing_result_and_database_progress_over_stale_cache(): void
    {
        $context = EstimateImportTestContext::create();
        $service = app(EstimateImportService::class);
        Bus::fake();
        foreach (['completed', 'validated'] as $status) {
            $session = $context->session(['status' => $status, 'options' => ['format_handler' => 'custom_excel'], 'stats' => ['progress' => 100, 'result' => ['items_created' => $status === 'completed' ? 1 : 0]]]);
            Cache::put("import_session_progress_{$session->id}", 10, 60);
            $result = $service->execute($session->id, [], [], $status === 'validated', $context->access());
            self::assertSame($status, $result['status']);
            self::assertSame(100, $result['progress']);
            self::assertSame($session->stats['result'], $result['result']);
        }
        Bus::assertNothingDispatched();
    }

    public function test_validated_dry_run_can_start_real_import_once(): void
    {
        $context = EstimateImportTestContext::create();
        $session = $context->session(['status' => 'validated', 'options' => ['format_handler' => 'custom_excel', 'validate_only' => true], 'stats' => ['progress' => 100]]);
        Bus::fake();
        $service = app(EstimateImportService::class);
        $result = $service->execute($session->id, [], [], false, $context->access());
        self::assertSame('queued', $result['status']);
        self::assertFalse($session->fresh()->options['validate_only']);
        $service->execute($session->id, [], [], false, $context->access());
        Bus::assertDispatchedTimes(ProcessEstimateImportJob::class, 1);
    }

    public function test_failed_retry_requires_explicit_flag_and_creates_new_attempt(): void
    {
        $context = EstimateImportTestContext::create();
        $session = $context->session(['status' => 'failed', 'options' => ['format_handler' => 'custom_excel', 'run_token' => 'old']]);
        Bus::fake();
        $service = app(EstimateImportService::class);
        try {
            $service->execute($session->id, [], [], false, $context->access());
            self::fail('Retry must be explicit');
        } catch (ImportSessionStateException $exception) {
            self::assertSame('import_retry_required', $exception->reasonCode);
        }
        $service->execute($session->id, [], [], false, $context->access(), true);
        self::assertNotSame('old', $session->fresh()->options['run_token']);
        Bus::assertDispatchedTimes(ProcessEstimateImportJob::class, 1);
    }

    public function test_expired_preparation_cannot_execute_and_cleanup_preserves_queued_source_and_history(): void
    {
        $context = EstimateImportTestContext::create();
        $expired = $context->session(['created_at' => now()->subHours(25), 'file_path' => 'org-'.$context->organization->id.'/estimate-imports/expired.xlsx', 'options' => ['format_handler' => 'custom_excel']]);
        $queued = $context->session(['created_at' => now()->subHours(25), 'status' => 'queued', 'file_path' => 'org-'.$context->organization->id.'/estimate-imports/queued.xlsx']);
        $files = Mockery::mock(FileService::class);
        $files->shouldReceive('existsCurrent')->once()->with($expired->file_path)->andReturn(true);
        $files->shouldReceive('deleteCurrent')->once()->with($expired->file_path);
        $this->app->instance(FileService::class, $files);
        $service = app(EstimateImportService::class);
        Bus::fake();
        try {
            $service->execute($expired->id, [], [], false, $context->access());
            self::fail('Expired session cannot execute');
        } catch (ImportSessionStateException $exception) {
            self::assertSame(410, $exception->httpStatus);
        }
        self::assertSame(1, $service->cleanupInactiveSources());
        self::assertSame('expired', $expired->refresh()->status);
        self::assertNull($expired->file_path);
        self::assertNotNull($queued->fresh()->file_path);
        self::assertCount(2, $service->getImportHistory($context->organization->id, 50, $context->access()));
        Bus::assertNothingDispatched();
    }

    public function test_abrupt_worker_death_and_failed_callback_recover_safely(): void
    {
        $context = EstimateImportTestContext::create();
        $session = $context->session(['status' => 'parsing', 'options' => ['run_token' => 'current'], 'stats' => ['progress' => 5, 'worker_deadline_at' => now()->subMinute()->timestamp]]);
        $service = app(EstimateImportService::class);
        self::assertSame('failed', $service->getImportStatus($session->id, $context->access())['status']);
        self::assertSame('import_worker_interrupted', $session->fresh()->stats['reason_code']);
        $session->update(['status' => 'completed']);
        (new ProcessEstimateImportJob($session->id, 'current'))->failed(new \RuntimeException('private/source/file'));
        self::assertSame('completed', $session->fresh()->status);
    }
}
