<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Features\AIAssistant\Actions\Reports\Tools\GenerateCustomReportTool;
use App\BusinessModules\Features\AIAssistant\Services\AIPermissionChecker;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantProjectJournalReader;
use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantCustomReportBuilder;
use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantCustomReportReference;
use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantCustomReportRenderer;
use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantGeneratedReportStorage;
use App\BusinessModules\Features\AIAssistant\Services\StatusSnapshots\AssistantStatusSnapshotFootprint;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Enums\ProjectOrganizationRole;
use App\Models\ConstructionJournal;
use App\Models\ConstructionJournalEntry;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\Organization;
use App\Models\Project;
use App\Services\ConstructionJournal\ConstructionJournalAccessService;
use App\Services\Modules\PackageCatalogService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

final class AssistantJournalReportVisibilityTest extends TestCase
{
    public function test_scoped_journal_reference_does_not_discover_other_accessible_project_hierarchies(): void
    {
        [$fixture, $project, $journals] = $this->fixture();
        $actor = $fixture->owner;
        $organizationId = (int) $fixture->organization->id;
        $otherProject = $journals[5]->project;
        $otherProject->organizations()->attach($organizationId, ['is_active' => true, 'role' => 'child_contractor',
            'role_new' => ProjectOrganizationRole::SUBCONTRACTOR->value]);
        $actor->assignedProjects()->attach($otherProject->id, ['is_active' => true, 'role' => 'member']);
        AuthorizationContext::getProjectContext((int) $otherProject->id, $organizationId);
        DB::table('project_organization_hierarchy')->insert([
            ['project_id' => $otherProject->id, 'organization_id' => $otherProject->organization_id, 'parent_organization_id' => null],
            ['project_id' => $otherProject->id, 'organization_id' => $organizationId, 'parent_organization_id' => $otherProject->organization_id],
        ]);
        $plan = $this->plan();
        $plan['sections'][0]['project_id'] = (int) $project->id;
        $report = app(AssistantCustomReportBuilder::class)->build($plan, $actor, $organizationId);
        self::assertSame(2, $report['sections'][0]['source_row_count']);
        $realParticipants = app(\App\Services\Project\ProjectParticipantService::class);
        $observed = [];
        $participants = \Mockery::mock(\App\Services\Project\ProjectParticipantService::class);
        $participants->shouldReceive('getHierarchiesWithActivity')->andReturnUsing(function (array $projects) use ($realParticipants, &$observed): array {
            foreach ($projects as $candidate) {
                $observed[] = (int) $candidate->id;
            }

            return $realParticipants->getHierarchiesWithActivity($projects);
        });
        app()->instance(\App\Services\Project\ProjectParticipantService::class, $participants);
        try {
            $policy = app(AssistantDataAccessPolicy::class);
            $allIds = $policy->withCurrentChecks($actor, $organizationId, function () use ($policy, $actor, $organizationId): array {
                $query = $policy->entityQuery($actor, $organizationId, 'construction_journal');
                self::assertNotNull($query);

                return $query->orderBy('construction_journals.id')->pluck('construction_journals.id')->all();
            }, fresh: true);
            self::assertContains($journals[5]->id, $allIds);
            self::assertContains((int) $project->id, $observed);
            self::assertContains((int) $otherProject->id, $observed);
            $observed = [];
            self::assertTrue(app(AssistantCustomReportReference::class)->canReadReference($actor, $organizationId, $report['source_refs'][0]));
            self::assertSame([(int) $project->id], array_values(array_unique($observed)));
        } finally {
            app()->instance(\App\Services\Project\ProjectParticipantService::class, $realParticipants);
        }
    }

