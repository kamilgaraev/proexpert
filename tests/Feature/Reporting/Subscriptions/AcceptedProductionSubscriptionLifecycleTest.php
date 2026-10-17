<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting\Subscriptions;

use App\BusinessModules\Core\Reporting\Application\Contracts\Access\ReportHttpAuthorizationTargetResolver;
use App\BusinessModules\Core\Reporting\Application\Contracts\CreateReportExportAction;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\CurrentReportScopeAuthorizer;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExportStore;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportRunStore;
use App\BusinessModules\Core\Reporting\Application\Exports\ReportArtifactVersionInventory;
use App\BusinessModules\Core\Reporting\Application\SavedViews\ReportSavedViewService;
use App\BusinessModules\Core\Reporting\Application\Subscriptions\ReportSubscriptionCoordinator;
use App\BusinessModules\Core\Reporting\Application\Subscriptions\ReportSubscriptionDeliveryProcessor;
use App\BusinessModules\Core\Reporting\Application\Subscriptions\ReportSubscriptionExecutionContextFactory;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSubscriptionDeliveryStore;
use App\BusinessModules\Core\Reporting\Domain\DTO\CreateReportSubscriptionData;
use App\BusinessModules\Core\Reporting\Domain\DTO\UpdateReportSubscriptionData;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportOperation;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\IdempotencyKey;
use App\BusinessModules\Core\Reporting\Infrastructure\Jobs\GenerateReportExportJob;
use App\BusinessModules\Core\Reporting\Infrastructure\Jobs\MaterializeReportRunJob;
use App\BusinessModules\Features\Notifications\Jobs\SendNotificationJob;
use App\BusinessModules\Features\Notifications\Models\Notification;
use App\Models\Project;
use App\Services\Modules\PackageCatalogService;
use App\Services\Storage\DTO\MultipartPart;
use App\Services\Storage\DTO\MultipartUpload;
use App\Services\Storage\DTO\StoredFile;
use App\Services\Storage\FileService;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

#[Group('postgres-contract')]
final class AcceptedProductionSubscriptionLifecycleTest extends TestCase
{
    protected $connectionsToTransact = [];

    public static function exportRecoveryModes(): array
    {
        return [['normal'], ['interrupted_attach'], ['expired_before_attach']];
    }

