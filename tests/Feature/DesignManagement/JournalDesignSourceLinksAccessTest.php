<?php

declare(strict_types=1);

namespace Tests\Feature\DesignManagement;

use App\BusinessModules\Features\DesignManagement\Models\DesignArtifactVersion;
use App\BusinessModules\Features\DesignManagement\Models\DesignDocumentSheet;
use App\BusinessModules\Features\DesignManagement\Models\DesignIfcModelElement;
use App\BusinessModules\Features\DesignManagement\Models\DesignImpactReview;
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
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\EnablesImmutableAuditWriter;
use Tests\TestCase;

final class JournalDesignSourceLinksAccessTest extends TestCase
{
    use EnablesImmutableAuditWriter;

    private ?int $deniedViewer = null;

    private array $inactiveBudgetOrganizations = [];

    private ?string $deniedSourcePermission = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableImmutableAuditWriter();
        $this->mock(AuthorizationService::class, function ($mock): void {
            $mock->shouldReceive('can')->andReturnUsing(fn (User $actor, string $permission): bool =>
                $permission !== $this->deniedSourcePermission
                && ! ($actor->id === $this->deniedViewer && str_starts_with($permission, 'construction-journal.')));
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

    #[DataProvider('sourcePermissions')]
    public function test_create_denies_typed_source_before_element_or_sheet_read_and_allows_authorized_source(string $format, string $permission): void
    {
        [$project, $entry, $users, $link] = $this->fixture();
        $source = DesignArtifactVersion::query()->findOrFail($link->source_version_id);
        $source->update(['file_format' => $format]);
        $selection = $this->sourceSelection($source, $format);
        $actor = $users[1];
        $organizationId = (int) $actor->current_organization_id;
        $data = ['source_version_id' => $source->id, 'target_type' => 'construction_journal_entry', 'target_id' => $entry->id] + $selection;
        $this->deniedSourcePermission = $permission;
        $reads = [];
        DB::listen(static function (QueryExecuted $query) use (&$reads): void {
            if (str_contains($query->sql, 'design_ifc_model_elements') || str_contains($query->sql, 'design_document_sheets')) {
                $reads[] = $query->sql;
            }
        });
        $service = app(DesignSourceLinkService::class);
        try {
            $service->create($actor, $organizationId, $data);
            self::fail('Creating a source snapshot requires its typed read permission.');
        } catch (DomainException $exception) {
            self::assertSame(trans_message('design_links.errors.forbidden'), $exception->getMessage());
        }
        self::assertSame([], $reads);
        self::assertSame(1, DesignSourceLink::query()->count());
        $this->deniedSourcePermission = null;
        $created = $service->create($actor, $organizationId, $data);
        self::assertTrue($created['source']['available']);
        self::assertSame(2, DesignSourceLink::query()->count());
        $context = $service->sourceContext($actor, $organizationId, $created['id']);
        self::assertSame($source->id, $context['version_id']);
        self::assertSame($format === 'ifc' ? 'model' : 'document', $context['kind']);
        self::assertSame($format === 'ifc' ? 'private-new-id' : 'План листа', $format === 'ifc' ? $context['element']['global_id'] : $context['sheet']['sheet_title']);
    }

    #[DataProvider('sourcePermissions')]
    public function test_move_to_new_denies_typed_source_without_changing_history_or_reading_selection(string $format, string $permission): void
    {
        [, $entry, $users, $link] = $this->fixture();
        $source = DesignArtifactVersion::query()->findOrFail($link->source_version_id);
        $next = $source->replicate();
        $next->forceFill(['version_number' => '2', 'file_format' => $format, 'is_current' => false])->save();
        $selection = $this->sourceSelection($next, $format);
        $review = DesignImpactReview::query()->create([
            'organization_id' => $link->organization_id, 'project_id' => $link->project_id,
            'link_id' => $link->id, 'previous_version_id' => $source->id, 'new_version_id' => $next->id, 'status' => 'pending',
        ]);
        $this->deniedSourcePermission = $permission;
        $before = $link->fresh()->getAttributes();
        $reads = [];
        DB::listen(static function (QueryExecuted $query) use (&$reads): void {
            if (str_contains($query->sql, 'design_ifc_model_elements') || str_contains($query->sql, 'design_document_sheets')) {
                $reads[] = $query->sql;
            }
        });
        $service = app(DesignSourceLinkService::class);
        try {
            $service->decideReview($users[1], (int) $link->organization_id, $review->id, 'move_to_new', 'Новая редакция', 1,
                $selection['source_sheet_id'] ?? null, $selection['source_element_id'] ?? null);
            self::fail('Moving to a new source requires its typed read permission.');
        } catch (DomainException $exception) {
            self::assertSame(trans_message('design_links.errors.forbidden'), $exception->getMessage());
        }
        self::assertSame([], $reads);
        self::assertSame($before, $link->fresh()->getAttributes());
        self::assertSame('pending', $review->fresh()->status);
        self::assertSame(1, DesignSourceLink::query()->count());
        if ($format === 'pdf') {
            try {
                $service->replacementSheets($users[1], (int) $link->organization_id, $review->id, 1);
                self::fail('Replacement sheet reads require document permission.');
            } catch (DomainException $exception) {
                self::assertSame(trans_message('design_links.errors.forbidden'), $exception->getMessage());
            }
            self::assertSame([], $reads);
        }
        $this->deniedSourcePermission = null;
        $result = $service->decideReview($users[1], (int) $link->organization_id, $review->id, 'move_to_new', 'Новая редакция', 1,
            $selection['source_sheet_id'] ?? null, $selection['source_element_id'] ?? null);
        self::assertSame('move_to_new', $result['decision']);
        self::assertSame('replaced', $link->fresh()->status);
        self::assertSame($before['source_snapshot'], $link->fresh()->getAttributes()['source_snapshot']);
        self::assertSame(2, DesignSourceLink::query()->count());
    }

    public function test_owner_without_typed_permission_retains_frozen_history_and_can_keep_old_revision(): void
    {
        [, $entry, $users, $link] = $this->fixture();
        $source = DesignArtifactVersion::query()->findOrFail($link->source_version_id);
        $source->update(['title' => 'Текущее название изменено']);
        $next = $source->replicate();
        $next->forceFill(['version_number' => '2', 'is_current' => false])->save();
        $review = DesignImpactReview::query()->create([
            'organization_id' => $link->organization_id, 'project_id' => $link->project_id,
            'link_id' => $link->id, 'previous_version_id' => $source->id, 'new_version_id' => $next->id, 'status' => 'pending',
        ]);
        $this->deniedSourcePermission = 'design-management.models.view';
        $service = app(DesignSourceLinkService::class);
        foreach ([$users[0], $users[1], $users[2]] as $actor) {
            $history = $service->linksForTarget($actor, (int) $actor->current_organization_id, 'construction_journal_entry', $entry->id);
            self::assertCount(1, $history);
            self::assertSame(['title' => 'АР. План', 'revision' => 'R01', 'available' => false], $history[0]['source']);
            self::assertSame('Бетонирование', $history[0]['target']['label']);
            self::assertNull($history[0]['source_version_id']);
        }
        $result = $service->decideReview($users[1], (int) $link->organization_id, $review->id, 'keep_old', 'Старый источник актуален', 1);
        self::assertSame('keep_old', $result['decision']);
        self::assertSame('active', $link->fresh()->status);
        self::assertSame('АР. План', $link->fresh()->source_snapshot['title']);
    }

    public function test_edit_and_typed_source_permission_can_create_without_generic_view(): void
    {
        [, $entry, $users, $link] = $this->fixture();
        $source = DesignArtifactVersion::query()->findOrFail($link->source_version_id);
        $selection = $this->sourceSelection($source, 'ifc');
        $this->deniedSourcePermission = 'design-management.view';
        $created = app(DesignSourceLinkService::class)->create($users[1], (int) $link->organization_id, [
            'source_version_id' => $source->id, 'target_type' => 'construction_journal_entry', 'target_id' => $entry->id,
        ] + $selection);
        self::assertSame(2, DesignSourceLink::query()->count());
        self::assertFalse($created['source']['available']);
        self::assertNull($created['source_version_id']);
        self::assertSame('private-new-id', DesignSourceLink::query()->findOrFail($created['id'])->source_snapshot['element']['global_id']);
    }

    public static function sourcePermissions(): array
    {
        return [['ifc', 'design-management.models.view'], ['pdf', 'design-management.documents.view']];
    }

    private function sourceSelection(DesignArtifactVersion $source, string $format): array
    {
        if ($format === 'ifc') {
            DesignIfcModelElement::query()->create([
                'organization_id' => $source->organization_id, 'project_id' => $source->project_id,
                'version_id' => $source->id, 'express_id' => 17, 'global_id' => 'private-new-id', 'name' => 'Закрытый элемент', 'category' => 'IfcWall',
            ]);

            return ['source_element_id' => 17];
        }
        $sheet = DesignDocumentSheet::query()->create([
            'organization_id' => $source->organization_id, 'project_id' => $source->project_id,
            'package_id' => $source->artifact->package_id, 'artifact_id' => $source->artifact_id,
            'version_id' => $source->id, 'sheet_number' => '1', 'sheet_title' => 'План листа',
        ]);

        return ['source_sheet_id' => $sheet->id];
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
