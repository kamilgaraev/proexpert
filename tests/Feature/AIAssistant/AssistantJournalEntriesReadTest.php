<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Actions\Domains\GetProjectJournalEntriesTool;
use App\BusinessModules\Features\AIAssistant\Services\AIPermissionChecker;
use App\BusinessModules\Features\AIAssistant\Services\AssistantSourceReferenceGuard;
use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantProjectJournalEntriesReader;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Enums\ProjectOrganizationRole;
use App\Models\ConstructionJournal;
use App\Models\ConstructionJournalEntry;
use App\Models\Project;
use App\Services\ConstructionJournal\ConstructionJournalAccessService;
use App\Services\ConstructionJournal\JournalEntryQueryService;
use App\Services\Modules\PackageCatalogService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

final class AssistantJournalEntriesReadTest extends TestCase
{
    protected function tearDown(): void
    {
        if (DB::transactionLevel() === 0) {
            DB::beginTransaction();
        }
        parent::tearDown();
    }

    public function test_shared_journal_returns_all_statuses_content_and_fresh_native_scope(): void
    {
        [$fixture, $project, $journal, $other] = $this->fixture();
        $long = str_repeat('Бетонирование ', 35).'[ссылка](https://example.invalid)';
        $approved = $this->entry($journal, 1, 'approved', $long);
        $submitted = $this->entry($journal, 2, 'submitted', 'На согласовании');
        $this->entry($other, 1, 'approved', 'SECRET-OTHER-JOURNAL');
        $this->entry($journal, 3, 'draft', 'SECRET-DELETED')->deleteQuietly();
        DB::commit();
        $actor = $fixture->owner;
        $org = (int) $fixture->organization->id;
        $this->actingAs($actor);
        $native = app(ConstructionJournalAccessService::class);
        self::assertTrue($native->canAccessProject($actor, $project));
        self::assertContains((int) $fixture->organization->id, $native->visiblePerformerIds($actor, $project));
        self::assertNotSame((int) $journal->organization_id, $org);
        Gate::forUser($actor)->authorize('view', $journal);
        $nativeEntries = app(JournalEntryQueryService::class)->query($journal, [], $actor)->orderBy('entry_date')->orderBy('id')->get();
        $tool = app(GetProjectJournalEntriesTool::class);
        self::assertTrue(app(AIPermissionChecker::class)->canExposeTool($actor, $tool->getName()));
        $arguments = ['project_id' => (int) $project->id, 'journal_name' => '  общий   журнал ', 'journal_number' => '1', 'offset' => 0];
        $result = $tool->execute($arguments, $actor, $fixture->organization);
        self::assertSame([(int) $approved->id, (int) $submitted->id], array_column($result['data']['entries'], 'id'));
        self::assertSame($nativeEntries->modelKeys(), array_column($result['data']['entries'], 'id'));
        self::assertSame(['approved', 'submitted'], array_column($result['data']['entries'], 'status'));
        self::assertSame(['2026-10-11', '2026-10-11'], array_column($result['data']['entries'], 'date'));
        self::assertSame($long, $result['data']['entries'][0]['description']);
        self::assertSame(2, $result['data']['entry_total']);
        self::assertFalse($result['data']['has_more']);
        self::assertStringNotContainsString('SECRET', json_encode($result, JSON_UNESCAPED_UNICODE));
        self::assertStringContainsString('Утверждена', $result['server_formatted_answer']);
        self::assertStringContainsString('На согласовании', $result['server_formatted_answer']);
        self::assertStringContainsString('\\[ссылка\\]', $result['server_formatted_answer']);
        self::assertStringContainsString('/journals/'.$journal->id.'/entries/'.$approved->id, $result['server_formatted_answer']);
        $guard = app(AssistantSourceReferenceGuard::class);
        self::assertTrue($guard->fresh($actor, $org, $result['source_refs']));
        $tampered = $result['source_refs'];
        $tampered[0]['_read_arguments']['journal_number'] = '2';
        self::assertFalse($guard->fresh($actor, $org, $tampered));
        $approved->updateQuietly(['work_description' => 'Новые данные']);
        self::assertFalse($guard->fresh($actor, $org, $result['source_refs']));
        $fresh = $tool->execute($arguments, $actor, $fixture->organization);
        self::assertSame('Новые данные', $fresh['data']['entries'][0]['description']);
        $actor->assignedProjects()->updateExistingPivot($project->id, ['is_active' => false]);
        self::assertFalse($guard->fresh($actor, $org, $fresh['source_refs']));
        $this->expectException(AuthorizationException::class);
        $tool->execute($arguments, $actor, $fixture->organization);
    }