    public function test_scoped_and_organization_journal_queries_keep_separate_compilers_and_visibility(): void
    {
        [$fixture, $project, $journals] = $this->fixture();
        $otherProject = $this->makeSecondJournalProjectAccessible($fixture, $journals[5]);
        $actor = $fixture->owner;
        $organizationId = (int) $fixture->organization->id;
        $policy = app(AssistantDataAccessPolicy::class);
        $expected = [0 => [$journals[0]->id, $journals[1]->id, $journals[5]->id],
            (int) $project->id => [$journals[0]->id, $journals[1]->id], (int) $otherProject->id => [$journals[5]->id]];
        foreach ([[null, $project->id, $otherProject->id, null], [$otherProject->id, null, $project->id], [$project->id, $otherProject->id, null]] as $order) {
            $policy->withCurrentChecks($actor, $organizationId, function () use ($policy, $actor, $organizationId, $order, $expected): void {
                foreach ($order as $projectId) {
                    $query = $projectId === null ? $policy->entityQuery($actor, $organizationId, 'construction_journal')
                        : $policy->constructionJournalReferenceQuery($actor, $organizationId, (int) $projectId);
                    self::assertNotNull($query);
                    self::assertSame($expected[$projectId ?? 0], (clone $query)->orderBy('construction_journals.id')->pluck('construction_journals.id')->all());
                    self::assertSame(count($expected[$projectId ?? 0]), $query->count());
                    if ($projectId !== null) {
                        $legacy = $policy->entityQuery($actor, $organizationId, 'construction_journal');
                        self::assertNotNull($legacy);
                        self::assertTrue($policy->applyEntityProjectScope($actor, $organizationId, 'construction_journal', $legacy, (int) $projectId));
                        self::assertSame($expected[$projectId], $legacy->orderBy('construction_journals.id')->pluck('construction_journals.id')->all());
                    }
                }
            }, fresh: true);
        }
    }

    public function test_scoped_journal_reference_rechecks_hierarchy_activity_membership_and_placement(): void
    {
        [$fixture, $project, $journals] = $this->fixture();
        $otherProject = $this->makeSecondJournalProjectAccessible($fixture, $journals[5]);
        $contractId = $journals[0]->contract_id;
        $actor = $fixture->owner;
        $organizationId = (int) $fixture->organization->id;
        $plan = $this->plan();
        $plan['sections'][0]['project_id'] = (int) $project->id;
        $reference = app(AssistantCustomReportBuilder::class)->build($plan, $actor, $organizationId)['source_refs'][0];
        $references = app(AssistantCustomReportReference::class);
        self::assertTrue($references->canReadReference($actor, $organizationId, $reference));
        $hierarchy = fn () => DB::table('project_organization_hierarchy')->where('project_id', $project->id)
            ->where('organization_id', $journals[1]->performing_organization_id);
        $mutations = [
            [fn () => $hierarchy()->update(['parent_organization_id' => $project->organization_id]),
                fn () => $hierarchy()->update(['parent_organization_id' => $organizationId])],
            [fn () => Organization::query()->whereKey($journals[1]->performing_organization_id)->update(['is_active' => false]),
                fn () => Organization::query()->whereKey($journals[1]->performing_organization_id)->update(['is_active' => true])],
            [fn () => $project->organizations()->updateExistingPivot($organizationId, ['is_active' => false]),
                fn () => $project->organizations()->updateExistingPivot($organizationId, ['is_active' => true])],
            [fn () => $actor->assignedProjects()->updateExistingPivot($project->id, ['is_active' => false]),
                fn () => $actor->assignedProjects()->updateExistingPivot($project->id, ['is_active' => true])],
            [fn () => $fixture->ownerAssignment->update(['is_active' => false]),
                fn () => $fixture->ownerAssignment->update(['is_active' => true])],
            [fn () => $actor->organizations()->updateExistingPivot($organizationId, ['is_active' => false]),
                fn () => $actor->organizations()->updateExistingPivot($organizationId, ['is_active' => true])],
            [fn () => $project->update(['is_archived' => true]), fn () => $project->update(['is_archived' => false])],
            [fn () => $journals[0]->update(['project_id' => $otherProject->id, 'contract_id' => null]),
                fn () => $journals[0]->update(['project_id' => $project->id, 'contract_id' => $contractId])],
        ];
        foreach ($mutations as [$revoke, $restore]) {
            $revoke();
            self::assertFalse($references->canReadReference($actor, $organizationId, $reference));
            $restore();
            self::assertTrue($references->canReadReference($actor, $organizationId, $reference));
        }
    }

