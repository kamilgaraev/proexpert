<?php

declare(strict_types=1);

namespace Tests\Feature\DesignManagement;

use App\BusinessModules\Features\DesignManagement\Models\DesignArtifactVersion;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackage;
use App\BusinessModules\Features\DesignManagement\Models\DesignSourceLink;
use App\BusinessModules\Features\DesignManagement\Services\DesignSourceLinkService;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\CompletedWork;
use App\Models\ConstructionJournal;
use App\Models\ConstructionJournalEntry;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Modules\Core\AccessController;
use App\Services\Project\ProjectParticipantService;
use DomainException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\EnablesImmutableAuditWriter;
use Tests\TestCase;

final class JournalDesignSourceLinksAccessTest extends TestCase
{
    use EnablesImmutableAuditWriter;

    private ?int $deniedViewer = null;

    private array $inactiveBudgetOrganizations = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableImmutableAuditWriter();
        $this->mock(AuthorizationService::class, function ($mock): void {
            $mock->shouldReceive('can')->andReturnUsing(fn (User $actor, string $permission): bool =>
                ! ($actor->id === $this->deniedViewer && str_starts_with($permission, 'construction-journal.')));
            $mock->shouldReceive('hasRole')->andReturnFalse();
        });
        $this->mock(AccessController::class, function ($mock): void {
            $mock->shouldReceive('hasModuleAccess')->andReturnUsing(fn (int $organizationId, string $module): bool =>
                $module !== 'budget-estimates' || ! in_array($organizationId, $this->inactiveBudgetOrganizations, true));
        });
    }

    public function test_ancestors_and_performer_read_canonical_links_without_gaining_source_access(): void
    {
        [$project, $entry, $users, $link] = $this->fixture();
        $service = app(DesignSourceLinkService::class);
        $before = $link->fresh()->getAttributes();
        foreach ([0, 1, 2] as $index) {
            $viewer = $users[$index];
            $links = $service->linksForTarget($viewer, (int) $viewer->current_organization_id, 'construction_journal_entry', $entry->id);
            self::assertCount(1, $links);
            self::assertSame($link->id, $links[0]['id']);
            self::assertSame($entry->id, $links[0]['target']['id']);
            if ($index === 1) {
                self::assertTrue($links[0]['source']['available']);
            } else {
                self::assertFalse($links[0]['source']['available']);
                self::assertNull($links[0]['source_version_id']);
                self::assertNull($links[0]['source_sheet_id']);
                self::assertNull($links[0]['source_element_id']);
                self::assertArrayNotHasKey('download_url', $links[0]['source']);
                self::assertArrayNotHasKey('filename', $links[0]['source']);
                self::assertArrayNotHasKey('element', $links[0]['source']);
            }
        }
        self::assertSame($before, $link->fresh()->getAttributes());
        self::assertSame($project->organization_id, $entry->journal->organization_id);
    }

    public function test_a_shared_reader_can_read_an_empty_link_list_and_foreign_scoped_rows_are_excluded(): void
    {
        [$project, $entry, $users, $link] = $this->fixture();
        $viewer = $users[0];
        $link->delete();
        self::assertSame([], app(DesignSourceLinkService::class)->linksForTarget($viewer, (int) $viewer->current_organization_id, 'construction_journal_entry', $entry->id));
        DesignSourceLink::query()->create([
            'organization_id' => $viewer->current_organization_id, 'project_id' => $project->id,
            'source_version_id' => $link->source_version_id, 'target_type' => 'construction_journal_entry', 'target_id' => $entry->id,
            'target_snapshot' => ['label' => 'Чужая область'], 'created_by' => $viewer->id,
        ]);
        self::assertSame([], app(DesignSourceLinkService::class)->linksForTarget($viewer, (int) $viewer->current_organization_id, 'construction_journal_entry', $entry->id));
    }

    #[DataProvider('deniedCases')]
    public function test_journal_link_read_rechecks_branch_membership_project_and_permission(string $case): void
    {
        [$project, $entry, $users] = $this->fixture();
        $viewer = $users[0];
        $organizationId = (int) $viewer->current_organization_id;
        if ($case === 'sibling') {
            $viewer = $users[3];
            $organizationId = (int) $viewer->current_organization_id;
        } elseif ($case === 'unassigned_employee') {
            $project->users()->updateExistingPivot($viewer->id, ['is_active' => false]);
        } elseif ($case === 'inactive_membership') {
            $viewer->organizations()->updateExistingPivot($organizationId, ['is_active' => false]);
        } elseif ($case === 'inactive_participant') {
            $project->organizations()->updateExistingPivot($organizationId, ['is_active' => false]);
        } elseif ($case === 'inactive_organization') {
            Organization::query()->findOrFail($organizationId)->update(['is_active' => false]);
        } elseif ($case === 'no_permission') {
            $this->deniedViewer = $viewer->id;
        } elseif ($case === 'foreign_project') {
            $otherProject = Project::factory()->create(['organization_id' => $project->organization_id]);
            $entry->journal->update(['project_id' => $otherProject->id]);
        } elseif ($case === 'organization_argument_mismatch') {
            $organizationId = (int) $users[3]->current_organization_id;
        }
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(trans_message('design_links.errors.target_out_of_scope'));
        app(DesignSourceLinkService::class)->linksForTarget($viewer, $organizationId, 'construction_journal_entry', $entry->id);
    }

    public static function deniedCases(): array
    {
        return array_map(static fn (string $case): array => [$case], [
            'sibling', 'unassigned_employee', 'inactive_membership', 'inactive_participant',
            'inactive_organization', 'no_permission', 'foreign_project', 'organization_argument_mismatch',
        ]);
    }

    public function test_shared_read_does_not_open_source_context_or_link_mutations(): void
    {
        [$project, $entry, $users, $link] = $this->fixture();
        $viewer = $users[0];
        $organizationId = (int) $viewer->current_organization_id;
        $source = $this->source($project, $viewer);
        $service = app(DesignSourceLinkService::class);
        $before = $link->fresh()->getAttributes();
        foreach (['context', 'create', 'delete'] as $operation) {
            try {
                match ($operation) {
                    'context' => $service->sourceContext($viewer, $organizationId, $link->id),
                    'create' => $service->create($viewer, $organizationId, [
                        'source_version_id' => $source->id, 'target_type' => 'construction_journal_entry', 'target_id' => $entry->id,
                    ]),
                    'delete' => $service->delete($viewer, $organizationId, $link->id, 'Попытка изменения', 1),
                };
                self::fail('Shared journal read granted '.$operation);
            } catch (DomainException) {
                self::assertSame($before, $link->fresh()->getAttributes());
                self::assertSame(1, DesignSourceLink::query()->count());
            }
        }
    }

    public function test_other_target_types_keep_strict_organization_scope(): void
    {
        [$project, , $users] = $this->fixture();
        $work = CompletedWork::query()->create([
            'organization_id' => $project->organization_id, 'project_id' => $project->id, 'user_id' => $users[1]->id,
            'quantity' => 3, 'completed_quantity' => 3, 'price' => 500, 'total_amount' => 1500,
            'completion_date' => '2026-10-11', 'description' => 'Объём', 'status' => 'confirmed',
        ]);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(trans_message('design_links.errors.target_out_of_scope'));
        app(DesignSourceLinkService::class)->linksForTarget($users[0], (int) $users[0]->current_organization_id, 'completed_work', $work->id);
    }

    public function test_shared_link_read_keeps_the_journal_module_gate(): void
    {
        [, $entry, $users] = $this->fixture();
        $this->inactiveBudgetOrganizations = [(int) $users[0]->current_organization_id];
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(trans_message('design_links.target_module_inactive'));
        app(DesignSourceLinkService::class)->linksForTarget($users[0], (int) $users[0]->current_organization_id, 'construction_journal_entry', $entry->id);
    }

    private function fixture(): array
    {
        $users = [];
        for ($index = 0; $index < 4; $index++) {
            $organization = Organization::factory()->create(['is_active' => true]);
            $user = User::factory()->create(['current_organization_id' => $organization->id]);
            $user->organizations()->attach($organization->id, ['is_active' => true, 'is_owner' => false, 'project_access_mode' => 'assigned_projects']);
            $users[] = $user;
        }
        $project = Project::factory()->create(['organization_id' => $users[1]->current_organization_id, 'is_archived' => false]);
        foreach ([0 => 'general_contractor', 2 => 'subcontractor', 3 => 'contractor'] as $index => $role) {
            $project->organizations()->attach($users[$index]->current_organization_id, ['is_active' => true, 'role' => 'contractor', 'role_new' => $role]);
        }
        foreach ($users as $user) {
            $project->users()->attach($user->id, ['is_active' => true]);
        }
        app(ProjectParticipantService::class)->saveHierarchy($project, (int) $users[0]->current_organization_id, [
            ['organization_id' => (int) $users[1]->current_organization_id, 'parent_organization_id' => (int) $users[0]->current_organization_id],
            ['organization_id' => (int) $users[2]->current_organization_id, 'parent_organization_id' => (int) $users[1]->current_organization_id],
            ['organization_id' => (int) $users[3]->current_organization_id, 'parent_organization_id' => (int) $users[0]->current_organization_id],
        ]);
        $journal = ConstructionJournal::query()->create([
            'organization_id' => $project->organization_id, 'performing_organization_id' => $users[2]->current_organization_id,
            'project_id' => $project->id, 'name' => 'Журнал субподрядчика', 'journal_number' => '1',
            'start_date' => '2026-10-11', 'status' => 'active', 'created_by_user_id' => $users[2]->id,
        ]);
        $entry = ConstructionJournalEntry::query()->create([
            'journal_id' => $journal->id, 'entry_date' => '2026-10-11', 'entry_number' => 1,
            'work_description' => 'Бетонирование', 'status' => 'approved', 'created_by_user_id' => $users[2]->id,
        ]);
        $version = $this->source($project, $users[1]);
        $link = DesignSourceLink::query()->create([
            'organization_id' => $project->organization_id, 'project_id' => $project->id,
            'source_version_id' => $version->id, 'target_type' => 'construction_journal_entry', 'target_id' => $entry->id,
            'target_snapshot' => ['label' => 'Бетонирование'], 'source_snapshot' => ['title' => 'АР. План', 'revision' => 'R01',
                'element' => ['express_id' => 17, 'global_id' => 'private-ifc-id', 'name' => 'Скрытый элемент', 'category' => 'IfcWall'],
            ],
            'created_by' => $users[1]->id,
        ]);

        return [$project, $entry, $users, $link];
    }

    private function source(Project $project, User $user): DesignArtifactVersion
    {
        $package = DesignPackage::query()->create([
            'organization_id' => $user->current_organization_id, 'project_id' => $project->id,
            'created_by' => $user->id, 'updated_by' => $user->id, 'title' => 'АР. План', 'status' => 'draft', 'metadata' => [],
        ]);
        $artifact = $package->artifacts()->create([
            'organization_id' => $user->current_organization_id, 'project_id' => $project->id,
            'created_by' => $user->id, 'updated_by' => $user->id, 'artifact_type' => 'model',
            'title' => 'АР. План', 'status' => 'active', 'metadata' => [],
        ]);

        return $artifact->versions()->create([
            'organization_id' => $user->current_organization_id, 'project_id' => $project->id,
            'created_by' => $user->id, 'updated_by' => $user->id, 'uploaded_by' => $user->id,
            'title' => 'АР. План', 'version_number' => '1', 'source_format' => 'ifc',
            'source_file_path' => 'org-'.$user->current_organization_id.'/pir/model.ifc', 'source_original_name' => 'model.ifc',
            'source_mime_type' => 'application/octet-stream', 'source_size_bytes' => 1000,
            'status' => 'uploaded', 'is_current' => true, 'metadata' => [],
        ]);
    }
}
