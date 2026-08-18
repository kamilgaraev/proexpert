<?php

declare(strict_types=1);

namespace Tests\Unit\MachineryOperations;

use App\BusinessModules\Features\MachineryOperations\Models\AssetRequest;
use App\BusinessModules\Features\MachineryOperations\Models\MachineryAssignment;
use App\BusinessModules\Features\MachineryOperations\Services\SiteRequestAssetProjectionService;
use App\BusinessModules\Features\SiteRequests\Models\SiteRequest;
use DomainException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\TestCase as LaravelTestCase;

final class SiteRequestAssetProjectionServiceTest extends LaravelTestCase
{
    private Capsule $database;

    private SiteRequestAssetProjectionService $service;

    public function createApplication()
    {
        $app = require dirname(__DIR__, 3).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->database = new Capsule;
        $this->database->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->database->setAsGlobal();
        $this->database->bootEloquent();
        $this->createSchema();
        $this->service = new SiteRequestAssetProjectionService;
    }

    public function test_equipment_request_is_projected_once_with_meaningful_fields_and_no_implicit_profile_flags(): void
    {
        $siteRequest = $this->siteRequest([
            'title' => 'Вывоз грунта',
            'description' => 'Погрузка и вывоз со второй очереди',
            'priority' => 'medium',
            'equipment_start_at' => '2026-08-18 09:00:00',
            'equipment_end_at' => '2026-08-18 18:00:00',
            'equipment_specs' => 'Самосвал от 20 тонн',
        ]);

        $first = $this->service->project($siteRequest, 7);
        $second = $this->service->project($siteRequest->fresh(), 7);

        self::assertSame($first->id, $second->id);
        self::assertSame(1, AssetRequest::query()->count());
        self::assertSame($siteRequest->id, $first->site_request_id);
        self::assertSame('site_request', $first->origin_type);
        self::assertSame('pending', $first->status);
        self::assertSame('normal', $first->priority);
        self::assertSame("Вывоз грунта\nПогрузка и вывоз со второй очереди", $first->purpose);
        self::assertSame('Самосвал от 20 тонн', $first->requirements);
        self::assertSame([], $first->required_profile);
        self::assertSame('2026-08-18 09:00:00', $first->planned_start_at?->format('Y-m-d H:i:s'));
        self::assertSame('2026-08-18 18:00:00', $first->planned_end_at?->format('Y-m-d H:i:s'));
    }

    public function test_retry_updates_the_projection_without_reopening_a_closed_request(): void
    {
        $siteRequest = $this->siteRequest();
        $assetRequest = $this->service->project($siteRequest, 7);
        $assetRequest->update(['status' => 'assigned']);
        $siteRequest->update([
            'title' => 'Кран для монтажа',
            'equipment_specs' => 'Вылет стрелы 30 м',
            'priority' => 'urgent',
        ]);

        $updated = $this->service->project($siteRequest->fresh(), 7);

        self::assertSame($assetRequest->id, $updated->id);
        self::assertSame('assigned', $updated->status);
        self::assertSame('urgent', $updated->priority);
        self::assertSame('Кран для монтажа', $updated->purpose);
        self::assertSame('Вылет стрелы 30 м', $updated->requirements);
    }

    public function test_projection_rejects_a_project_outside_the_site_request_organization(): void
    {
        $this->database->table('projects')->where('id', 100)->update(['organization_id' => 20]);
        $siteRequest = $this->siteRequest();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Выбранный проект недоступен текущей организации.');

        try {
            $this->service->project($siteRequest, 7);
        } finally {
            self::assertSame(0, AssetRequest::query()->count());
        }
    }

    public function test_projection_accepts_a_project_shared_with_an_active_participant_organization(): void
    {
        $this->database->table('projects')->where('id', 100)->update(['organization_id' => 20]);
        $this->database->table('project_organization')->insert([
            'project_id' => 100,
            'organization_id' => 10,
            'is_active' => true,
        ]);

        $assetRequest = $this->service->project($this->siteRequest(), 7);

        self::assertSame(10, $assetRequest->organization_id);
        self::assertSame(100, $assetRequest->project_id);
        self::assertSame(1, AssetRequest::query()->count());
    }

    public function test_terminal_site_request_closes_projected_request_and_active_assignment(): void
    {
        $siteRequest = $this->siteRequest();
        $assetRequest = $this->service->project($siteRequest, 7);
        $assignment = $this->assignment($assetRequest);
        $siteRequest->update(['status' => 'cancelled']);

        $this->service->synchronizeFromSiteRequest($siteRequest->fresh(), 7);

        self::assertSame('cancelled', $assetRequest->fresh()->status);
        self::assertSame('cancelled', $assignment->fresh()->status);
        self::assertNotNull($assignment->fresh()->actual_end_at);
    }