    public function test_journal_reference_context_placement_does_not_narrow_organization_scope(): void
    {
        [$fixture, $project, $journals] = $this->fixture();
        $otherProject = $this->makeSecondJournalProjectAccessible($fixture, $journals[5]);
        $actor = $fixture->owner;
        $organizationId = (int) $fixture->organization->id;
        $references = app(AssistantCustomReportReference::class);
        $original = app(AssistantCustomReportBuilder::class)->build($this->plan(), $actor, $organizationId)['source_refs'][0];
        self::assertNull($original['_report_scope']['project_id']);
        $scope = $original['_report_scope'];
        $scope['context_project_id'] = (int) $project->id;
        $reference = $references->create($actor, $organizationId, $scope, $original['checked_fields'],
            $original['_report_ids'], $original['source_version'], $original['fetched_at']);
        self::assertSame((int) $project->id, $reference['project_id']);
        self::assertTrue($references->canReadReference($actor, $organizationId, $reference));
        $changed = $reference;
        $changed['_report_scope']['project_id'] = (int) $project->id;
        self::assertFalse($references->canReadReference($actor, $organizationId, $changed));
        $changed = $reference;
        $changed['project_id'] = (int) $otherProject->id;
        self::assertFalse($references->canReadReference($actor, $organizationId, $changed));
        $changed = $reference;
        $changed['_report_ids'] = [(string) $journals[5]->id];
        self::assertFalse($references->canReadReference($actor, $organizationId, $changed));
    }

    public function test_scoped_journal_query_rejects_wrong_frames_and_recovers_after_nested_discovery_failure(): void
    {
        [$fixture, $project, $journals] = $this->fixture();
        $actor = $fixture->owner;
        $organizationId = (int) $fixture->organization->id;
        $policy = app(AssistantDataAccessPolicy::class);
        self::assertNull($policy->constructionJournalReferenceQuery($actor, $organizationId, (int) $project->id));
        $plan = $this->plan();
        $plan['sections'][0]['project_id'] = (int) $project->id;
        $reference = app(AssistantCustomReportBuilder::class)->build($plan, $actor, $organizationId)['source_refs'][0];
        $realParticipants = app(\App\Services\Project\ProjectParticipantService::class);
        $failNext = false;
        $participants = \Mockery::mock(\App\Services\Project\ProjectParticipantService::class);
        $participants->shouldReceive('getHierarchiesWithActivity')->andReturnUsing(function (array $projects) use ($realParticipants, $policy, $actor, $organizationId, $project, &$failNext): array {
            self::assertNull($policy->constructionJournalReferenceQuery($actor, $organizationId, (int) $project->id));
            if ($failNext) {
                $failNext = false;
                throw new \LogicException('journal_discovery_test_stop');
            }

            return $realParticipants->getHierarchiesWithActivity($projects);
        });
        app()->instance(\App\Services\Project\ProjectParticipantService::class, $participants);
        try {
            $policy->withCurrentChecks($actor, $organizationId, function () use ($policy, $actor, $organizationId, $project, $fixture, $journals, $reference, &$failNext): void {
                self::assertNull($policy->constructionJournalReferenceQuery($actor, $organizationId, 0));
                self::assertNull($policy->constructionJournalReferenceQuery($actor, (int) $fixture->foreignOrganization->id, (int) $project->id));
                $failNext = true;
                self::assertFalse(app(AssistantCustomReportReference::class)->canReadReference($actor, $organizationId, $reference));
                self::assertFalse($failNext);
                $generic = $policy->entityQuery($actor, $organizationId, 'construction_journal');
                self::assertNotNull($generic);
                self::assertSame([$journals[0]->id, $journals[1]->id], $generic->orderBy('construction_journals.id')->pluck('construction_journals.id')->all());
                $scoped = $policy->constructionJournalReferenceQuery($actor, $organizationId, (int) $project->id);
                self::assertNotNull($scoped);
                self::assertSame(2, $scoped->count());
                self::assertTrue(app(AssistantCustomReportReference::class)->canReadReference($actor, $organizationId, $reference));
            }, fresh: true);
        } finally {
            app()->instance(\App\Services\Project\ProjectParticipantService::class, $realParticipants);
        }
        self::assertNull($policy->constructionJournalReferenceQuery($actor, $organizationId, (int) $project->id));
    }

