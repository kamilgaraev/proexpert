<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Core\Reporting\Application\Contracts\CreateReportExportAction;
use App\BusinessModules\Core\Reporting\Application\Contracts\CreateReportRunAction;
use App\BusinessModules\Core\Reporting\Application\Exports\ReportArtifactVersionInventory;
use App\BusinessModules\Core\Reporting\Infrastructure\Jobs\GenerateReportExportJob;
use App\BusinessModules\Core\Reporting\Infrastructure\Jobs\MaterializeReportRunJob;
use App\BusinessModules\Features\AIAssistant\Models\AssistantCoreReportTask;
use App\BusinessModules\Features\AIAssistant\Models\AssistantRequest;
use App\BusinessModules\Features\AIAssistant\Services\AIAssistantService;
use App\BusinessModules\Features\AIAssistant\Services\AIPermissionChecker;
use App\BusinessModules\Features\AIAssistant\Services\AIToolRegistry;
use App\BusinessModules\Features\AIAssistant\Services\AssistantCurrentRequest;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantPublishedReportCatalog;
use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantPublishedReportProcessor;
use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantPublishedReportTasks;
use App\BusinessModules\Features\KnowledgeHub\Enums\KnowledgeSurface;
use App\Models\Project;
use App\Models\User;
use App\Services\Modules\PackageCatalogService;
use App\Services\Storage\DTO\MultipartPart;
use App\Services\Storage\DTO\MultipartUpload;
use App\Services\Storage\DTO\StoredFile;
use App\Services\Storage\FileService;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

#[Group('postgres-contract')]
final class AssistantPublishedReportTasksTest extends TestCase
{
    protected $connectionsToTransact = [];

