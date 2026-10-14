<?php

declare(strict_types=1);

namespace Tests\Unit\WorkforceManagement\HR;

use App\BusinessModules\Features\WorkforceManagement\Domain\HR\Models\WorkforceEmployee;
use App\BusinessModules\Features\WorkforceManagement\Domain\HR\Services\EmploymentTerms;
use App\BusinessModules\Features\WorkforceManagement\Domain\HR\Services\HrAccess;
use App\BusinessModules\Features\WorkforceManagement\Domain\HR\Services\HrDocumentRetentionPolicy;
use App\BusinessModules\Features\WorkforceManagement\Http\Resources\WorkforceEmployeeResource;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Infrastructure\DatabaseWorkforceReportAdapter;
use App\BusinessModules\Features\WorkforceManagement\Services\WorkforceEmployeeService;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use PHPUnit\Framework\TestCase;

final class HrCoreContractTest extends TestCase
{
    private Container $container;

    protected function setUp(): void
    {
        parent::setUp();
        $this->container = new Container;
        Container::setInstance($this->container);
        $this->container->instance('app', new class
        {
            public function getLocale(): string
            {
                return 'ru';
            }
        });
        $this->container->instance('config', new Repository(['app' => ['fallback_locale' => 'ru']]));
        $this->container->instance('translator', new Translator(new ArrayLoader, 'ru'));
        $this->container->instance('log', new class
        {
            public function warning(string $message): void {}
        });
        Facade::setFacadeApplication($this->container);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);
        parent::tearDown();
    }

    public function test_civil_engagement_cannot_acquire_employee_norms(): void
    {
        $this->expectException(\DomainException::class);
        EmploymentTerms::assert($this->civil() + ['weekly_norm_hours' => 40]);
    }

    public function test_civil_engagement_is_valid_without_invented_employee_terms(): void
    {
        EmploymentTerms::assert($this->civil());
        $this->addToAssertionCount(1);
    }

    public function test_employee_relation_rejects_invalid_timezone_and_overfull_share(): void
    {
        foreach ([['timezone' => 'Unknown/Zone'], ['job_share' => 1.25], ['weekly_norm_hours' => 41], ['end_date' => '2026-09-01']] as $invalid) {
            try {
                EmploymentTerms::assert(array_replace($this->employment(), $invalid));
                $this->fail('Invalid employment terms were accepted');
            } catch (\DomainException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_access_denies_missing_actor_before_any_database_operation(): void
    {
        $authorization = $this->createMock(AuthorizationService::class);
        $authorization->expects($this->never())->method('canCurrent');
        $this->expectException(AuthorizationException::class);
        (new HrAccess($authorization))->assert(1, null);
    }

    public function test_cross_organization_actor_is_denied_even_if_permission_would_allow(): void
    {
        $authorization = $this->createMock(AuthorizationService::class);
        $authorization->expects($this->never())->method('canCurrent');
        $actor = $this->createMock(User::class);
        $actor->method('belongsToOrganization')->with(2)->willReturn(false);
        $this->assertFalse((new HrAccess($authorization))->allows(2, $actor, 'workforce.hr.manage'));
    }

    public function test_employee_resource_has_separate_payroll_and_contact_scopes(): void
    {
        $actor = $this->createMock(User::class);
        $actor->method('belongsToOrganization')->willReturn(true);
        $authorization = $this->createMock(AuthorizationService::class);
        $authorization->method('canCurrent')->willReturnCallback(static fn (User $user, string $permission): bool => $permission === 'workforce.hr.private.read');
        $this->container->instance(HrAccess::class, new HrAccess($authorization));
        $employee = new WorkforceEmployee;
        $employee->setRawAttributes(['id' => 5, 'organization_id' => 1, 'last_name' => 'Test', 'first_name' => 'User', 'employment_status' => 'active',
            'email' => 'private@example.test', 'phone' => '123', 'metadata' => '{"hour_rate":1000}', 'external_payroll_ref' => 'PAYROLL-SECRET']);
        $request = Request::create('/');
        $request->setUserResolver(fn () => $actor);
        $data = (new WorkforceEmployeeResource($employee))->resolve($request);
        $this->assertSame('private@example.test', $data['email']);
        $this->assertArrayNotHasKey('external_payroll_ref', $data);
        $this->assertArrayNotHasKey('metadata', $data);
        $this->assertSame('needs_reconciliation', $data['hr_reconciliation_status']);
    }

    public function test_basic_employee_resource_does_not_disclose_contacts_or_arbitrary_metadata(): void
    {
        $actor = $this->createMock(User::class);
        $actor->method('belongsToOrganization')->willReturn(true);
        $authorization = $this->createMock(AuthorizationService::class);
        $authorization->method('canCurrent')->willReturn(false);
        $this->container->instance(HrAccess::class, new HrAccess($authorization));
        $employee = new WorkforceEmployee;
        $employee->setRawAttributes(['id' => 5, 'organization_id' => 1, 'last_name' => 'Test', 'first_name' => 'User', 'employment_status' => 'active',
            'email' => 'private@example.test', 'phone' => '123', 'metadata' => '{"diagnosis":"private"}', 'external_payroll_ref' => 'secret']);
        $request = Request::create('/');
        $request->setUserResolver(fn () => $actor);
        $data = (new WorkforceEmployeeResource($employee))->resolve($request);
        foreach (['phone', 'email', 'metadata', 'external_payroll_ref'] as $field) {
            $this->assertArrayNotHasKey($field, $data);
        }
    }

    public function test_concurrent_main_secondary_and_civil_relations_have_distinct_slots(): void
    {
        $main = $this->employment();
        $secondary = array_replace($main, ['employment_mode' => 'internal_secondary', 'job_share' => 0.5, 'weekly_norm_hours' => 20]);
        EmploymentTerms::assert($secondary);
        $this->assertFalse(EmploymentTerms::sameSlot($main, $secondary));
        $this->assertFalse(EmploymentTerms::sameSlot($main, $this->civil()));
        $this->assertTrue(EmploymentTerms::sameSlot($main, $main));
        $this->assertTrue(EmploymentTerms::sameSlot($secondary, $secondary));
        $this->assertTrue(EmploymentTerms::sameSlot($this->civil(), $this->civil()));
    }

    public function test_unknown_document_classification_cannot_set_disposal_deadline(): void
    {
        $policy = new HrDocumentRetentionPolicy;
        $document = ['document_type' => 'unknown', 'retention_class' => 'unclassified_hold', 'document_date' => '2026-10-01'];
        $this->assertFalse($policy->classify($document, null, [])['disposal_allowed']);
        $this->expectException(\DomainException::class);
        $policy->classify($document + ['retain_until' => '2027-01-01'], null, []);
    }

    public function test_active_personnel_case_cannot_receive_a_short_retention_deadline(): void
    {
        $policy = new HrDocumentRetentionPolicy;
        $rules = ['employment_contract' => ['class' => 'personnel_long_term', 'minimum_years' => 50, 'before_2003_years' => 75,
            'basis' => 'case_closed', 'legal_reference' => 'verified-policy-reference']];
        $document = ['document_type' => 'employment_contract', 'retention_class' => 'personnel_long_term', 'document_date' => '2026-10-01'];
        $this->assertNull($policy->classify($document, null, $rules)['earliest_review_date']);
        $this->expectException(\DomainException::class);
        $policy->classify($document + ['retain_until' => '2077-01-01'], null, $rules);
    }

    public function test_retention_policy_keeps_the_verified_floor_and_rejects_short_dates(): void
    {
        $policy = new HrDocumentRetentionPolicy;
        $rules = ['employment_contract' => ['class' => 'personnel_long_term', 'minimum_years' => 50, 'before_2003_years' => 75,
            'basis' => 'case_closed', 'legal_reference' => 'verified-policy-reference']];
        $document = ['document_type' => 'employment_contract', 'retention_class' => 'personnel_long_term', 'document_date' => '2026-10-01'];
        $classification = $policy->classify($document, '2026-10-14', $rules);
        $this->assertSame(50, $classification['minimum_years']);
        $this->assertSame('2077-01-01', $classification['earliest_review_date']);
        $this->assertFalse($classification['disposal_allowed']);
        $this->expectException(\DomainException::class);
        $policy->classify($document + ['retain_until' => '2027-01-01'], '2026-10-14', $rules);
    }

    public function test_long_retention_cutoff_uses_recordkeeping_completion_not_signing_year(): void
    {
        $rules = ['employment_contract' => ['class' => 'personnel_long_term', 'minimum_years' => 50, 'before_2003_years' => 75,
            'basis' => 'case_closed', 'legal_reference' => 'verified-policy-reference']];
        $document = ['document_type' => 'employment_contract', 'retention_class' => 'personnel_long_term', 'document_date' => '2000-10-01'];
        $policy = new HrDocumentRetentionPolicy;
        $this->assertSame(50, $policy->classify($document, '2026-10-14', $rules)['minimum_years']);
        $this->assertSame(75, $policy->classify($document, '2002-10-14', $rules)['minimum_years']);
    }

    public function test_configuration_cannot_reduce_a_verified_personnel_retention_floor(): void
    {
        $this->expectException(\DomainException::class);
        (new HrDocumentRetentionPolicy)->classify(['document_type' => 'employment_contract', 'retention_class' => 'personnel_long_term', 'document_date' => '2026-10-01'],
            '2026-10-14', ['employment_contract' => ['class' => 'personnel_long_term', 'minimum_years' => 1, 'before_2003_years' => 2,
                'basis' => 'case_closed', 'legal_reference' => 'misconfigured-policy-reference']]);
    }

    public function test_employee_card_schedule_and_qr_presence_never_create_worked_hours(): void
    {
        $service = (new \ReflectionClass(WorkforceEmployeeService::class))->newInstanceWithoutConstructor();
        $presence = new \ReflectionMethod($service, 'presence');
        $assignment = (object) ['work_schedule_id' => 1, 'schedule_hours_per_day' => 8, 'schedule_type' => 'weekly'];
        $schedule = (object) ['day_type' => 'work', 'planned_hours' => 8];
        $planned = $presence->invoke($service, 'active', '2026-10-14', $assignment, $schedule, null, null);
        $this->assertSame('scheduled_work', $planned['status']);
        $this->assertNull($planned['hours']);
        $this->assertSame(8.0, $planned['planned_hours']);
        $scan = $presence->invoke($service, 'active', '2026-10-14', $assignment, $schedule, null, null, null, true);
        $this->assertSame('at_work', $scan['status']);
        $this->assertNull($scan['hours']);
        $this->assertSame(8.0, $scan['planned_hours']);
        $correction = (object) ['status' => 'at_work', 'hours' => 6.5];
        $corrected = $presence->invoke($service, 'active', '2026-10-14', $assignment, $schedule, null, null, $correction);
        $this->assertSame(6.5, $corrected['hours']);
        $this->assertSame(8.0, $corrected['planned_hours']);
        $correction->hours = null;
        $this->assertNull($presence->invoke($service, 'active', '2026-10-14', $assignment, $schedule, null, null, $correction)['hours']);
    }

    public function test_card_weekly_day_off_remains_a_plan_with_unknown_worked_hours(): void
    {
        $service = (new \ReflectionClass(WorkforceEmployeeService::class))->newInstanceWithoutConstructor();
        $presence = new \ReflectionMethod($service, 'presence');
        $assignment = (object) ['work_schedule_id' => 1, 'schedule_hours_per_day' => 8, 'schedule_type' => 'weekly'];
        $day = $presence->invoke($service, 'active', '2026-10-18', $assignment, null, null, null);
        $this->assertSame('scheduled_day_off', $day['status']);
        $this->assertSame(0.0, $day['planned_hours']);
        $this->assertNull($day['hours']);
    }

    public function test_attendance_export_schema_resolves_human_labels_for_new_fields(): void
    {
        $loader = new ArrayLoader;
        $loader->addMessages('ru', 'reports', ['columns' => ['confirmed_hours' => 'Подтверждённые часы',
            'actual_duration_known' => 'Часы подтверждены', 'status_label' => 'Состояние']]);
        $this->container->instance('translator', new Translator($loader, 'ru'));
        Facade::clearResolvedInstances();
        $adapter = (new \ReflectionClass(DatabaseWorkforceReportAdapter::class))->newInstanceWithoutConstructor();
        $schema = (new \ReflectionMethod($adapter, 'attendanceSchema'))->invoke($adapter);
        $columns = array_column($schema, null, 'id');
        $this->assertSame('Подтверждённые часы', $columns['confirmed_hours']['labels']['ru']);
        $this->assertSame('Часы подтверждены', $columns['actual_duration_known']['label']);
        $this->assertSame('Состояние', $columns['status_label']['label']);
        $this->assertArrayNotHasKey('status', $columns);
        $this->assertArrayNotHasKey('present_hours', $columns);
    }

    private function civil(): array
    {
        return ['relation_type' => 'civil', 'contract_number' => 'CIVIL-1', 'contract_date' => '2026-10-01', 'start_date' => '2026-10-01'];
    }

    private function employment(): array
    {
        return array_replace($this->civil(), ['relation_type' => 'employment', 'employment_mode' => 'main', 'job_share' => 1,
            'weekly_norm_hours' => 40, 'timezone' => 'Europe/Moscow', 'calendar_profile' => 'ru-commercial']);
    }
}