    public function test_scoped_journal_reference_query_has_proven_snapshot_dependencies(): void
    {
        [$fixture, $project, $journals] = $this->fixture();
        $actor = $fixture->owner;
        $organizationId = (int) $fixture->organization->id;
        $policy = app(AssistantDataAccessPolicy::class);
        $proof = app(AssistantStatusSnapshotFootprint::class)->capture(function () use ($policy, $actor, $organizationId, $project): array {
            return $policy->withCurrentChecks($actor, $organizationId, function () use ($policy, $actor, $organizationId, $project): array {
                $query = $policy->constructionJournalReferenceQuery($actor, $organizationId, (int) $project->id);
                self::assertNotNull($query);

                return $query->orderBy('construction_journals.id')->pluck('construction_journals.id')->all();
            }, fresh: true);
        });
        self::assertSame([$journals[0]->id, $journals[1]->id], $proof['value']);
        self::assertIsArray($proof['relations'], $proof['reason'] ?? '');
        foreach (['public.construction_journals', 'public.projects', 'public.project_organization_hierarchy'] as $relation) {
            self::assertContains($relation, $proof['relations']);
        }
    }

    public function test_project_only_journal_permission_allows_scoped_report_and_rechecks_role_and_assignment(): void
    {
        [$fixture, $project] = $this->fixture();
        $org = (int) $fixture->organization->id;
        $actor = Model::withoutEvents(fn () => $fixture->addMember(['ai-assistant' => ['ai_assistant.chat'],
            'project-management' => ['projects.view']]));
        $policy = app(AssistantDataAccessPolicy::class);
        self::assertFalse($policy->canCurrentPermission($actor, $org, 'construction-journal.view'));
        $assignment = Model::withoutEvents(function () use ($actor, $project, $org) {
            $actor->assignedProjects()->attach($project->id, ['is_active' => true, 'role' => 'member']);

            return UserRoleAssignment::assignRole(user: $actor, roleSlug: 'project_manager',
                context: AuthorizationContext::getProjectContext((int) $project->id, $org));
        });
        self::assertFalse($policy->canCurrentPermission($actor, $org, 'construction-journal.view'));
        self::assertTrue($policy->canCurrentProjectPermission($actor, $org, (int) $project->id, 'construction-journal.view'));
        $native = app(AssistantProjectJournalReader::class)->read($actor, $org, ['project_id' => (int) $project->id]);
        self::assertSame(2, $native['data']['journal_total']);
        $plan = $this->plan();
        $plan['sections'][0]['project_id'] = (int) $project->id;
        $builder = app(AssistantCustomReportBuilder::class);
        $references = app(AssistantCustomReportReference::class);
        $report = $builder->build($plan, $actor, $org);
        self::assertSame(2, $report['sections'][0]['source_row_count']);
        self::assertTrue($references->canReadReference($actor, $org, $report['source_refs'][0]));
        try {
            $builder->build($this->plan(), $actor, $org);
            self::fail('Organization-wide report requires organization-level journal permission');
        } catch (AccessDeniedHttpException) {
        }
        $assignment->update(['is_active' => false]);
        self::assertFalse($references->canReadReference($actor, $org, $report['source_refs'][0]));
        $assignment->update(['is_active' => true]);
        self::assertTrue($references->canReadReference($actor, $org, $report['source_refs'][0]));
        $actor->assignedProjects()->updateExistingPivot($project->id, ['is_active' => false]);
        self::assertFalse($references->canReadReference($actor, $org, $report['source_refs'][0]));
        try {
            $builder->build($plan, $actor, $org);
            self::fail('Revoked project assignment must not produce an empty successful report');
        } catch (AccessDeniedHttpException) {
        }
    }

