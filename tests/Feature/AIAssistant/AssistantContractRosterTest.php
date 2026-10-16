<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AssistantProjectContractRosterReader;
use App\BusinessModules\Features\AIAssistant\Services\AssistantSourceReferenceGuard;
use App\Models\Contract;
use App\Models\ContractOrganizationView;
use App\Models\Contractor;
use App\Models\ContractParty;
use App\Models\Project;
use App\Services\Modules\PackageCatalogService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

final class AssistantContractRosterTest extends TestCase
{
    public function test_shared_party_names_follow_native_direction_without_disclosing_private_party_fields(): void
    {
        Queue::fake(); config(['cache.default' => 'array']);
        $fixture = Model::withoutEvents(fn () => AssistantRealAuthorizationFixture::create(array_column(app(PackageCatalogService::class)->allPackages(), 'slug')));
        $project = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $fixture->organization->id]));
        $foreignProject = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $fixture->foreignOrganization->id]));
        $reader = app(AssistantProjectContractRosterReader::class);
        $contracts = [];
        foreach (['expense', 'income'] as $direction) {
            $contract = Contract::withoutEvents(fn () => Contract::query()->create([
                'organization_id' => $fixture->foreignOrganization->id, 'project_id' => $foreignProject->id,
                'contract_side_type' => 'general_contract', 'number' => 'SHARED-'.$direction, 'date' => '2026-06-01',
                'subject' => 'Общий договор', 'status' => 'active', 'end_date' => null, 'is_multi_project' => true,
                'is_self_execution' => false, 'total_amount' => '123456.78',
            ]));
            Model::withoutEvents(fn () => $contract->projects()->attach($project->id));
            $view = ContractOrganizationView::query()->create(['contract_id' => $contract->id, 'organization_id' => $fixture->organization->id, 'visibility' => 'active', 'version' => 1]);
            foreach (['first', 'second'] as $side) {
                $isCurrent = $side === ($direction === 'income' ? 'second' : 'first');
                ContractParty::withoutEvents(fn () => ContractParty::query()->create([
                    'contract_id' => $contract->id, 'side' => $side, 'party_key' => $side,
                    'role' => $side === 'first' ? 'customer' : 'general_contractor',
                    'linked_organization_id' => $isCurrent ? $fixture->organization->id : $fixture->foreignOrganization->id,
                    'name' => $isCurrent ? 'Наша организация' : 'Контрагент '.$direction,
                    'inn' => 'private_inn_marker', 'email' => 'private_email_marker@example.test',
                    'phone' => 'private_phone_marker', 'snapshot' => ['private' => 'private_snapshot_marker'],
                ]));
            }
            $contracts[$direction] = [$contract, $view];
        }
        $result = $reader->read($fixture->owner, $fixture->organization->id, ['project_id' => $project->id]);
        self::assertSame(2, $result['data']['total']);
        $rows = array_column($result['data']['items'], null, 'id');
        foreach ($contracts as $direction => [$contract]) {
            $native = app(\App\Services\Contract\ContractSideResolverService::class)->resolve($contract, $fixture->organization->id);
            $opposite = $native[$native['is_income'] ? 'first_party' : 'second_party'];
            self::assertSame('Контрагент '.$direction, $opposite['name']);
            self::assertSame($opposite['name'], $rows[$contract->id]['counterparty']);
            self::assertSame($native[$native['is_income'] ? 'second_party' : 'first_party']['role'], $rows[$contract->id]['our_role']);
            self::assertSame($opposite['role'], $rows[$contract->id]['counterparty_role']);
            self::assertStringContainsString('Контрагент '.$direction, $result['server_formatted_answer']);
        }
        self::assertStringContainsString('Наша роль: Заказчик', $result['server_formatted_answer']);
        self::assertStringContainsString('Наша роль: Генподрядчик', $result['server_formatted_answer']);
        \Illuminate\Support\Facades\DB::table('contract_parties')->where('contract_id', $contracts['income'][0]->id)->where('side', 'second')->update(['role' => 'contractor']);
        self::assertFalse($reader->matchesReference($fixture->owner, $fixture->organization->id, $result['source_refs'][0]));
        $roleChanged = $reader->read($fixture->owner, $fixture->organization->id, ['project_id' => $project->id]);
        self::assertSame('contractor', array_column($roleChanged['data']['items'], null, 'id')[$contracts['income'][0]->id]['our_role']);
        self::assertStringContainsString('Наша роль: Подрядчик', $roleChanged['server_formatted_answer']);
        foreach (['private_inn_marker', 'private_email_marker', 'private_phone_marker', 'private_snapshot_marker', '123456.78', 'Наша организация'] as $excluded) {
            self::assertStringNotContainsString($excluded, json_encode($result, JSON_UNESCAPED_UNICODE));
        }
        $contracts['expense'][0]->secondParty()->update(['name' => 'Изменённый контрагент']);
        self::assertFalse($reader->matchesReference($fixture->owner, $fixture->organization->id, $result['source_refs'][0]));
        $contracts['expense'][1]->update(['access_revoked_at' => now()]);
        $current = $reader->read($fixture->owner, $fixture->organization->id, ['project_id' => $project->id]);
        self::assertSame(1, $current['data']['total']);
        self::assertStringNotContainsString('Изменённый контрагент', $current['server_formatted_answer']);
        $contracts['income'][1]->update(['visibility' => 'archived']);
        self::assertFalse($reader->matchesReference($fixture->owner, $fixture->organization->id, $current['source_refs'][0]));
        self::assertSame(0, $reader->read($fixture->owner, $fixture->organization->id, ['project_id' => $project->id])['data']['total']);
    }

    public function test_roster_matches_native_active_placement_status_and_multiproject_scope_without_shared_counterparty_leak(): void
    {
        Queue::fake(); config(['cache.default' => 'array']);
        $fixture = Model::withoutEvents(fn () => AssistantRealAuthorizationFixture::create(array_column(app(PackageCatalogService::class)->allPackages(), 'slug')));
        $project = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $fixture->organization->id]));
        $foreignProject = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $fixture->foreignOrganization->id]));
        $counterparty = Contractor::withoutEvents(fn () => Contractor::query()->create(['organization_id' => $fixture->organization->id, 'name' => 'Подрядчик проекта']));
        $base = ['organization_id' => $fixture->organization->id, 'project_id' => $project->id, 'contractor_id' => $counterparty->id,
            'contract_side_type' => 'contract', 'date' => '2026-06-01', 'subject' => 'Договор', 'work_type_category' => 'smr',
            'base_amount' => '100.00', 'total_amount' => '100.00', 'gp_percentage' => 0, 'planned_advance_amount' => 0,
            'actual_advance_amount' => 0, 'status' => 'active', 'start_date' => '2026-06-01', 'end_date' => '2026-09-30',
            'is_fixed_amount' => true, 'is_multi_project' => false, 'is_self_execution' => false];
        $own = Contract::withoutEvents(fn () => Contract::query()->create(['number' => 'OWN-ACTIVE'] + $base));
        Contract::withoutEvents(fn () => Contract::query()->create(['number' => 'DRAFT', 'status' => 'draft'] + $base));
        $archived = Contract::withoutEvents(fn () => Contract::query()->create(['number' => 'ARCHIVED-VIEW'] + $base));
        ContractOrganizationView::query()->create(['contract_id' => $archived->id, 'organization_id' => $fixture->organization->id, 'visibility' => 'archived', 'version' => 1]);
        $shared = Contract::withoutEvents(fn () => Contract::query()->create(['number' => 'SHARED-ACTIVE', 'organization_id' => $fixture->foreignOrganization->id,
            'project_id' => $foreignProject->id, 'end_date' => null, 'is_multi_project' => true] + $base));
        Model::withoutEvents(fn () => $shared->projects()->attach($project->id));
        $view = ContractOrganizationView::query()->create(['contract_id' => $shared->id, 'organization_id' => $fixture->organization->id, 'visibility' => 'active', 'version' => 1]);
        Contract::withoutEvents(fn () => Contract::query()->create(['number' => 'FOREIGN-NOT-SHARED', 'organization_id' => $fixture->foreignOrganization->id, 'project_id' => $foreignProject->id] + $base));
        $reader = app(AssistantProjectContractRosterReader::class);
        $read = $reader->read($fixture->owner, $fixture->organization->id, ['project_id' => $project->id]);
        self::assertSame(2, $read['data']['total']);
        self::assertSame(1, $read['data']['overdue_count']);
        self::assertSame(0, $read['data']['ending_soon_count']);
        self::assertSame(1, $read['data']['missing_end_date_count']);
        $rows = array_column($read['data']['items'], null, 'id');
        self::assertSame('Подрядчик проекта', $rows[$own->id]['counterparty']);
        self::assertNull($rows[$shared->id]['counterparty']);
        self::assertStringContainsString('дата уже прошла', mb_strtolower($read['server_formatted_answer']));
        foreach (['DRAFT', 'ARCHIVED-VIEW', 'FOREIGN-NOT-SHARED', 'total_amount', '100.00'] as $excluded) { self::assertStringNotContainsString($excluded, json_encode($read)); }
        self::assertTrue(app(AssistantSourceReferenceGuard::class)->fresh($fixture->owner, $fixture->organization->id, $read['source_refs']));
        $view->update(['access_revoked_at' => now()]);
        self::assertFalse($reader->matchesReference($fixture->owner, $fixture->organization->id, $read['source_refs'][0]));
        for ($i = 0; $i < 21; $i++) { Contract::withoutEvents(fn () => Contract::query()->create(['number' => 'EXTRA-'.$i] + $base)); }
        $full = $reader->read($fixture->owner, $fixture->organization->id, ['project_id' => $project->id]);
        self::assertSame(22, $full['data']['total']);
        self::assertCount(20, $full['data']['items']);
        self::assertSame(22, $full['data']['overdue_count'] + $full['data']['ending_soon_count'] + $full['data']['missing_end_date_count']);
        foreach ([0, 7, 10] as $days) { Contract::withoutEvents(fn () => Contract::query()->create(['number' => 'DEADLINE-'.$days, 'end_date' => today()->addDays($days)->toDateString()] + $base)); }
        $deadlines = $reader->read($fixture->owner, $fixture->organization->id, ['project_id' => $project->id]);
        self::assertSame(25, $deadlines['data']['total']); self::assertSame(22, $deadlines['data']['overdue_count']);
        self::assertSame(2, $deadlines['data']['ending_soon_count']); self::assertSame(0, $deadlines['data']['missing_end_date_count']);
        Contract::withoutEvents(fn () => Contract::query()->create(['number' => 'UNDATED-AFTER-25', 'end_date' => null] + $base));
        $undated = $reader->read($fixture->owner, $fixture->organization->id, ['project_id' => $project->id]);
        self::assertSame(26, $undated['data']['total']);
        self::assertCount(20, $undated['data']['items']);
        self::assertCount(1, $undated['data']['undated_items']);
        self::assertSame('UNDATED-AFTER-25', $undated['data']['undated_items'][0]['number']);
        self::assertStringContainsString('Договоры без даты окончания: показано 1 из 1.', $undated['server_formatted_answer']);
        self::assertStringContainsString('UNDATED-AFTER-25', $undated['server_formatted_answer']);
        $missingOnly = $reader->read($fixture->owner, $fixture->organization->id, ['project_id' => $project->id, 'deadline_filter' => 'undated']);
        self::assertSame(1, $missingOnly['data']['selected_total']);
        self::assertSame(['UNDATED-AFTER-25'], array_column($missingOnly['data']['items'], 'number'));
        self::assertStringNotContainsString('OWN-ACTIVE', $missingOnly['server_formatted_answer']);
        self::assertStringNotContainsString('DEADLINE-', $missingOnly['server_formatted_answer']);
        self::assertSame($missingOnly['server_formatted_answer'], $reader->verifiedAnswer($missingOnly, $fixture->owner, $fixture->organization->id)['text']);
        $overdueOnly = $reader->read($fixture->owner, $fixture->organization->id, ['project_id' => $project->id, 'deadline_filter' => 'overdue']);
        self::assertSame(22, $overdueOnly['data']['selected_total']);
        self::assertSame([], $overdueOnly['data']['undated_items']);
        self::assertStringNotContainsString('UNDATED-AFTER-25', $overdueOnly['server_formatted_answer']);
        self::assertStringNotContainsString('DEADLINE-', $overdueOnly['server_formatted_answer']);
        self::assertSame($overdueOnly['server_formatted_answer'], $reader->verifiedAnswer($overdueOnly, $fixture->owner, $fixture->organization->id)['text']);
        $combined = $reader->read($fixture->owner, $fixture->organization->id, ['project_id' => $project->id, 'deadline_filter' => 'overdue_or_undated']);
        self::assertSame(23, $combined['data']['selected_total']);
        self::assertStringContainsString('UNDATED-AFTER-25', $combined['server_formatted_answer']);
        self::assertStringNotContainsString('DEADLINE-', $combined['server_formatted_answer']);
        $forged = $missingOnly['source_refs'][0]; $forged['deadline_filter'] = 'all';
        self::assertFalse($reader->matchesReference($fixture->owner, $fixture->organization->id, $forged));
    }
}