    #[DataProvider('exportRecoveryModes')]
    public function test_real_workers_create_nonempty_excel_and_deliver_one_notification_from_frozen_project(string $mode): void
    {
        Queue::fake();
        config(['cache.default' => 'array']);
        $f = Model::withoutEvents(fn () => AssistantRealAuthorizationFixture::create(array_column(app(PackageCatalogService::class)->allPackages(), 'slug')));
        $organizationId = (int) $f->organization->id;
        $project = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $organizationId]));
        $replacement = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $organizationId]));
        $projectId = (int) $project->id;
        $checkpoint = DB::table('production_acceptance_history_checkpoints')->where('organization_id', $organizationId)->sole();
        $acceptedAt = (new DateTimeImmutable((string) $checkpoint->completed_at))
            ->setTimezone(new DateTimeZone('UTC'))->modify('monday next week')->modify('+1 day')->setTime(8, 0);
        $this->seedAcceptedSource($organizationId, $projectId, $acceptedAt);
        $scheduledFor = $acceptedAt->modify('monday next week')->setTime(9, 0);
        $timezone = new DateTimeZone('Europe/Moscow');
        $authorization = app(CurrentReportScopeAuthorizer::class)->authorizeCatalog((int) $f->owner->id,
            $organizationId, $timezone, app(ReportHttpAuthorizationTargetResolver::class)->catalog());
        $view = app(ReportSavedViewService::class)->create($authorization->context, [
            'report_code' => 'accepted_production_progress', 'name' => 'Принятые работы', 'visibility' => 'private',
            'filters' => ['period_from' => $acceptedAt->format('Y-m-d'), 'period_to' => $acceptedAt->format('Y-m-d')],
            'comparison' => [], 'columns' => ['accepted_quantity', 'event_status', 'recognized_on'],
            'sort' => ['field' => 'recognized_on', 'direction' => 'desc'], 'is_default' => false,
        ]);
        $coordinator = app(ReportSubscriptionCoordinator::class);
        $subscription = $coordinator->create($authorization->context, new CreateReportSubscriptionData($view->id,
            \App\BusinessModules\Core\Reporting\Domain\Enums\ReportSubscriptionFrequency::WEEKLY, 5, null,
            '09:00', $timezone, ['mode' => 'previous_week'], 'xlsx', $projectId));
        $delivery = $coordinator->runManual($authorization->context, $subscription->id,
            new IdempotencyKey('accepted-lifecycle-'.\Illuminate\Support\Str::uuid()), $scheduledFor);
        $coordinator->update($authorization->context, $subscription->id, new UpdateReportSubscriptionData(['project_id' => (int) $replacement->id]));
        $context = app(ReportSubscriptionExecutionContextFactory::class)->forSubscription($subscription, ReportOperation::RUN, $delivery->executionInput());
        $processor = app(ReportSubscriptionDeliveryProcessor::class);
        $deliveries = app(ReportSubscriptionDeliveryStore::class);
        $processor->process($delivery->id);
        [$current] = $deliveries->lockWithSubscription($delivery->id);
        self::assertNotNull($current->runId);
        $query = app(ReportRunStore::class)->queryForRun($context, $current->runId);
        self::assertSame($organizationId, $query->filters->values['organization_id']);
        self::assertSame($projectId, $query->filters->values['project_id']);
        self::assertSame([$projectId], $query->scope->projectIds);
        self::assertArrayNotHasKey('project_id', $delivery->executionInput()->filters->values);
        $queue = new SyncQueue;
        $queue->setContainer($this->app);
        $queue->push(new MaterializeReportRunJob($current->runId));
        $run = app(ReportRunStore::class)->get($context, $current->runId);
        self::assertSame('ready', $run->status->value);
        self::assertSame(1, $run->rowCount);
        $source = app(ReportRunStore::class)->exportSource($context, $current->runId);
        $exportable = \App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantPublishedReportColumns::exportable(
            $source->query->definition->code, $source->query->definition->columns);
        self::assertCount(19, $exportable);
        self::assertSame(array_column($source->result->rowSchema, 'id'), array_column($exportable, 'id'));
        $exports = app(CreateReportExportAction::class);
        $exportAction = $this->createMock(CreateReportExportAction::class);
        $exportAction->method('handle')->willReturnCallback(function (...$arguments) use ($exports, $mode, $delivery) {
            self::assertSame(0, DB::connection()->transactionLevel());
            $export = $exports->handle(...$arguments);
            if ($mode === 'expired_before_attach') {
                DB::table('report_subscription_deliveries')->where('id', $delivery->id)
                    ->update(['execution_expires_at' => now()->subSecond()]);
            }

            return $export;
        });
        $interrupted = false;
        $deliveryStore = $this->createMock(ReportSubscriptionDeliveryStore::class);
        foreach ((new \ReflectionClass(ReportSubscriptionDeliveryStore::class))->getMethods() as $method) {
            $name = $method->getName();
            $deliveryStore->method($name)->willReturnCallback(function (...$arguments) use ($deliveries, $name, $mode, &$interrupted) {
                if ($name === 'attachExportLocked' && $mode === 'interrupted_attach' && ! $interrupted) {
                    $interrupted = true;
                    throw new \RuntimeException('interrupted_export_attachment');
                }

                return $deliveries->{$name}(...$arguments);
            });
        }
        $processor = app()->make(ReportSubscriptionDeliveryProcessor::class, ['exports' => $exportAction, 'deliveries' => $deliveryStore]);
        if ($mode === 'interrupted_attach') {
            try {
                $processor->process($delivery->id);
                self::fail('Expected interrupted attachment');
            } catch (\RuntimeException $exception) {
                self::assertSame('interrupted_export_attachment', $exception->getMessage());
            }
            [$current] = $deliveries->lockWithSubscription($delivery->id);
            self::assertSame('building_run', $current->status->value);
            self::assertNull($current->exportId);
            self::assertSame(1, DB::table('report_exports')->where('run_id', $current->runId)->count());
        }
        $processor->process($delivery->id);
        [$current] = $deliveries->lockWithSubscription($delivery->id);
        self::assertSame(1, DB::table('report_exports')->where('run_id', $current->runId)->count());
        if ($mode === 'expired_before_attach') {
            self::assertSame('expired', $current->status->value);
            self::assertNull($current->exportId);
            self::assertSame(0, Notification::query()->where('notifiable_id', $f->owner->id)->where('type', 'report_subscription_ready')->count());

            return;
        }
        self::assertNotNull($current->exportId);
        $files = new SubscriptionLifecycleFileService;
        $this->app->instance(FileService::class, $files);
        $inventory = $this->createStub(ReportArtifactVersionInventory::class);
        $inventory->method('forExport')->willReturn([]);
        $this->app->instance(ReportArtifactVersionInventory::class, $inventory);
        $queue->push(new GenerateReportExportJob($current->exportId));
        $export = app(ReportExportStore::class)->get($context, $current->exportId);
        self::assertSame('ready', $export->status->value);
        self::assertSame(1, $export->rowCount);
        self::assertSame(hash('sha256', $files->bytes), $export->checksum?->value);
        self::assertSame(strlen($files->bytes), $export->sizeBytes);
        $this->assertExcel($files->bytes, $acceptedAt->format('Y-m-d'));
        $processor->process($delivery->id);
        $processor->process($delivery->id);
        $notification = Notification::query()->where('notifiable_id', $f->owner->id)->where('type', 'report_subscription_ready')->sole();
        self::assertSame($export->id, $notification->data['export_id']);
        self::assertSame('Отчёт по подписке готов', $notification->data['title']);
        self::assertNotEmpty($notification->data['message']);
        self::assertSame('/reports/exports/'.$export->id, $notification->data['target_route']);
        self::assertSame('/reports/exports/'.$export->id, $notification->data['actions'][0]['route']);
        $queue->push(new SendNotificationJob($notification));
        $processor->process($delivery->id);
        [$current] = $deliveries->lockWithSubscription($delivery->id);
        self::assertSame('notified', $current->status->value);
        self::assertNotNull($current->notificationReceiptId);
        self::assertTrue($notification->analytics()->where('channel', 'in_app')->where('status', 'delivered')->exists());
        $processor->process($delivery->id);
        self::assertSame(1, Notification::query()->where('notifiable_id', $f->owner->id)->where('type', 'report_subscription_ready')->count());
        self::assertSame(1, DB::table('report_subscription_notification_receipts')->where('delivery_id', $delivery->id)->count());
        self::assertSame('paused', $coordinator->pause($authorization->context, $subscription->id)->status->value);
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

final class SubscriptionLifecycleFileService extends FileService
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