    public function test_full_report_matches_native_performer_visibility_without_financial_contract_access(): void
    {
        [$fixture, $project, $journals, $contract] = $this->fixture();
        Queue::fake();
        $actor = $fixture->owner;
        $organizationId = (int) $fixture->organization->id;
        $access = app(ConstructionJournalAccessService::class);
        $native = array_values(array_filter($journals, static fn (ConstructionJournal $journal): bool => $access->canRead($actor, $journal)));
        self::assertSame([$journals[0]->id, $journals[1]->id], array_column($native, 'id'));
        $policy = app(AssistantDataAccessPolicy::class);
        self::assertFalse($policy->canReadEntity($actor, $organizationId, 'contract', $contract->id));
        $report = app(AssistantCustomReportBuilder::class)->build($this->plan(), $actor, $organizationId);
        self::assertSame(array_column($native, 'id'), array_column($report['sections'][0]['rows'], 0));
        self::assertSame(2, $report['sections'][0]['source_row_count']);
        self::assertSame(['Активный', 'Активный'], array_column($report['sections'][0]['rows'], 2));
        $withContract = $this->plan();
        $withContract['sections'][0]['columns'][] = 'contract_id';
        $contractReport = app(AssistantCustomReportBuilder::class)->build($withContract, $actor, $organizationId);
        self::assertSame([
            [$journals[0]->id, 'Журнал 0', 'Активный', $contract->id],
            [$journals[1]->id, 'Журнал 1', 'Активный', null],
        ], $contractReport['sections'][0]['rows']);
        $encoded = json_encode($contractReport, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        foreach (['PRIVATE-JOURNAL-CONTRACT', 'SECRET-JOURNAL-COUNTERPARTY'] as $private) {
            self::assertStringNotContainsString($private, $encoded);
        }
        self::assertSame(['construction_journal'], array_unique(array_column(array_column($contractReport['source_refs'], '_report_scope'), 'entity_type')));
        $entry = Model::withoutEvents(fn () => ConstructionJournalEntry::query()->create([
            'journal_id' => $journals[1]->id, 'entry_date' => '2026-10-01', 'entry_number' => 1,
            'work_description' => 'Доступная запись исполнителя', 'status' => 'draft', 'created_by_user_id' => $actor->id,
        ]));
        self::assertTrue($policy->canReadEntity($actor, $organizationId, 'construction_journal_entry', $entry->id));
        $hidden = Model::withoutEvents(fn () => ConstructionJournalEntry::query()->create([
            'journal_id' => $journals[2]->id, 'entry_date' => '2026-10-01', 'entry_number' => 1,
            'work_description' => 'Скрытая запись соседнего исполнителя', 'status' => 'draft', 'created_by_user_id' => $actor->id,
        ]));
        self::assertFalse($policy->canReadEntity($actor, $organizationId, 'construction_journal_entry', $hidden->id));
        Queue::assertNothingPushed();
    }

    public function test_report_reference_rechecks_hierarchy_project_assignment_and_actor_membership(): void
    {
        [$fixture, $project, $journals] = $this->fixture();
        $actor = $fixture->owner;
        $organizationId = (int) $fixture->organization->id;
        $builder = app(AssistantCustomReportBuilder::class);
        $references = app(AssistantCustomReportReference::class);
        $report = $builder->build($this->plan(), $actor, $organizationId);
        self::assertSame(2, $report['sections'][0]['source_row_count']);
        self::assertCount(1, $report['source_refs']);
        $reference = $report['source_refs'][0];
        self::assertSame([(string) $journals[0]->id, (string) $journals[1]->id], $reference['_report_ids']);
        $nativeAccess = app(ConstructionJournalAccessService::class);
        self::assertTrue($nativeAccess->canRead($actor, $journals[1]));
        self::assertTrue($references->canReadReference($actor, $organizationId, $reference));
        self::assertSame(1, DB::table('project_organization_hierarchy')->where('project_id', $project->id)
            ->where('organization_id', $journals[1]->performing_organization_id)->update(['parent_organization_id' => $project->organization_id]));
        self::assertFalse($nativeAccess->canRead($actor, $journals[1]));
        self::assertFalse($references->canReadReference($actor, $organizationId, $reference));
        $currentReport = $builder->build($this->plan(), $actor, $organizationId);
        self::assertSame(1, $currentReport['sections'][0]['source_row_count']);
        $current = $currentReport['source_refs'][0];
        self::assertSame([(string) $journals[0]->id], $current['_report_ids']);
        self::assertTrue($references->canReadReference($actor, $organizationId, $current));
        $actor->assignedProjects()->updateExistingPivot($project->id, ['is_active' => false]);
        self::assertFalse($references->canReadReference($actor, $organizationId, $current));
        $actor->assignedProjects()->updateExistingPivot($project->id, ['is_active' => true]);
        $project->organizations()->updateExistingPivot($organizationId, ['is_active' => false]);
        self::assertFalse($references->canReadReference($actor, $organizationId, $current));
        $project->organizations()->updateExistingPivot($organizationId, ['is_active' => true]);
        $fresh = $builder->build($this->plan(), $actor, $organizationId)['source_refs'][0];
        $actor->organizations()->updateExistingPivot($organizationId, ['is_active' => false]);
        self::assertFalse($references->canReadReference($actor, $organizationId, $fresh));
        $actor->organizations()->updateExistingPivot($organizationId, ['is_active' => true]);
        $fixture->ownerAssignment->update(['is_active' => false]);
        self::assertFalse($references->canReadReference($actor, $organizationId, $fresh));
    }

    public function test_report_stops_reading_when_the_shared_project_is_archived(): void
    {
        [$fixture, $project, $journals] = $this->fixture();
        $actor = $fixture->owner;
        $organizationId = (int) $fixture->organization->id;
        $access = app(ConstructionJournalAccessService::class);
        $policy = app(AssistantDataAccessPolicy::class);
        self::assertTrue($access->canRead($actor, $journals[0]));
        self::assertTrue($policy->canReadEntity($actor, $organizationId, 'construction_journal', $journals[0]->id));
        $project->update(['is_archived' => true]);
        self::assertFalse($access->canRead($actor, $journals[0]));
        self::assertFalse($policy->canReadEntity($actor, $organizationId, 'construction_journal', $journals[0]->id));
        $report = app(AssistantCustomReportBuilder::class)->build($this->plan(), $actor, $organizationId);
        self::assertSame(0, $report['sections'][0]['source_row_count']);
    }

    public function test_missing_project_authorization_context_refuses_a_partial_report_without_creating_context_or_file(): void
    {
        [$fixture, $project, $journals] = $this->fixture();
        $actor = $fixture->owner;
        $organizationId = (int) $fixture->organization->id;
        $builder = app(AssistantCustomReportBuilder::class);
        $previous = $builder->build($this->plan(), $actor, $organizationId);
        self::assertSame(2, $previous['sections'][0]['source_row_count']);
        self::assertCount(2, $previous['source_refs'][0]['_report_ids']);
        $otherProject = $journals[5]->project;
        $otherProject->organizations()->attach($organizationId, ['is_active' => true, 'role' => 'child_contractor',
            'role_new' => ProjectOrganizationRole::SUBCONTRACTOR->value]);
        $actor->assignedProjects()->attach($otherProject->id, ['is_active' => true, 'role' => 'member']);
        self::assertTrue(app(AssistantCustomReportReference::class)->canReadReference($actor, $organizationId, $previous['source_refs'][0]));
        try {
            $builder->build($this->plan(), $actor, $organizationId);
            self::fail('A new report must verify every candidate project scope');
        } catch (ReportContractException $exception) {
            self::assertSame(ReportErrorCode::REPORT_SOURCE_UNAVAILABLE, $exception->errorCode);
        }
        AuthorizationContext::getProjectContext((int) $otherProject->id, $organizationId);
        self::assertSame(3, $builder->build($this->plan(), $actor, $organizationId)['sections'][0]['source_row_count']);
        $context = AuthorizationContext::findProjectContext((int) $project->id, $organizationId);
        self::assertNotNull($context);
        $context->delete();
        $contextCount = AuthorizationContext::query()->count();
        try {
            $builder->build($this->plan(), $actor, $organizationId);
            self::fail('An unverified project scope must not become a partial successful report');
        } catch (ReportContractException $exception) {
            self::assertSame(ReportErrorCode::REPORT_SOURCE_UNAVAILABLE, $exception->errorCode);
        }
        $storage = \Mockery::mock(AssistantGeneratedReportStorage::class);
        $storage->shouldNotReceive('store');
        $tool = new GenerateCustomReportTool($builder, app(AssistantCustomReportRenderer::class), $storage, app(AIPermissionChecker::class));
        self::assertSame(['status' => 'unavailable', 'error_code' => ReportErrorCode::REPORT_SOURCE_UNAVAILABLE->value,
            'message' => trans_message('reports.errors.report_source_unavailable')], $tool->execute($this->plan(), $actor, $fixture->organization));
        self::assertSame($contextCount, AuthorizationContext::query()->count());
        self::assertNull(AuthorizationContext::findProjectContext((int) $project->id, $organizationId));
    }

    public function test_real_journal_performer_query_has_proven_snapshot_dependencies(): void
    {
        [$fixture, , $journals] = $this->fixture();
        $actor = $fixture->owner;
        $organizationId = (int) $fixture->organization->id;
        $policy = app(AssistantDataAccessPolicy::class);
        $sql = null;
        $proof = app(AssistantStatusSnapshotFootprint::class)->capture(function () use ($policy, $actor, $organizationId, &$sql): array {
            return $policy->withCurrentChecks($actor, $organizationId, function () use ($policy, $actor, $organizationId, &$sql): array {
                $query = $policy->entityQuery($actor, $organizationId, 'construction_journal');
                self::assertNotNull($query);
                $sql = $query->toSql();

                return $query->orderBy('construction_journals.id')->pluck('construction_journals.id')->all();
            }, fresh: true);
        });
        self::assertIsString($sql);
        self::assertStringContainsString('jsonb_to_recordset(', $sql);
        self::assertSame([$journals[0]->id, $journals[1]->id], $proof['value']);
        self::assertIsArray($proof['relations'], $proof['reason'] ?? '');
        foreach (['public.construction_journals', 'public.projects', 'public.project_organization_hierarchy'] as $relation) {
            self::assertContains($relation, $proof['relations']);
        }
    }

    private function makeSecondJournalProjectAccessible(AssistantRealAuthorizationFixture $fixture, ConstructionJournal $journal): Project
    {
        $organizationId = (int) $fixture->organization->id;
        $project = $journal->project;
        self::assertInstanceOf(Project::class, $project);
        $project->organizations()->attach($organizationId, ['is_active' => true, 'role' => 'child_contractor',
            'role_new' => ProjectOrganizationRole::SUBCONTRACTOR->value]);
        $fixture->owner->assignedProjects()->attach($project->id, ['is_active' => true, 'role' => 'member']);
        AuthorizationContext::getProjectContext((int) $project->id, $organizationId);
        DB::table('project_organization_hierarchy')->insert([
            ['project_id' => $project->id, 'organization_id' => $project->organization_id, 'parent_organization_id' => null],
            ['project_id' => $project->id, 'organization_id' => $organizationId, 'parent_organization_id' => $project->organization_id],
        ]);

        return $project;
    }

    private function plan(): array
    {
        return ['title' => 'Полный реестр журналов', 'format' => 'xlsx', 'sections' => [[
            'title' => 'Журналы', 'domain' => 'core_journals', 'entity_type' => 'construction_journal', 'project_id' => null,
            'columns' => ['id', 'name', 'status'], 'filters' => [['field' => 'status', 'operator' => 'eq', 'value' => 'active']],
            'group_by' => [], 'metrics' => [], 'order_by' => [['field' => 'id', 'direction' => 'asc']],
        ]]];
    }

    private function fixture(): array
    {
        Queue::fake();
        config(['cache.default' => 'array']);
        $fixture = Model::withoutEvents(fn () => AssistantRealAuthorizationFixture::create(array_column(app(PackageCatalogService::class)->allPackages(), 'slug')));
        $actorOrg = $fixture->organization;
        $owner = $fixture->foreignOrganization;
        $child = Organization::factory()->create(['is_active' => true]);
        $sibling = Organization::factory()->create(['is_active' => true]);
        $inactive = Organization::factory()->create(['is_active' => false]);
        $project = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $owner->id, 'is_archived' => false]));
        $fixture->owner->organizations()->updateExistingPivot($actorOrg->id, ['project_access_mode' => 'assigned_projects']);
        $fixture->owner->assignedProjects()->attach($project->id, ['is_active' => true, 'role' => 'member']);
        foreach ([$actorOrg, $child, $sibling, $inactive] as $organization) {
            $project->organizations()->attach($organization->id, ['is_active' => true, 'role' => 'child_contractor', 'role_new' => ProjectOrganizationRole::SUBCONTRACTOR->value]);
        }
        AuthorizationContext::getProjectContext((int) $project->id, (int) $actorOrg->id);
        DB::table('project_organization_hierarchy')->insert([
            ['project_id' => $project->id, 'organization_id' => $owner->id, 'parent_organization_id' => null],
            ['project_id' => $project->id, 'organization_id' => $actorOrg->id, 'parent_organization_id' => $owner->id],
            ['project_id' => $project->id, 'organization_id' => $child->id, 'parent_organization_id' => $actorOrg->id],
            ['project_id' => $project->id, 'organization_id' => $sibling->id, 'parent_organization_id' => $owner->id],
            ['project_id' => $project->id, 'organization_id' => $inactive->id, 'parent_organization_id' => $actorOrg->id],
        ]);
        $contract = Model::withoutEvents(fn () => Contract::query()->create([
            'organization_id' => $owner->id, 'project_id' => $project->id,
            'contractor_id' => Contractor::query()->create(['organization_id' => $owner->id, 'name' => 'SECRET-JOURNAL-COUNTERPARTY'])->id,
            'number' => 'PRIVATE-JOURNAL-CONTRACT', 'date' => '2026-10-01', 'subject' => 'Работы', 'total_amount' => 1, 'status' => 'active',
        ]));
        $journals = [];
        foreach ([$actorOrg, $child, $sibling, $inactive, $owner] as $index => $performer) {
            $journals[] = Model::withoutEvents(fn () => ConstructionJournal::query()->create([
                'organization_id' => $owner->id, 'performing_organization_id' => $performer->id, 'project_id' => $project->id,
                'contract_id' => $index === 0 ? $contract->id : null, 'name' => 'Журнал '.$index, 'journal_number' => 'SHARED-'.$index,
                'start_date' => '2026-10-01', 'status' => 'active', 'created_by_user_id' => $fixture->owner->id,
            ]));
        }
        $foreignProject = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $owner->id, 'is_archived' => false]));
        $journals[] = Model::withoutEvents(fn () => ConstructionJournal::query()->create([
            'organization_id' => $owner->id, 'performing_organization_id' => $actorOrg->id, 'project_id' => $foreignProject->id,
            'name' => 'Чужой проект', 'journal_number' => 'HIDDEN-PROJECT', 'start_date' => '2026-10-01',
            'status' => 'active', 'created_by_user_id' => $fixture->owner->id,
        ]));

        return [$fixture, $project, $journals, $contract];
    }
}
