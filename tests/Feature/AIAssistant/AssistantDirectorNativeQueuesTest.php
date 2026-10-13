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