    private AssistantRealAuthorizationFixture $fixture;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['cache.default' => 'array']);
        $this->fixture = Model::withoutEvents(fn () => AssistantRealAuthorizationFixture::create(array_column(app(PackageCatalogService::class)->allPackages(), 'slug')));
        $this->project = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $this->fixture->organization->id]));
        app(AssistantDataAccessPolicy::class)->setTrustedSurface(KnowledgeSurface::ADMIN);
    }

    public static function interruptions(): array
    {
        return [['normal'], ['run'], ['export']];
    }

    #[DataProvider('interruptions')]
    public function test_source_only_actor_gets_real_nonempty_excel_and_reuses_native_work_after_interruption(string $interruption): void
    {
        $actor = Model::withoutEvents(fn () => $this->fixture->addMember([
            'ai-assistant' => ['ai_assistant.chat', 'ai_assistant.reports.generate'],
            'project-management' => ['projects.view'],
            'reports' => ['reports.production_progress.view', 'reports.production_progress.export'],
        ]));
        $actor->assignedProjects()->attach($this->project->id, ['is_active' => true, 'role' => 'member']);
        $organizationId = (int) $this->fixture->organization->id;
        self::assertFalse(app(AssistantDataAccessPolicy::class)->canCurrentPermission($actor, $organizationId, 'reports.view'));
        $checkpoint = DB::table('production_acceptance_history_checkpoints')->where('organization_id', $organizationId)->sole();
        $acceptedAt = (new DateTimeImmutable((string) $checkpoint->completed_at))->setTimezone(new DateTimeZone('UTC'))->modify('monday next week')->modify('+1 day')->setTime(8, 0);
        $this->seedAcceptedSource($organizationId, (int) $this->project->id, $acceptedAt);
        $root = $this->root($actor, $acceptedAt->modify('+1 day'));
        $arguments = array_replace($this->arguments(), ['filters_json' => json_encode(['period_from' => $acceptedAt->format('Y-m-d'), 'period_to' => $acceptedAt->format('Y-m-d')], JSON_THROW_ON_ERROR)]);
        $tasks = app(AssistantPublishedReportTasks::class);
        self::assertTrue(app(AIPermissionChecker::class)->canExecuteTool($actor, 'generate_published_report', $arguments));
        $catalog = app(AssistantPublishedReportCatalog::class)->read($actor, $organizationId, 'accepted_production_progress');
        self::assertSame('accepted_production_progress', $catalog['items'][0]['report_code']);
        self::assertNotContains('row_key', array_column($catalog['items'][0]['columns'], 'id'));
        self::assertNotContains('accepted_amount_minor', array_column($catalog['items'][0]['columns'], 'id'));
        $this->denied(fn () => $tasks->create(array_replace($arguments, ['sort' => ['field' => 'accepted_amount_minor', 'direction' => 'desc']]), $actor, $organizationId));
        self::assertSame(0, AssistantCoreReportTask::query()->where('assistant_request_id', $root->id)->count());
        $tool = app(AIToolRegistry::class)->getTool('generate_published_report');
        self::assertNotNull($tool);
        $first = $tool->execute($arguments, $actor, $this->fixture->organization);
        self::assertSame($first, $tasks->create($arguments, $actor, $organizationId));
        $task = AssistantCoreReportTask::query()->findOrFail($first['report_task_id']);
        self::assertSame($root->created_at->format('Y-m-d\TH:i:s.uP'), $task->parameters['as_of']);
        self::assertNull($tasks->read($task->id, $actor, $organizationId)['export_id']);
        $this->assertCanonicalArtifact($tasks, $task, $actor, $organizationId);
        $root->update(['status' => 'completed']);
        $actor->updateQuietly(['current_organization_id' => $this->fixture->foreignOrganization->id]);
        $this->app->instance(FileService::class, $files = new PublishedTaskFileService);
        $inventory = $this->createStub(ReportArtifactVersionInventory::class);
        $inventory->method('forExport')->willReturn([]);
        $this->app->instance(ReportArtifactVersionInventory::class, $inventory);
        $processor = app(AssistantPublishedReportProcessor::class);
        $runAction = app(CreateReportRunAction::class);
        $exportAction = app(CreateReportExportAction::class);
        $didInterrupt = false;
        if ($interruption !== 'normal') {
            $interface = $interruption === 'run' ? CreateReportRunAction::class : CreateReportExportAction::class;
            $original = $interruption === 'run' ? $runAction : $exportAction;
            $wrapper = $this->createMock($interface);
            $wrapper->method('handle')->willReturnCallback(function (...$arguments) use ($original, &$didInterrupt) {
                self::assertSame(0, DB::connection()->transactionLevel());
                $result = $original->handle(...$arguments);
                if (! $didInterrupt) {
                    $didInterrupt = true;
                    throw new \RuntimeException('process_interrupted_before_attach');
                }

                return $result;
            });
            $processor = app()->make(AssistantPublishedReportProcessor::class, [$interruption === 'run' ? 'createRuns' : 'createExports' => $wrapper]);
        }
        $queue = new SyncQueue;
        $queue->setContainer($this->app);
        $this->process($processor, $task);
        if ($interruption === 'run') {
            self::assertNull($task->fresh()->run_id);
            $this->process($processor, $task);
        }
        $task->refresh();
        self::assertNotNull($task->run_id);
        $queue->push(new MaterializeReportRunJob($task->run_id));
        $this->process($processor, $task);
        if ($interruption === 'export') {
            self::assertNull($task->fresh()->export_id);
            $this->process($processor, $task);
        }
        $task->refresh();
        self::assertNotNull($task->export_id);
        $queue->push(new GenerateReportExportJob($task->export_id));
        $this->process($processor, $task);
        $this->process($processor, $task);
        self::assertSame('ready', $task->fresh()->status);
        self::assertSame(1, DB::table('report_runs')->where('organization_id', $organizationId)->count());
        self::assertSame(1, DB::table('report_exports')->where('run_id', $task->run_id)->count());
        $actor->updateQuietly(['current_organization_id' => $organizationId]);
        $state = $tasks->read($task->id, $actor, $organizationId);
        self::assertSame('ready', $state['status']);
        self::assertSame($task->export_id, $state['export_id']);
        $this->assertExcel($files->bytes, $acceptedAt->format('Y-m-d'));
        self::assertSame(0, DB::table('report_saved_views')->where('organization_id', $organizationId)->count());
        $actor->organizations()->updateExistingPivot($organizationId, ['is_active' => false]);
        $this->expectException(AuthorizationException::class);
        $tasks->read($task->id, $actor, $organizationId);
    }

    public function test_ownership_surface_current_request_and_project_are_enforced_before_publication(): void
    {
        $actor = $this->fixture->owner;
        $org = (int) $this->fixture->organization->id;
        $root = $this->root($actor);
        $tasks = app(AssistantPublishedReportTasks::class);
        $result = $tasks->create($this->arguments(), $actor, $org);
        $task = AssistantCoreReportTask::query()->findOrFail($result['report_task_id']);
        $this->denied(fn () => $tasks->read($task->id, $this->fixture->member, $org));
        $this->denied(fn () => $tasks->read($task->id, $this->fixture->foreignOwner, (int) $this->fixture->foreignOrganization->id));
        app(AssistantCurrentRequest::class)->replace(null);
        $this->denied(fn () => $tasks->artifact($task->id, $actor, $org));
        app(AssistantCurrentRequest::class)->replace($root);
        app(AssistantDataAccessPolicy::class)->setTrustedSurface(KnowledgeSurface::LK);
        $this->denied(fn () => $tasks->create($this->arguments(), $actor, $org));
        app(AssistantDataAccessPolicy::class)->setTrustedSurface(KnowledgeSurface::ADMIN);
        $replacement = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $org]));
        $this->denied(fn () => $tasks->create(array_replace($this->arguments(), ['project_id' => (int) $replacement->id]), $actor, $org));
        $task->update(['parameters' => array_replace($task->parameters, ['title' => 'Подмена'])]);
        $this->denied(fn () => $tasks->artifact($task->id, $actor, $org));
    }

    public function test_cancellation_deadline_lease_and_recovery_do_not_create_extra_work(): void
    {
        $actor = $this->fixture->owner;
        $org = (int) $this->fixture->organization->id;
        $root = $this->root($actor);
        $tasks = app(AssistantPublishedReportTasks::class);
        $result = $tasks->create($this->arguments(), $actor, $org);
        $task = AssistantCoreReportTask::query()->findOrFail($result['report_task_id']);
        $processor = app(AssistantPublishedReportProcessor::class);
        $task->update(['lease_token' => (string) Str::uuid(), 'lease_expires_at' => now()->addMinute()]);
        $processor->process($task->id);
        self::assertNull($task->fresh()->run_id);
        $task->update(['lease_expires_at' => now()->subSecond()]);
        $processor->recover();
        Queue::assertPushed(\App\BusinessModules\Features\AIAssistant\Jobs\GenerateAssistantPublishedReport::class, fn ($job): bool => $job->taskId === $task->id);
        $root->update(['cancel_requested_at' => now()]);
        self::assertSame('cancelled', $tasks->read($task->id, $actor, $org)['status']);
        $processor->process($task->id);
        self::assertSame('cancelled', $task->fresh()->status);
        self::assertNull($task->fresh()->run_id);
        $root = $this->root($actor);
        $result = $tasks->create($this->arguments(), $actor, $org);
        $task = AssistantCoreReportTask::query()->findOrFail($result['report_task_id']);
        $task->update(['execution_expires_at' => now()->subSecond()]);
        $before = $task->fresh()->getAttributes();
        self::assertSame('expired', $tasks->read($task->id, $actor, $org)['status']);
        self::assertSame($before, $task->fresh()->getAttributes());
        $processor->process($task->id);
        self::assertSame('expired', $task->fresh()->status);
        $root->delete();
        self::assertNull($task->fresh());
    }

    public function test_real_admin_get_returns_read_only_owner_status_and_rechecks_source_permission(): void
    {
        $actor = $this->fixture->owner;
        $org = (int) $this->fixture->organization->id;
        $this->root($actor);
        $tasks = app(AssistantPublishedReportTasks::class);
        $result = $tasks->create($this->arguments(), $actor, $org);
        $task = AssistantCoreReportTask::query()->findOrFail($result['report_task_id']);
        $headers = $this->headers($actor, $org);
        $before = $task->getAttributes();
        $this->withHeaders($headers)->getJson('/api/v1/admin/ai-assistant/report-tasks/'.$task->id)
            ->assertOk()->assertJsonPath('success', true)->assertJsonPath('data.id', $task->id)
            ->assertJsonPath('data.status', 'queued')->assertJsonPath('data.export_id', null);
        self::assertSame($before, $task->fresh()->getAttributes());
        self::assertNull($task->fresh()->run_id);
        $this->withHeaders($this->headers($this->fixture->member, $org))->getJson('/api/v1/admin/ai-assistant/report-tasks/'.$task->id)->assertNotFound();
        $this->fixture->ownerAssignment->updateQuietly(['is_active' => false]);
        $this->withHeaders($headers)->getJson('/api/v1/admin/ai-assistant/report-tasks/'.$task->id)->assertForbidden();
    }

    public function test_invalid_filters_columns_formats_and_request_identity_cannot_create_tasks(): void
    {
        $actor = $this->fixture->owner;
        $org = (int) $this->fixture->organization->id;
        $root = $this->root($actor);
        $tasks = app(AssistantPublishedReportTasks::class);
        foreach ([['columns' => ['row_key']], ['columns' => ['drill']], ['format' => 'pdf'],
            ['filters_json' => '[]'], ['filters_json' => '{"project_id":123}'],
            ['filters_json' => '{"organization_id":123}'], ['sort' => ['field' => 'missing', 'direction' => 'asc']],
            ['scope' => 'organization', 'project_id' => null]] as $change) {
            try {
                $tasks->create(array_replace($this->arguments(), $change), $actor, $org);
                self::fail('Expected invalid published report input');
            } catch (AuthorizationException|\InvalidArgumentException|\App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException) {
                self::assertSame(0, AssistantCoreReportTask::query()->where('assistant_request_id', $root->id)->count());
            }
        }
        $result = $tasks->create($this->arguments(), $actor, $org);
        $task = AssistantCoreReportTask::query()->findOrFail($result['report_task_id']);
        $this->root($actor);
        $this->denied(fn () => $tasks->artifact($task->id, $actor, $org));
    }

    private function headers(User $actor, int $organizationId): array
    {
        $sessionId = (string) Str::uuid();
        \App\Models\UserAuthSession::query()->create(['user_id' => $actor->id, 'organization_id' => $organizationId,
            'session_uuid' => $sessionId, 'device_fingerprint' => hash('sha256', $sessionId), 'device_name' => 'Published report test',
            'ip_address' => '127.0.0.1', 'risk_score' => 0, 'risk_flags' => [], 'status' => \App\Enums\AuthSessionStatus::Active,
            'first_seen_at' => now(), 'last_seen_at' => now()]);
        $tokens = app(\App\Services\Auth\WebAuthTokenService::class)->issue($actor, 'admin', $sessionId, $organizationId, false);

        return ['Authorization' => 'Bearer '.$tokens->accessToken, 'Accept' => 'application/json', 'Origin' => 'https://admin.1мост.рф'];
    }

    public function test_long_running_report_backs_off_queue_checks_and_http_polling(): void
    {
        $actor = $this->fixture->owner;
        $org = (int) $this->fixture->organization->id;
        $checkpoint = DB::table('production_acceptance_history_checkpoints')->where('organization_id', $org)->sole();
        $acceptedAt = (new DateTimeImmutable((string) $checkpoint->completed_at))->setTimezone(new DateTimeZone('UTC'))->modify('monday next week')->modify('+1 day')->setTime(8, 0);
        $this->seedAcceptedSource($org, (int) $this->project->id, $acceptedAt);
        $this->root($actor, $acceptedAt->modify('+1 day'));
        $tasks = app(AssistantPublishedReportTasks::class);
        $arguments = array_replace($this->arguments(), ['filters_json' => json_encode(['period_from' => $acceptedAt->format('Y-m-d'), 'period_to' => $acceptedAt->format('Y-m-d')], JSON_THROW_ON_ERROR)]);
        $result = $tasks->create($arguments, $actor, $org);
        $task = AssistantCoreReportTask::query()->findOrFail($result['report_task_id']);
        $processor = app(AssistantPublishedReportProcessor::class);
        $this->process($processor, $task);
        self::assertNotNull($task->fresh()->run_id);
        self::assertSame(0, $task->fresh()->failure_count);
        $task->refresh()->update(['created_at' => now()->subMinutes(10)]);
        $this->process($processor, $task);
        $delay = $task->fresh()->retry_at->getTimestamp() - now()->getTimestamp();
        self::assertGreaterThanOrEqual(59, $delay);
        self::assertLessThanOrEqual(60, $delay);
        self::assertSame(30000, $tasks->read($task->id, $actor, $org)['poll_after_ms']);
        self::assertNotNull($task->fresh()->run_id);
        self::assertNull($task->fresh()->export_id);
    }

    public static function stoppedWork(): array
    {
        return [['run', 'cancel'], ['run', 'failed'], ['run', 'expiry'], ['export', 'cancel'],
            ['export', 'expiry'], ['run_race', 'cancel'], ['export_race', 'cancel'], ['run_retry', 'cancel']];
    }

    #[DataProvider('stoppedWork')]
    public function test_stopped_task_cancels_its_native_work(string $stage, string $reason): void
    {
        $actor = $this->fixture->owner;
        $org = (int) $this->fixture->organization->id;
        $checkpoint = DB::table('production_acceptance_history_checkpoints')->where('organization_id', $org)->sole();
        $acceptedAt = (new DateTimeImmutable((string) $checkpoint->completed_at))->setTimezone(new DateTimeZone('UTC'))->modify('monday next week')->modify('+1 day')->setTime(8, 0);
        $this->seedAcceptedSource($org, (int) $this->project->id, $acceptedAt);
        $root = $this->root($actor, $acceptedAt->modify('+1 day'));
        $tasks = app(AssistantPublishedReportTasks::class);
        $arguments = array_replace($this->arguments(), ['filters_json' => json_encode(['period_from' => $acceptedAt->format('Y-m-d'), 'period_to' => $acceptedAt->format('Y-m-d')], JSON_THROW_ON_ERROR)]);
        $result = $tasks->create($arguments, $actor, $org);
        $task = AssistantCoreReportTask::query()->findOrFail($result['report_task_id']);
        $cancelCalls = 0;
        foreach ([\App\BusinessModules\Core\Reporting\Application\Contracts\CancelReportRunAction::class,
            \App\BusinessModules\Core\Reporting\Application\Contracts\CancelReportExportAction::class] as $interface) {
            $original = app($interface);
            $wrapper = $this->createMock($interface);
            $wrapper->method('handle')->willReturnCallback(function (...$arguments) use ($original, $stage, &$cancelCalls) {
                self::assertSame(0, DB::connection()->transactionLevel());
                $cancelCalls++;
                if ($stage === 'run_retry' && $cancelCalls === 1) {
                    throw new \RuntimeException('temporary_cancel_failure');
                }

                return $original->handle(...$arguments);
            });
            app()->instance($interface, $wrapper);
        }
        if (str_ends_with($stage, '_race')) {
            $interface = $stage === 'run_race' ? CreateReportRunAction::class : CreateReportExportAction::class;
            $original = app($interface);
            $wrapper = $this->createMock($interface);
            $wrapper->method('handle')->willReturnCallback(function (...$arguments) use ($original, $root) {
                $result = $original->handle(...$arguments);
                $root->update(['cancel_requested_at' => now()]);

                return $result;
            });
            app()->instance($interface, $wrapper);
        }
        $processor = app(AssistantPublishedReportProcessor::class);
        $this->process($processor, $task);
        self::assertNotNull($task->fresh()->run_id);
        $hasExport = str_starts_with($stage, 'export');
        if ($hasExport) {
            $queue = new SyncQueue;
            $queue->setContainer($this->app);
            $queue->push(new MaterializeReportRunJob($task->fresh()->run_id));
            $this->process($processor, $task);
            self::assertNotNull($task->fresh()->export_id);
        }
        if ($reason === 'expiry') {
            $task->fresh()->update(['execution_expires_at' => now()->subSecond()]);
        } elseif ($reason === 'failed') {
            $root->update(['status' => 'failed']);
        } else {
            $root->update(['cancel_requested_at' => now()]);
        }
        $this->process($processor, $task);
        self::assertSame($reason === 'expiry' ? 'expired' : 'cancelled', $task->fresh()->status);
        if ($stage === 'run_retry') {
            self::assertSame('queued', DB::table('report_runs')->where('id', $task->fresh()->run_id)->value('status'));
            self::assertNull($task->fresh()->core_cleanup_completed_at);
            $task->fresh()->update(['retry_at' => now()->subSecond()]);
            Queue::fake();
            $processor->recover();
            Queue::assertPushed(\App\BusinessModules\Features\AIAssistant\Jobs\GenerateAssistantPublishedReport::class,
                fn ($job): bool => $job->taskId === $task->id);
            $this->process($processor, $task);
        }
        self::assertSame($hasExport ? 'ready' : 'cancelled', DB::table('report_runs')->where('id', $task->fresh()->run_id)->value('status'));
        if ($hasExport) {
            self::assertSame('cancelled', DB::table('report_exports')->where('id', $task->fresh()->export_id)->value('status'));
        }
        self::assertNotNull($task->fresh()->core_cleanup_completed_at);
        self::assertSame($stage === 'run_retry' ? 2 : 1, $cancelCalls);
        $before = $task->fresh()->getAttributes();
        $processor->process($task->id);
        self::assertSame($before, $task->fresh()->getAttributes());
    }

    private function root(User $actor, ?DateTimeImmutable $asOf = null): AssistantRequest
    {
        $root = AssistantRequest::query()->create(['request_id' => (string) Str::uuid(), 'organization_id' => $this->fixture->organization->id,
            'user_id' => $actor->id, 'request_hash' => hash('sha256', Str::random()), 'profile' => 'short', 'status' => 'running',
            'stage' => 'tools', 'max_calls' => 10, 'approved_max_minor' => 100, 'heartbeat_at' => now(), 'lease_expires_at' => now()->addMinutes(5),
            'surface' => 'admin', 'payload' => ['message' => 'Сформируй Excel о принятой выработке текущего проекта',
                'context' => ['entity_refs' => [['entity_type' => 'project', 'entity_id' => $this->project->id]]]],
            'created_at' => $asOf ?? now()]);
        $root->refresh();
        app(AssistantCurrentRequest::class)->replace($root);

        return $root;
    }

    private function arguments(): array
    {
        return ['report_code' => 'accepted_production_progress', 'scope' => 'project', 'project_id' => (int) $this->project->id,
            'timezone' => 'Europe/Moscow', 'format' => 'xlsx', 'period_mode' => 'fixed_filters',
            'filters_json' => json_encode(['period_from' => now()->startOfMonth()->format('Y-m-d'), 'period_to' => now()->format('Y-m-d')], JSON_THROW_ON_ERROR),
            'columns' => ['accepted_quantity', 'event_status', 'recognized_on']];
    }

    private function process(AssistantPublishedReportProcessor $processor, AssistantCoreReportTask $task): void
    {
        $task->fresh()?->update(['retry_at' => now()->subSecond()]);
        $processor->process((string) $task->id);
    }

    private function assertCanonicalArtifact(AssistantPublishedReportTasks $tasks, AssistantCoreReportTask $task, User $actor, int $org): void
    {
        $artifact = $tasks->artifact($task->id, $actor, $org);
        self::assertSame('core_report_task', $artifact['artifact_type']);
        self::assertSame('/ai-assistant/report-tasks/'.$task->id, $artifact['url']);
        self::assertArrayNotHasKey('export_id', $artifact);
        $service = (new \ReflectionClass(AIAssistantService::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod($service, 'filterAgentArtifactsForOrganization');
        self::assertSame([$artifact], $method->invoke($service, $org, [['artifact_type' => 'core_report_task', 'task_id' => $task->id, 'url' => 'https://untrusted.invalid/file']], (int) $actor->id));
    }

    private function denied(callable $operation): void
    {
        try {
            $operation();
            self::fail('Expected current authorization denial');
        } catch (AuthorizationException|\Illuminate\Database\Eloquent\ModelNotFoundException) {
            self::assertTrue(true);
        }
    }

    private function seedAcceptedSource(int $organizationId, int $projectId, DateTimeImmutable $acceptedAt): void
    {
        $member = ['contractor_id' => 19, 'source_line_id' => 91, 'source_line_type' => 'performance_act_line',
            'unit_code' => 'm3', 'work_id' => 77, 'zone' => 'A'];
        $ownerId = DB::table('production_acceptance_owner_versions')->insertGetId([
            'organization_id' => $organizationId, 'project_id' => $projectId, 'contract_id' => 21, 'performance_act_id' => 51,
            'version' => 1, 'event_type' => 'accepted', 'effective_at' => $acceptedAt,
            'source_event_id' => 'subscription-lifecycle:'.$organizationId, 'source_hash' => hash('sha256', 'owner:'.$organizationId),
            'members' => json_encode([$member], JSON_THROW_ON_ERROR),
        ]);
        DB::table('production_acceptance_owner_members')->insert([
            'owner_version_id' => $ownerId, 'organization_id' => $organizationId, 'project_id' => $projectId,
            'performance_act_id' => 51, ...$member,
        ]);
        DB::table('production_acceptance_events')->insert([
            'organization_id' => $organizationId, 'project_id' => $projectId, 'contract_id' => 21, 'performance_act_id' => 51,
            'source_line_type' => 'performance_act_line', 'source_line_id' => 91, 'work_id' => 77, 'task_id' => 31,
            'wbs_code' => '1.2', 'zone' => 'A', 'contractor_id' => 19, 'transition_version' => 1, 'event_type' => 'accepted',
            'accepted_quantity_delta' => '2.500', 'planned_quantity' => '10.000', 'reported_quantity' => '7.500',
            'unit_dimension' => 'volume', 'unit_code' => 'm3', 'conversion_version' => 'unit_4', 'approved_rate_minor' => 125045,
            'currency' => 'RUB', 'currency_source' => 'performance_act_line.unit_price@contract_performance_act.currency',
            'recognized_at' => $acceptedAt, 'actor_id' => 5, 'source_hash' => hash('sha256', 'event:'.$organizationId),
            'evidence_refs' => json_encode([['type' => 'performance_act', 'id' => 51, 'project_id' => $projectId]], JSON_THROW_ON_ERROR),
        ]);
    }

    private function assertExcel(string $bytes, string $day): void
    {
        $path = tempnam(sys_get_temp_dir(), 'accepted-subscription-');
        self::assertNotFalse($path);
        try {
            file_put_contents($path, $bytes);
            $book = IOFactory::load($path);
            $rows = $book->getActiveSheet()->toArray();
            $matches = array_values(array_filter($rows, static fn (array $row): bool => in_array('accepted', $row, true)));
            self::assertCount(1, $matches);
            self::assertSame(2.5, (float) $matches[0][0]);
            self::assertContains($day, $matches[0]);
            $book->disconnectWorksheets();
        } finally {
            unlink($path);
        }
    }
}

final class PublishedTaskFileService extends FileService
{
    public string $bytes = '';

    private array $parts = [];

    private array $metadata = [];

    private StoredFile $stored;

    public function __construct() {}

    public function startMultipart(string $organizationPath, string $mime, int $partSizeBytes, array $metadata): MultipartUpload
    {
        $this->parts = [];
        $this->metadata = $metadata;

        return new MultipartUpload($organizationPath, 'subscription-upload', $mime, $partSizeBytes, $metadata);
    }

    public function uploadPart(MultipartUpload $upload, int $partNumber, string $bytes, string $checksumSha256): MultipartPart
    {
        $this->parts[$partNumber] = $bytes;

        return new MultipartPart($upload->organizationPath, $upload->uploadId, $partNumber, 'part-'.$partNumber, strlen($bytes), $checksumSha256);
    }

    public function completeMultipart(MultipartUpload $upload, array $orderedParts, array $conditions): StoredFile
    {
        ksort($this->parts);
        $this->bytes = implode('', $this->parts);

        return $this->stored = new StoredFile($upload->organizationPath, 'subscription-artifact', strlen($this->bytes),
            hash('sha256', $this->bytes), $upload->mime);
    }

    public function describeCurrent(string $path, int $maxBytes = 64000000): array
    {
        return ['path' => $path, 'body' => '', 'size' => $this->stored->sizeBytes, 'sha256' => $this->stored->sha256,
            'etag' => $this->stored->etag, 'content_type' => $this->stored->mime, 'metadata' => $this->metadata];
    }

    public function abortMultipart(MultipartUpload $upload): void {}
}
