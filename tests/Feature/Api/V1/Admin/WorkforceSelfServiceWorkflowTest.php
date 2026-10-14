<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Features\Notifications\Models\Notification;
use App\BusinessModules\Features\Notifications\Services\NotificationService;
use App\BusinessModules\Features\WorkforceManagement\Domain\HR\Models\EmploymentContract;
use App\BusinessModules\Features\WorkforceManagement\Domain\HR\Models\HrDocument;
use App\BusinessModules\Features\WorkforceManagement\Domain\HR\Models\WorkforceEmployee;
use App\BusinessModules\Features\WorkforceManagement\Domain\HR\Services\HrDocumentService;
use App\BusinessModules\Features\WorkforceManagement\Domain\SelfService\Models\EmployeeRequest;
use App\BusinessModules\Features\WorkforceManagement\Domain\SelfService\Services\EmployeeWorkspaceService;
use App\BusinessModules\Features\WorkforceManagement\Domain\SelfService\Services\RequestNotificationPublisher;
use App\BusinessModules\Features\WorkforceManagement\Domain\SelfService\Services\TeamQueueService;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\File;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class WorkforceSelfServiceWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $employeeUser;

    private User $manager;

    private User $hr;

    private WorkforceEmployee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->organization = Organization::factory()->verified()->create();
        $this->employeeUser = $this->member();
        $this->manager = $this->member();
        $this->hr = $this->member();
        $this->employee = $this->employee($this->employeeUser);
        $hrId = $this->hr->id;
        $this->mock(AuthorizationService::class, function ($mock) use ($hrId): void {
            $mock->shouldReceive('canCurrent')->andReturnUsing(static function (User $actor, string $permission) use ($hrId): bool {
                return $permission === 'workforce.hr.manage' ? $actor->id === $hrId : true;
            });
        });
    }

    public function test_own_overview_redacts_document_files_and_excludes_other_employees(): void
    {
        $own = $this->document($this->employee);
        $other = $this->document($this->employee($this->member()));
        $overview = app(EmployeeWorkspaceService::class)->overview((int) $this->organization->id, $this->employeeUser, '2026-10');
        self::assertSame($this->employee->id, $overview['employee']['id']);
        self::assertCount(1, $overview['documents']);
        self::assertSame($own->id, $overview['documents'][0]['id']);
        self::assertArrayNotHasKey('file_id', $overview['documents'][0]);
        self::assertArrayNotHasKey('evidence_file_id', $overview['documents'][0]);
        self::assertArrayNotHasKey('external_payroll_ref', $overview['employee']);
        try {
            app(EmployeeWorkspaceService::class)->download((int) $this->organization->id, $this->employeeUser, (int) $other->id);
            self::fail('A different employee document must be inaccessible.');
        } catch (ModelNotFoundException) {
            self::assertTrue(true);
        }
    }

    public function test_unbound_and_foreign_organization_actors_fail_closed(): void
    {
        $service = app(EmployeeWorkspaceService::class);
        foreach ([$this->member(), User::factory()->create()] as $actor) {
            try {
                $service->employee((int) $this->organization->id, $actor);
                self::fail('Employee binding and active organization membership are required.');
            } catch (AuthorizationException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_identical_retry_creates_one_request_and_changed_retry_is_rejected(): void
    {
        $service = app(EmployeeWorkspaceService::class);
        $payload = $this->payload();
        $first = $service->submit((int) $this->organization->id, $this->employeeUser, $payload);
        self::assertSame($first['id'], $service->submit((int) $this->organization->id, $this->employeeUser, $payload)['id']);
        $payload['basis'] = 'Другая заявка с тем же ключом';
        try {
            $service->submit((int) $this->organization->id, $this->employeeUser, $payload);
            self::fail('A reused key cannot accept a changed request.');
        } catch (\DomainException) {
            self::assertSame(1, EmployeeRequest::query()->count());
            self::assertSame(1, DB::table('workforce_request_notification_outbox')->count());
        }
    }

    public function test_manager_queue_and_decisions_require_explicit_active_scope(): void
    {
        $request = app(EmployeeWorkspaceService::class)->submit((int) $this->organization->id, $this->employeeUser, $this->payload());
        $queue = app(TeamQueueService::class);
        self::assertSame([], $queue->index((int) $this->organization->id, $this->manager)['data']);
        try {
            $queue->decide((int) $this->organization->id, $this->manager, $request['id'], ['action' => 'approve', 'basis' => 'Согласовано']);
            self::fail('A manager without a scope must not decide a request.');
        } catch (AuthorizationException) {
            self::assertSame('submitted', EmployeeRequest::query()->findOrFail($request['id'])->status);
        }
        $this->scope();
        self::assertCount(1, $queue->index((int) $this->organization->id, $this->manager)['data']);
        $result = $queue->decide((int) $this->organization->id, $this->manager, $request['id'], ['action' => 'approve', 'basis' => 'Согласовано']);
        self::assertSame('awaiting_hr', $result['status']);
        self::assertSame(0, DB::table('workforce_absences')->count());
    }

    public function test_hr_approval_requires_basis_and_atomically_creates_approved_absence(): void
    {
        $this->scope();
        $request = app(EmployeeWorkspaceService::class)->submit((int) $this->organization->id, $this->employeeUser, $this->payload());
        $queue = app(TeamQueueService::class);
        $queue->decide((int) $this->organization->id, $this->manager, $request['id'], ['action' => 'approve', 'basis' => 'Согласовано руководителем']);
        try {
            $queue->decide((int) $this->organization->id, $this->hr, $request['id'], ['action' => 'hr_approve', 'basis' => 'Проверено']);
            self::fail('HR final validation requires a paper basis reference.');
        } catch (ValidationException) {
            self::assertSame('awaiting_hr', EmployeeRequest::query()->findOrFail($request['id'])->status);
            self::assertSame(0, DB::table('workforce_absences')->count());
        }
        $result = $queue->decide((int) $this->organization->id, $this->hr, $request['id'],
            ['action' => 'hr_approve', 'basis' => 'Проверен бумажный приказ', 'basis_reference' => 'Приказ О-101 от 14.10.2026']);
        self::assertSame('fulfilled', $result['status']);
        $this->assertDatabaseHas('workforce_absences', ['id' => $result['result']['id'], 'employee_id' => $this->employee->id,
            'organization_id' => $this->organization->id, 'status' => 'approved', 'basis_reference' => 'Приказ О-101 от 14.10.2026']);
        $this->assertDatabaseHas('workforce_request_notification_outbox', ['request_id' => $request['id'], 'revision' => 2, 'user_id' => $this->hr->id]);
    }

    public function test_recovery_sends_each_event_once_with_its_original_status(): void
    {
        $this->failInitialNotificationDelivery();
        $request = app(EmployeeWorkspaceService::class)->submit((int) $this->organization->id, $this->employeeUser, $this->payload());
        EmployeeRequest::query()->findOrFail($request['id'])->update(['status' => 'fulfilled', 'revision' => 3]);
        $event = DB::table('workforce_request_notification_outbox')->first();
        self::assertNull($event->dispatched_at);
        self::assertNull($event->discarded_at);
        $this->mock(NotificationService::class, function ($mock) use ($event): void {
            $mock->shouldReceive('sendWithId')->once()->withArgs(static function ($id, $user, $type, $data) use ($event): bool {
                return $id === $event->id && $type === 'workforce.request.changed'
                    && $data['context']['status'] === 'submitted' && $data['context']['revision'] === 1;
            })->andReturn(new Notification);
        });
        $publisher = app(RequestNotificationPublisher::class);
        $publisher->recover();
        $publisher->recover();
        self::assertNotNull(DB::table('workforce_request_notification_outbox')->where('id', $event->id)->value('dispatched_at'));
    }

    public function test_revoked_membership_discards_event_without_delivery_or_blocking_recovery(): void
    {
        $this->failInitialNotificationDelivery();
        app(EmployeeWorkspaceService::class)->submit((int) $this->organization->id, $this->employeeUser, $this->payload());
        $this->organization->users()->updateExistingPivot($this->employeeUser->id, ['is_active' => false]);
        $this->mock(NotificationService::class, fn ($mock) => $mock->shouldNotReceive('sendWithId'));
        app(RequestNotificationPublisher::class)->recover();
        $event = DB::table('workforce_request_notification_outbox')->first();
        self::assertNull($event->dispatched_at);
        self::assertNotNull($event->discarded_at);
    }

    public function test_pagination_prioritizes_old_pending_requests_and_counts_the_full_scoped_queue(): void
    {
        $this->scope();
        $old = app(EmployeeWorkspaceService::class)->submit((int) $this->organization->id, $this->employeeUser, $this->payload());
        $closed = [];
        for ($index = 0; $index < 210; $index++) {
            $closed[] = ['organization_id' => $this->organization->id, 'employee_id' => $this->employee->id,
                'requested_by' => $this->employeeUser->id, 'idempotency_key' => (string) Str::uuid(), 'kind' => 'absence',
                'date_from' => '2026-10-15', 'date_to' => '2026-10-16', 'basis' => 'Архивная заявка',
                'requested_data' => json_encode(['absence_type_code' => 'vacation']), 'status' => 'rejected',
                'created_at' => now(), 'updated_at' => now()];
        }
        DB::table('workforce_employee_requests')->insert($closed);
        $other = $this->employee($this->member());
        EmployeeRequest::create($this->payload() + ['organization_id' => $this->organization->id, 'employee_id' => $other->id,
            'requested_by' => $other->user_id]);
        $service = app(TeamQueueService::class);
        $queue = $service->index((int) $this->organization->id, $this->manager, ['per_page' => 100]);
        self::assertSame($old['id'], $queue['data'][0]['id']);
        self::assertSame(211, $queue['meta']['total']);
        self::assertSame(3, $queue['meta']['last_page']);
        self::assertCount(100, $queue['data']);
        self::assertSame(1, $queue['summary']['pending_count']);
        $pending = $service->index((int) $this->organization->id, $this->manager, ['status' => 'pending']);
        self::assertSame(1, $pending['meta']['total']);
        self::assertSame($old['id'], $pending['data'][0]['id']);
        $archive = $service->index((int) $this->organization->id, $this->manager, ['status' => 'rejected', 'page' => 3, 'per_page' => 100]);
        self::assertCount(10, $archive['data']);
        self::assertSame(1, $archive['summary']['pending_count']);
        $own = app(EmployeeWorkspaceService::class)->requests((int) $this->organization->id, $this->employeeUser, ['page' => 3, 'per_page' => 100]);
        self::assertSame(211, $own['meta']['total']);
        self::assertCount(11, $own['data']);
        self::assertSame(1, $own['meta']['pending_count']);
        self::assertNotContains($other->id, array_column($own['data'], 'employee_id'));
        $this->expectException(ValidationException::class);
        $service->index((int) $this->organization->id, $this->manager, ['per_page' => 101]);
    }

    public function test_hr_basis_uses_document_id_not_file_id_and_rejects_another_employee_document(): void
    {
        $this->scope();
        $own = $this->document($this->employee);
        if ((int) $own->id === (int) $own->file_id) {
            // Deliberately separate the two sequences so a File ID cannot accidentally pass as a document ID.
            File::query()->findOrFail($own->file_id)->replicate()->save();
            $own = $this->document($this->employee);
        }
        self::assertNotSame((int) $own->id, (int) $own->file_id);
        $foreign = $this->document($this->employee($this->member()));
        $request = app(EmployeeWorkspaceService::class)->submit((int) $this->organization->id, $this->employeeUser, $this->payload());
        $queue = app(TeamQueueService::class);
        $queue->decide((int) $this->organization->id, $this->manager, $request['id'], ['action' => 'approve', 'basis' => 'Согласовано']);
        try {
            $queue->decide((int) $this->organization->id, $this->hr, $request['id'], ['action' => 'hr_approve',
                'basis' => 'Проверено', 'basis_reference' => 'Приказ О-102', 'basis_document_id' => $foreign->id]);
            self::fail('A basis document must belong to the request employee.');
        } catch (\DomainException) {
            self::assertSame('awaiting_hr', EmployeeRequest::query()->findOrFail($request['id'])->status);
            self::assertSame(0, DB::table('workforce_absences')->count());
        }
        $result = $queue->decide((int) $this->organization->id, $this->hr, $request['id'], ['action' => 'hr_approve',
            'basis' => 'Проверено', 'basis_reference' => 'Приказ О-102', 'basis_document_id' => $own->id]);
        self::assertSame('fulfilled', $result['status']);
        $this->assertDatabaseHas('workforce_absences', ['id' => $result['result']['id'], 'basis_document_id' => $own->id]);
    }

    public function test_own_working_timezones_are_effective_date_scoped_and_never_expose_other_employee_terms(): void
    {
        $base = ['organization_id' => $this->organization->id, 'employee_id' => $this->employee->id,
            'contract_date' => '2026-05-01', 'start_date' => '2026-05-01', 'status' => 'active',
            'relation_type' => 'employment', 'employment_mode' => 'main', 'job_share' => 1,
            'weekly_norm_hours' => 40, 'calendar_profile' => 'ru-five-day'];
        $own = EmploymentContract::create($base + ['contract_number' => 'SELF-TZ-1', 'timezone' => 'Europe/Moscow']);
        $future = EmploymentContract::create(array_replace($base, ['start_date' => '2026-12-01',
            'contract_number' => 'SELF-TZ-FUTURE', 'timezone' => 'Asia/Yekaterinburg', 'employment_mode' => 'internal_secondary']));
        $other = $this->employee($this->member());
        EmploymentContract::create(array_replace($base, ['employee_id' => $other->id, 'contract_number' => 'OTHER-TZ', 'timezone' => 'Asia/Vladivostok']));
        $service = app(EmployeeWorkspaceService::class);
        $overview = $service->overview((int) $this->organization->id, $this->employeeUser, '2026-10', '2026-10-15');
        self::assertSame('Europe/Moscow', $overview['employee']['working_timezone']);
        self::assertSame('2026-10-15', $overview['employee']['working_timezone_date']);
        self::assertSame($own->id, $overview['employee']['working_contract_id']);
        self::assertCount(1, $overview['employee']['working_timezones']);
        self::assertArrayNotHasKey('weekly_norm_hours', $overview['employee']['working_timezones'][0]);
        $conflict = $service->overview((int) $this->organization->id, $this->employeeUser, '2026-12', '2026-12-01');
        self::assertNull($conflict['employee']['working_timezone']);
        self::assertNull($conflict['employee']['working_contract_id']);
        self::assertSame([$own->id, $future->id], array_column($conflict['employee']['working_timezones'], 'employment_contract_id'));
    }

    private function failInitialNotificationDelivery(): void
    {
        // Laravel's test transaction manager executes afterCommit at the wrapping test transaction.
        // A failed first attempt leaves a real pending outbox record for recovery assertions.
        $this->mock(NotificationService::class, fn ($mock) => $mock->shouldReceive('sendWithId')->once()
            ->andThrow(new \RuntimeException('temporary_delivery_failure')));
    }

    private function member(): User
    {
        $user = User::factory()->create();
        $this->organization->users()->attach($user->id, ['is_active' => true, 'is_owner' => false]);

        return $user;
    }

    private function employee(User $user): WorkforceEmployee
    {
        return WorkforceEmployee::create(['organization_id' => $this->organization->id, 'user_id' => $user->id,
            'personnel_number' => 'SELF-'.$user->id, 'last_name' => 'Иванов', 'first_name' => 'Иван',
            'employment_status' => 'active', 'hire_date' => '2026-05-01']);
    }

    private function payload(): array
    {
        return ['idempotency_key' => (string) Str::uuid(), 'kind' => 'absence', 'date_from' => '2026-10-15',
            'date_to' => '2026-10-16', 'basis' => 'Прошу предоставить отпуск', 'requested_data' => ['absence_type_code' => 'vacation']];
    }

    private function scope(): void
    {
        app(TeamQueueService::class)->storeScope((int) $this->organization->id, $this->hr, [
            'employee_id' => $this->employee->id, 'manager_user_id' => $this->manager->id, 'valid_from' => '2026-05-01']);
    }

    private function document(WorkforceEmployee $employee): HrDocument
    {
        $file = File::create(['organization_id' => $this->organization->id, 'user_id' => $this->hr->id,
            'fileable_type' => WorkforceEmployee::class, 'fileable_id' => $employee->id, 'name' => 'contract.pdf',
            'original_name' => 'contract.pdf', 'path' => 'org-'.$this->organization->id.'/workforce/hr/'.$employee->id.'/'.Str::uuid(),
            'mime_type' => 'application/pdf', 'size' => 100, 'disk' => 's3', 'type' => 'document', 'category' => 'workforce_hr',
            'additional_info' => ['visibility' => 'private', 'hr_employee_id' => $employee->id]]);

        return app(HrDocumentService::class)->register((int) $this->organization->id, (int) $employee->id,
            ['document_type' => 'contract', 'title' => 'Трудовой договор', 'file_id' => $file->id,
                'document_date' => '2026-05-01', 'retention_class' => 'unclassified_hold'], $this->hr);
    }
}