    public function test_window_limits_and_ambiguous_selectors_never_claim_a_complete_list(): void
    {
        [$fixture, $project, $journal] = $this->fixture();
        foreach (range(1, 51) as $number) {
            $this->entry($journal, $number, ['draft', 'submitted', 'approved', 'rejected'][$number % 4],
                $number === 1 ? str_repeat('Я', 4001) : 'Работа '.$number);
        }
        DB::commit();
        $reader = app(AssistantProjectJournalEntriesReader::class);
        $args = ['project_id' => (int) $project->id, 'journal_name' => 'Общий журнал', 'journal_number' => '1', 'offset' => 0];
        $result = $reader->read($fixture->owner, (int) $fixture->organization->id, $args);
        self::assertSame(51, $result['data']['entry_total']);
        self::assertCount(50, $result['data']['entries']);
        self::assertTrue($result['data']['has_more']);
        self::assertSame(4000, mb_strlen($result['data']['entries'][0]['description']));
        self::assertTrue($result['data']['entries'][0]['description_truncated']);
        self::assertStringContainsString('Это часть списка', $result['server_formatted_answer']);
        self::assertStringContainsString('Покажи записи журнала №1 «Общий журнал»', $result['server_formatted_answer']);
        self::assertStringContainsString('в проекте «'.$project->name.'» начиная с 51.', $result['server_formatted_answer']);
        self::assertStringNotContainsString('Показаны все записи', $result['server_formatted_answer']);
        $last = $reader->read($fixture->owner, (int) $fixture->organization->id, array_replace($args, ['offset' => 50]));
        self::assertCount(1, $last['data']['entries']);
        self::assertFalse($last['data']['has_more']);
        self::assertStringNotContainsString('Показаны все записи', $last['server_formatted_answer']);
        self::assertSame([], array_intersect(array_column($result['data']['entries'], 'id'), array_column($last['data']['entries'], 'id')));
        foreach ([array_replace($args, ['journal_number' => null]), array_replace($args, ['journal_number' => 'NO-SUCH-NUMBER']),
            ['project_id' => (int) $project->id]] as $selection) {
            $unresolved = $reader->read($fixture->owner, (int) $fixture->organization->id, $selection);
            self::assertTrue($unresolved['data']['journal_unresolved']);
            self::assertArrayNotHasKey('entries', $unresolved['data']);
            self::assertTrue($reader->verifiedAnswer($unresolved, $fixture->owner, (int) $fixture->organization->id)['needs_clarification']);
        }
    }

    public function test_project_only_journal_role_is_checked_again_after_revocation(): void
    {
        [$fixture, $project, $journal] = $this->fixture();
        $actor = Model::withoutEvents(fn () => $fixture->addMember(['ai-assistant' => ['ai_assistant.chat'],
            'project-management' => ['projects.view']]));
        $actor->assignedProjects()->attach($project->id, ['is_active' => true, 'role' => 'member']);
        $assignment = Model::withoutEvents(fn () => UserRoleAssignment::assignRole(user: $actor, roleSlug: 'project_manager',
            context: AuthorizationContext::getProjectContext((int) $project->id, (int) $fixture->organization->id)));
        $entry = $this->entry($journal, 1, 'submitted', 'Разрешённая запись');
        DB::commit();
        $org = (int) $fixture->organization->id;
        $access = app(\App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy::class);
        self::assertFalse($access->canCurrentPermission($actor, $org, 'construction-journal.view'));
        self::assertTrue($access->canCurrentProjectPermission($actor, $org, (int) $project->id, 'construction-journal.view'));
        self::assertTrue(app(AIPermissionChecker::class)->canExposeTool($actor, 'get_project_journal_entries'));
        $reader = app(AssistantProjectJournalEntriesReader::class);
        $arguments = ['project_id' => (int) $project->id, 'journal_number' => '1'];
        $result = $reader->read($actor, $org, $arguments);
        self::assertSame([(int) $entry->id], array_column($result['data']['entries'], 'id'));
        $assignment->updateQuietly(['is_active' => false]);
        self::assertFalse(app(AssistantSourceReferenceGuard::class)->fresh($actor, $org, $result['source_refs']));
        $this->expectException(AuthorizationException::class);
        $reader->read($actor, $org, $arguments);
    }

    private function fixture(): array
    {
        Queue::fake();
        config(['cache.default' => 'array']);
        $fixture = Model::withoutEvents(fn () => AssistantRealAuthorizationFixture::create(
            array_column(app(PackageCatalogService::class)->allPackages(), 'slug')));
        $actorOrg = $fixture->organization;
        $owner = $fixture->foreignOrganization;
        $project = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $owner->id, 'is_archived' => false]));
        $fixture->owner->organizations()->updateExistingPivot($actorOrg->id, ['project_access_mode' => 'assigned_projects']);
        $fixture->owner->assignedProjects()->attach($project->id, ['is_active' => true, 'role' => 'member']);
        $project->organizations()->attach($actorOrg->id, ['is_active' => true, 'role' => 'child_contractor',
            'role_new' => ProjectOrganizationRole::SUBCONTRACTOR->value]);
        AuthorizationContext::getProjectContext((int) $project->id, (int) $actorOrg->id);
        DB::table('project_organization_hierarchy')->insert([
            ['project_id' => $project->id, 'organization_id' => $owner->id, 'parent_organization_id' => null],
            ['project_id' => $project->id, 'organization_id' => $actorOrg->id, 'parent_organization_id' => $owner->id],
        ]);
        $create = fn (string $number): ConstructionJournal => Model::withoutEvents(fn () => ConstructionJournal::query()->create([
            'organization_id' => $owner->id, 'performing_organization_id' => $actorOrg->id, 'project_id' => $project->id,
            'name' => 'Общий журнал', 'journal_number' => $number, 'start_date' => '2026-10-01',
            'status' => 'active', 'created_by_user_id' => $fixture->owner->id]));

        return [$fixture, $project, $create('1'), $create('2')];
    }

    private function entry(ConstructionJournal $journal, int $number, string $status, string $description): ConstructionJournalEntry
    {
        return Model::withoutEvents(fn () => ConstructionJournalEntry::query()->create(['journal_id' => $journal->id,
            'entry_date' => '2026-10-11', 'entry_number' => $number, 'status' => $status, 'work_description' => $description,
            'created_by_user_id' => $journal->created_by_user_id]));
    }
}
