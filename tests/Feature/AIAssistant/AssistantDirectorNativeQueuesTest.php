<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantProjectActReader;
use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantProjectSiteRequestReader;
use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantProjectStaffReader;
use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantTenderReader;
use App\BusinessModules\Features\SiteRequests\Models\SiteRequest;
use App\BusinessModules\Features\Tenders\Models\Tender;
use App\BusinessModules\Features\WorkforceManagement\Domain\HR\Models\WorkforceEmployee;
use App\Models\Contract;
use App\Models\ContractPerformanceAct;
use App\Models\Project;
use App\Services\Modules\PackageCatalogService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

final class AssistantDirectorNativeQueuesTest extends TestCase
{
    public function test_material_request_queue_excludes_other_types_and_rechecks_type_changes(): void
    {
        Queue::fake(); config(['cache.default' => 'array']);
        $f = Model::withoutEvents(fn () => AssistantRealAuthorizationFixture::create(array_column(app(PackageCatalogService::class)->allPackages(), 'slug')));
        $org = (int) $f->organization->id; $actor = $f->owner;
        $project = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $org]));
        $other = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $org]));
        Model::withoutEvents(function () use ($org, $actor, $project, $other): void {
            foreach (range(1, 21) as $i) {
                SiteRequest::query()->create(['organization_id' => $org, 'project_id' => $project->id, 'user_id' => $actor->id,
                    'title' => 'MATERIAL-'.$i, 'request_type' => 'material_request', 'status' => 'pending', 'required_date' => today()->subDay()]);
            }
            foreach ([[$project, 'equipment_request', 'pending'], [$other, 'material_request', 'pending'], [$project, 'material_request', 'cancelled']] as [$scope, $type, $status]) {
                SiteRequest::query()->create(['organization_id' => $org, 'project_id' => $scope->id, 'user_id' => $actor->id,
                    'title' => 'EXCLUDED-'.$type.'-'.$status, 'request_type' => $type, 'status' => $status, 'required_date' => today()->subDay()]);
            }
        });
        DB::table('site_requests')->where('title', 'MATERIAL-21')->where('project_id', $project->id)->update(['request_type' => 'material']);
        $reader = app(\App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantProjectMaterialRequestReader::class);
        $args = ['project_id' => (int) $project->id];
        $read = $reader->read($actor, $org, $args);
        self::assertSame(21, $read['data']['active']);
        self::assertSame(21, $read['data']['overdue']);
        self::assertSame(21, $read['data']['unassigned']);
        self::assertCount(20, $read['data']['examples']);
        self::assertStringNotContainsString('EXCLUDED', json_encode($read));
        self::assertStringContainsString('только заявки на материалы', $read['server_formatted_answer']);
        self::assertTrue($reader->matchesReference($actor, $org, $read['source_refs'][0]));
        SiteRequest::query()->where('title', 'MATERIAL-1')->where('project_id', $project->id)->firstOrFail()->updateQuietly(['request_type' => 'equipment_request']);
        self::assertFalse($reader->matchesReference($actor, $org, $read['source_refs'][0]));
        self::assertSame(20, $reader->read($actor, $org, $args)['data']['active']);
    }

    public function test_act_amounts_use_full_status_groups_and_keep_currencies_separate(): void
    {
        Queue::fake(); config(['cache.default' => 'array']);
        $f = Model::withoutEvents(fn () => AssistantRealAuthorizationFixture::create(array_column(app(PackageCatalogService::class)->allPackages(), 'slug')));
        $org = (int) $f->organization->id; $actor = $f->owner;
        $project = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $org]));
        $contract = Contract::withoutEvents(fn () => Contract::query()->create(['organization_id' => $org, 'project_id' => $project->id,
            'number' => 'CURRENCIES', 'date' => today(), 'subject' => 'Работы', 'status' => 'active', 'currency' => 'RUB', 'total_amount' => 10000, 'is_self_execution' => true]));
        Model::withoutEvents(function () use ($contract, $project): void {
            foreach ([['pending_approval', 'RUB', '100.10'], ['pending_approval', 'USD', '9.25'],
                ['approved', 'RUB', '200.20'], ['signed', 'RUB', '20.03'], ['approved', 'USD', '3.00'],
                ['draft', 'RUB', '9999.00'], ['annulled', 'RUB', '9999.00']] as $i => [$status, $currency, $amount]) {
                ContractPerformanceAct::query()->create(['contract_id' => $contract->id, 'project_id' => $project->id,
                    'act_document_number' => 'CURRENCY-'.$i, 'act_date' => today(), 'amount' => $amount, 'currency' => $currency, 'status' => $status]);
            }
        });
        $otherCurrencyContract = Contract::withoutEvents(fn () => Contract::query()->create(['organization_id' => $org, 'project_id' => $project->id,
            'number' => 'LEGACY-USD', 'date' => today(), 'subject' => 'Работы', 'status' => 'active', 'currency' => 'USD', 'total_amount' => 10000, 'is_self_execution' => true]));
        foreach ([[$contract, '777.11'], [$otherCurrencyContract, '888.22']] as [$parent, $amount]) {
            ContractPerformanceAct::withoutEvents(fn () => ContractPerformanceAct::query()->create(['contract_id' => $parent->id, 'project_id' => $project->id,
                'act_document_number' => 'UNKNOWN-'.$parent->id, 'act_date' => today(), 'amount' => $amount, 'currency' => null, 'status' => 'approved']));
        }
        $reader = app(AssistantProjectActReader::class);
        $read = $reader->read($actor, $org, ['project_id' => (int) $project->id]);
        self::assertSame(2, $read['data']['pending']);
        self::assertCount(5, $read['data']['approved_examples']);
        self::assertStringContainsString('CURRENCY-2', $read['server_formatted_answer']);
        self::assertStringNotContainsString('CURRENCY-5', $read['server_formatted_answer']);
        self::assertSame([['currency' => 'RUB', 'amount' => '100.10'], ['currency' => 'USD', 'amount' => '9.25']], $read['data']['amounts_by_status']['pending']);
        self::assertSame([['currency' => 'RUB', 'amount' => '220.23'], ['currency' => 'USD', 'amount' => '3.00']], $read['data']['amounts_by_status']['approved']);
        self::assertSame(['pending' => 0, 'approved' => 2], $read['data']['amounts_unknown_currency']);
        self::assertStringContainsString('Не включены в суммы принятых актов: 2', $read['server_formatted_answer']);
        self::assertStringNotContainsString('1665.33', $read['server_formatted_answer']);
        self::assertStringContainsString('220.23 RUB; 3.00 USD', $read['server_formatted_answer']);
        self::assertTrue($reader->matchesReference($actor, $org, $read['source_refs'][0]));
        $member = $f->addMember(['ai-assistant' => ['ai_assistant.chat'], 'project-management' => ['projects.view'],
            'contract-management' => ['contracts.view', 'contracts.performance_acts.view']]);
        $member->assignedProjects()->attach($project->id, ['role' => 'member', 'is_active' => true]);
        $sumQueries = [];
        DB::listen(static function ($event) use (&$sumQueries): void { if (stripos($event->sql, 'sum(') !== false) { $sumQueries[] = $event->sql; } });
        $limited = $reader->read($member, $org, ['project_id' => (int) $project->id]);
        self::assertSame(2, $limited['data']['pending']);
        self::assertNull($limited['data']['amounts_by_status']);
        self::assertSame([], $sumQueries);
        self::assertStringContainsString('Суммы актов недоступны', $limited['server_formatted_answer']);
        self::assertStringNotContainsString('220.23', $limited['server_formatted_answer']);
        $slug = \App\Domain\Authorization\Models\UserRoleAssignment::query()->where('user_id', $member->id)->value('role_slug');
        $role = \App\Domain\Authorization\Models\OrganizationCustomRole::query()->where('organization_id', $org)->where('slug', $slug)->firstOrFail();
        $role->updateQuietly(['system_permissions' => ['finance.view']]);
        self::assertFalse($reader->matchesReference($member, $org, $limited['source_refs'][0]));
        $granted = $reader->read($member, $org, ['project_id' => (int) $project->id]);
        self::assertNotNull($granted['data']['amounts_by_status']);
        $role->updateQuietly(['system_permissions' => []]);
        self::assertFalse($reader->matchesReference($member, $org, $granted['source_refs'][0]));
        ContractPerformanceAct::query()->where('contract_id', $contract->id)->where('act_document_number', 'CURRENCY-3')->firstOrFail()->updateQuietly(['currency' => 'USD']);
        self::assertFalse($reader->matchesReference($actor, $org, $read['source_refs'][0]));
        ContractPerformanceAct::query()->where('project_id', $project->id)->whereNotNull('currency')->whereIn('status', ['approved', 'signed'])->update(['status' => 'draft']);
        $unknownOnly = $reader->read($actor, $org, ['project_id' => (int) $project->id]);
        self::assertSame([], $unknownOnly['data']['amounts_by_status']['approved']);
        self::assertSame(2, $unknownOnly['data']['amounts_unknown_currency']['approved']);
        self::assertStringContainsString('итог не подтверждён без валюты актов', $unknownOnly['server_formatted_answer']);
    }

    public function test_native_queues_count_before_limit_and_recheck_current_scope_and_visibility(): void
    {
        Queue::fake(); config(['cache.default' => 'array']);
        $f = Model::withoutEvents(fn () => AssistantRealAuthorizationFixture::create(array_column(app(PackageCatalogService::class)->allPackages(), 'slug')));
        $org = (int) $f->organization->id; $actor = $f->owner;
        $project = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $org]));
        $other = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $org]));
        $args = ['project_id' => (int) $project->id];
        $acts = app(AssistantProjectActReader::class); $sites = app(AssistantProjectSiteRequestReader::class); $tenders = app(AssistantTenderReader::class);
        $contract = Contract::withoutEvents(fn () => Contract::query()->create(['organization_id' => $org, 'project_id' => $project->id,
            'number' => 'NATIVE-ACTS', 'date' => today(), 'subject' => 'Работы', 'status' => 'active', 'total_amount' => 10000, 'is_self_execution' => true]));
        Model::withoutEvents(function () use ($org, $actor, $project, $other, $contract, $f): void {
            foreach (range(1, 23) as $i) {
                ContractPerformanceAct::query()->create(['contract_id' => $contract->id, 'project_id' => $project->id, 'act_document_number' => 'ACT-'.$i,
                    'act_date' => today(), 'amount' => 100, 'status' => 'pending_approval', 'submitted_at' => now()]);
                SiteRequest::query()->create(['organization_id' => $org, 'project_id' => $project->id, 'user_id' => $actor->id,
                    'title' => 'SITE-'.$i, 'request_type' => 'material_request', 'status' => 'pending', 'required_date' => today()->subDay()]);
                Tender::query()->create(['organization_id' => $org, 'project_id' => $i === 1 ? $other->id : $project->id,
                    'number' => 'TENDER-'.$i, 'title' => 'Тендер', 'status' => 'go_no_go', 'submission_deadline_at' => now()->subDay(), 'owner_user_id' => $actor->id]);
            }
            ContractPerformanceAct::query()->create(['contract_id' => $contract->id, 'project_id' => $project->id, 'act_date' => today(), 'amount' => 1, 'status' => 'draft']);
            SiteRequest::query()->create(['organization_id' => $org, 'project_id' => $project->id, 'user_id' => $actor->id,
                'title' => 'CANCELLED_PRIVATE', 'request_type' => 'material_request', 'status' => 'cancelled', 'required_date' => today()->subDay()]);
            SiteRequest::query()->create(['organization_id' => $org, 'project_id' => $project->id, 'user_id' => $f->administrator->id,
                'title' => 'OTHER_DRAFT_PRIVATE', 'request_type' => 'material_request', 'status' => 'draft', 'required_date' => today()->subDay()]);
            Tender::query()->create(['organization_id' => $org, 'project_id' => null, 'number' => 'PROJECTLESS', 'title' => 'Без проекта', 'status' => 'preparation', 'submission_deadline_at' => now()->addDay()]);
            Tender::query()->create(['organization_id' => $org, 'project_id' => $project->id, 'number' => 'SUBMITTED', 'title' => 'Подана', 'status' => 'submitted', 'submission_deadline_at' => now()->subDay(), 'submitted_at' => now()->subDays(2)]);
            Tender::query()->create(['organization_id' => $org, 'project_id' => $project->id, 'number' => 'CANCELLED', 'title' => 'Отменён', 'status' => 'cancelled', 'submission_deadline_at' => now()->subDay()]);
        });
        $act = $acts->read($actor, $org, $args); $site = $sites->read($actor, $org, $args); $tender = $tenders->read($actor, $org, ['project_id' => null]);
        self::assertSame(24, $act['data']['total']); self::assertSame(23, $act['data']['pending']); self::assertCount(20, $act['data']['examples']);
        self::assertSame(23, $site['data']['active']); self::assertSame(23, $site['data']['overdue']); self::assertSame(23, $site['data']['unassigned']);
        self::assertSame(23, $site['data']['attention']); self::assertCount(20, $site['data']['examples']);
        self::assertStringNotContainsString('PRIVATE', json_encode($site));
        self::assertSame(26, $tender['data']['total']); self::assertSame(23, $tender['data']['pending']);
        self::assertSame(23, $tender['data']['submission_overdue']); self::assertSame(1, $tender['data']['submission_soon']); self::assertCount(20, $tender['data']['examples']);
        self::assertSame(22, $tenders->read($actor, $org, $args)['data']['pending']);
        $limited = $f->addMember(['ai-assistant' => ['ai_assistant.chat'], 'project-management' => ['projects.view'], 'tenders' => ['tenders.view']]);
        $limited->assignedProjects()->attach($project->id, ['role' => 'member', 'is_active' => true]);
        $limitedResult = $tenders->read($limited, $org, ['project_id' => null]);
        self::assertSame(22, $limitedResult['data']['pending']);
        self::assertArrayNotHasKey('native_summary', $limitedResult['data']);
        self::assertNotContains((int) $other->id, array_column($limitedResult['data']['examples'], 'project_id'));
        self::assertTrue($acts->matchesReference($actor, $org, $act['source_refs'][0]));
        self::assertTrue($sites->matchesReference($actor, $org, $site['source_refs'][0]));
        self::assertTrue($tenders->matchesReference($actor, $org, $tender['source_refs'][0]));
        ContractPerformanceAct::query()->where('act_document_number', 'ACT-1')->firstOrFail()->updateQuietly(['status' => 'approved']);
        self::assertFalse($acts->matchesReference($actor, $org, $act['source_refs'][0]));
        SiteRequest::query()->where('title', 'SITE-1')->firstOrFail()->updateQuietly(['status' => 'completed']);
        self::assertFalse($sites->matchesReference($actor, $org, $site['source_refs'][0]));
        Tender::query()->where('number', 'TENDER-1')->firstOrFail()->updateQuietly(['status' => 'won']);
        self::assertFalse($tenders->matchesReference($actor, $org, $tender['source_refs'][0]));
        $f->ownerAssignment->updateQuietly(['is_active' => false]);
        self::assertFalse($sites->matchesReference($actor, $org, $site['source_refs'][0]));
        self::assertFalse($tenders->matchesReference($actor, $org, $tender['source_refs'][0]));
    }

    public function test_staff_roster_uses_native_view_permission_only_minimal_fields_and_current_dates(): void
    {
        Queue::fake(); config(['cache.default' => 'array']);
        $f = Model::withoutEvents(fn () => AssistantRealAuthorizationFixture::create(array_column(app(PackageCatalogService::class)->allPackages(), 'slug')));
        $org = (int) $f->organization->id;
        $project = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $org]));
        $member = $f->addMember(['ai-assistant' => ['ai_assistant.chat'], 'project-management' => ['projects.view'], 'workforce-management' => ['workforce.view']]);
        $member->assignedProjects()->attach($project->id, ['role' => 'member', 'is_active' => true]);
        $timestamps = ['created_at' => now(), 'updated_at' => now()];
        $department = DB::table('workforce_departments')->insertGetId(['organization_id' => $org, 'code' => 'DEP', 'name' => 'Монтаж'] + $timestamps);
        $position = DB::table('workforce_positions')->insertGetId(['organization_id' => $org, 'code' => 'POS', 'name' => 'Монтажник'] + $timestamps);
        $unit = DB::table('workforce_staff_units')->insertGetId(['organization_id' => $org, 'department_id' => $department, 'position_id' => $position,
            'code' => 'UNIT', 'valid_from' => today()->subMonth()] + $timestamps);
        $firstAssignment = null;
        foreach (range(1, 23) as $i) {
            $employee = WorkforceEmployee::withoutEvents(fn () => WorkforceEmployee::query()->create(['organization_id' => $org,
                'personnel_number' => 'EMP-'.$i, 'last_name' => 'Иванов', 'first_name' => 'Иван'.$i, 'hire_date' => today()->subYear(),
                'email' => 'PRIVATE_EMPLOYEE@example.invalid', 'phone' => 'PRIVATE_PHONE', 'external_payroll_ref' => 'PRIVATE_PAYROLL_'.$i]));
            $assignment = ['organization_id' => $org, 'employee_id' => $employee->id, 'staff_unit_id' => $unit, 'department_id' => $department,
                'position_id' => $position, 'project_id' => $project->id, 'status' => 'active', 'valid_from' => today()->subDay()];
            $id = DB::table('workforce_employee_assignments')->insertGetId($assignment + $timestamps);
            if ($firstAssignment === null) { $firstAssignment = $id; }
            if ($i === 1) { DB::table('workforce_employee_assignments')->insert($assignment + $timestamps); }
            if ($i === 2) { DB::table('workforce_employee_assignments')->insert(array_replace($assignment, ['valid_from' => today()->addDay()]) + $timestamps); }
            if ($i === 3) { DB::table('workforce_employee_assignments')->insert(array_replace($assignment, ['status' => 'inactive']) + $timestamps); }
        }
        $reader = app(AssistantProjectStaffReader::class); $args = ['project_id' => (int) $project->id];
        $result = $reader->read($member, $org, $args);
        self::assertSame(23, $result['data']['employees']); self::assertSame(24, $result['data']['assignments']); self::assertCount(20, $result['data']['examples']);
        self::assertStringNotContainsString('PRIVATE_', json_encode($result));
        self::assertSame('Монтажник', $result['data']['examples'][0]['position']); self::assertTrue($reader->matchesReference($member, $org, $result['source_refs'][0]));
        DB::table('workforce_employee_assignments')->where('id', $firstAssignment)->update(['valid_to' => today()->subDay()]);
        self::assertFalse($reader->matchesReference($member, $org, $result['source_refs'][0]));
        DB::table('workforce_positions')->where('id', $position)->update(['organization_id' => $f->foreignOrganization->id]);
        self::assertSame(0, $reader->read($member, $org, $args)['data']['employees']);
    }
}