    public function test_assignment_and_return_keep_the_linked_site_request_lifecycle_aligned(): void
    {
        $siteRequest = $this->siteRequest(['status' => 'approved']);
        $assetRequest = $this->service->project($siteRequest, 7);
        $assignment = $this->assignment($assetRequest);

        $this->service->markSiteRequestInProgress($assetRequest, 8);
        self::assertSame('in_progress', $siteRequest->fresh()->status->value);

        $assignment->update(['status' => 'completed', 'actual_end_at' => '2026-08-18 18:00:00']);
        $this->service->completeFromAssignment($assignment->fresh(), 8);

        self::assertSame('completed', $assetRequest->fresh()->status);
        self::assertSame('completed', $siteRequest->fresh()->status->value);
        self::assertSame(2, $this->database->table('site_request_history')->count());
    }

    /** @param array<string, mixed> $overrides */
    private function siteRequest(array $overrides = []): SiteRequest
    {
        return SiteRequest::query()->create([
            'organization_id' => 10,
            'project_id' => 100,
            'user_id' => 7,
            'status' => 'draft',
            'priority' => 'medium',
            'request_type' => 'equipment_request',
            'title' => 'Экскаватор',
            'description' => null,
            'rental_start_date' => '2026-08-18',
            'rental_end_date' => null,
            'equipment_start_at' => null,
            'equipment_end_at' => null,
            'equipment_specs' => null,
            ...$overrides,
        ]);
    }

    private function assignment(AssetRequest $request): MachineryAssignment
    {
        return MachineryAssignment::query()->create([
            'organization_id' => 10,
            'asset_request_id' => $request->id,
            'asset_id' => 501,
            'organization_asset_id' => 601,
            'project_id' => 100,
            'status' => 'active',
            'planned_start_at' => '2026-08-18 09:00:00',
            'planned_end_at' => '2026-08-18 18:00:00',
        ]);
    }

    private function createSchema(): void
    {
        $schema = $this->database->schema();
        $schema->create('organizations', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->softDeletes();
        });
        $this->database->table('organizations')->insert([
            ['id' => 10, 'name' => 'Участник'],
            ['id' => 20, 'name' => 'Владелец'],
        ]);
        $schema->create('projects', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->string('name');
            $table->softDeletes();
        });
        $this->database->table('projects')->insert(['id' => 100, 'organization_id' => 10, 'name' => 'Проект']);
        $schema->create('project_organization', function (Blueprint $table): void {
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('organization_id');
            $table->boolean('is_active')->default(true);
        });

        $schema->create('site_requests', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('user_id');
            $table->string('status');
            $table->string('priority');
            $table->string('request_type');
            $table->string('title');
            $table->text('description')->nullable();
            $table->date('rental_start_date')->nullable();
            $table->date('rental_end_date')->nullable();
            $table->dateTime('equipment_start_at')->nullable();
            $table->dateTime('equipment_end_at')->nullable();
            $table->text('equipment_specs')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        $schema->create('asset_requests', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('site_request_id')->nullable()->unique();
            $table->unsignedBigInteger('requested_by_user_id');
            $table->string('origin_type')->default('manual');
            $table->string('status');
            $table->string('priority');
            $table->dateTime('planned_start_at');
            $table->dateTime('planned_end_at')->nullable();
            $table->json('required_profile')->nullable();
            $table->text('requirements')->nullable();
            $table->text('purpose');
            $table->timestamps();
            $table->softDeletes();
        });
        $schema->create('asset_request_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('asset_request_id');
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('event_type');
            $table->json('payload')->nullable();
            $table->dateTime('occurred_at');
        });
        $schema->create('machinery_assignments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('asset_request_id')->nullable();
            $table->unsignedBigInteger('asset_id');
            $table->unsignedBigInteger('organization_asset_id')->nullable();
            $table->unsignedBigInteger('project_id');
            $table->string('status');
            $table->dateTime('planned_start_at');
            $table->dateTime('planned_end_at')->nullable();
            $table->dateTime('actual_end_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        $schema->create('site_request_history', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_request_id');
            $table->unsignedBigInteger('user_id');
            $table->string('action');
            $table->json('old_value')->nullable();
            $table->json('new_value')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }
}
