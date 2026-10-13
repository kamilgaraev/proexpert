<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocument;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentSet;
use App\BusinessModules\Features\ExecutiveDocumentation\Services\ExecutiveDocumentInput;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\CompletedWork;
use App\Models\ConstructionJournal;
use App\Models\ConstructionJournalEntry;
use App\Models\Project;
use App\Models\User;
use App\Modules\Core\AccessController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class ExecutiveDocumentComposeActHttpTest extends TestCase
{
    use RefreshDatabase;

    public function test_compose_preserves_cleared_optional_volume_and_generates_a_number(): void
    {
        [$context, $set, $payload] = $this->fixture();
        $payload['profile_data']['actual_volume'] = '';
        $response = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/executive-documentation/sets/{$set->id}/compose-act", $payload)
            ->assertCreated()->assertJsonPath('data.profile_data.actual_volume', null);
        $document = ExecutiveDocument::query()->findOrFail($response->json('data.id'));
        self::assertNull($document->profile_data['actual_volume']);
        self::assertNotEmpty($document->profile_data['act_number']);
        self::assertSame('2026-10-02', $document->document_date->toDateString());
        self::assertSame('generated', $document->metadata['capture_mode']);
        $snapshot = $document->versions->first()->metadata['print_snapshot'];
        self::assertArrayHasKey('actual_volume', $snapshot['profile_data']);
        self::assertNull($snapshot['profile_data']['actual_volume']);
        self::assertSame($document->profile_data['act_number'], $snapshot['profile_data']['act_number']);
        self::assertArrayNotHasKey('journal_entry_number', $snapshot['profile_data']);
        self::assertSame($payload['journal_entry_id'], $document->journal_entry_id);
    }

    public function test_omitted_compose_fields_and_legacy_upload_blanks_still_receive_defaults(): void
    {
        [$context, $set, $payload] = $this->fixture();
        $payload['profile_data']['actual_volume'] = '';
        $payload['metadata'] = ['executive_act_composition' => ['explicit_profile_fields' => ['actual_volume']]];
        $legacy = app(ExecutiveDocumentInput::class)->normalize($payload, $set);
        self::assertNotEmpty($legacy['profile_data']['actual_volume']);
        unset($payload['profile_data']['actual_volume'], $payload['metadata']);

        $response = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/executive-documentation/sets/{$set->id}/compose-act", $payload)->assertCreated();
        self::assertNotEmpty($response->json('data.profile_data.actual_volume'));
        self::assertNotEmpty($response->json('data.profile_data.act_number'));
    }

    public function test_unknown_client_fields_and_explicitly_cleared_required_fields_are_rejected(): void
    {
        [$context, $set, $payload] = $this->fixture();
        $endpoint = "/api/v1/admin/executive-documentation/sets/{$set->id}/compose-act";
        $unknown = $payload;
        $unknown['profile_data']['unexpected'] = 'Не относится к форме';
        $this->withHeaders($context->authHeaders())->postJson($endpoint, $unknown)
            ->assertUnprocessable()->assertJsonValidationErrors('profile_data');
        $payload['profile_data']['presented_works'] = '';
        $this->withHeaders($context->authHeaders())->postJson($endpoint, $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('profile_data.presented_works');
        self::assertSame(2, $set->documents()->count());
    }

    private function fixture(): array
    {
        Storage::fake('s3');
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $set = ExecutiveDocumentSet::query()->create([
            'organization_id' => $context->organization->id, 'project_id' => $project->id,
            'created_by' => $context->user->id, 'set_number' => 'ED-TEST-001', 'title' => 'Комплект', 'status' => 'draft',
        ]);
        $journal = ConstructionJournal::query()->create([
            'organization_id' => $context->organization->id, 'project_id' => $project->id,
            'name' => 'Общий журнал', 'journal_number' => 'ОЖР-1', 'start_date' => '2026-10-01',
            'status' => 'active', 'created_by_user_id' => $context->user->id,
        ]);
        $entry = ConstructionJournalEntry::query()->create([
            'journal_id' => $journal->id, 'entry_date' => '2026-10-02', 'entry_number' => 1,
            'work_description' => 'Подготовка основания', 'status' => 'approved',
            'created_by_user_id' => $context->user->id,
        ]);
        $work = CompletedWork::query()->create([
            'organization_id' => $context->organization->id, 'project_id' => $project->id,
            'journal_entry_id' => $entry->id, 'user_id' => $context->user->id,
            'quantity' => 100, 'completion_date' => '2026-10-02', 'status' => 'confirmed',
        ]);
        $relations = [['relation_type' => 'journal_entry', 'target_type' => 'journal_entry', 'target_id' => $entry->id]];
        foreach (['quality_documents' => 'incoming_control_document', 'executive_schemes' => 'geodetic_scheme'] as $key => $type) {
            $source = ExecutiveDocument::query()->create([
                'organization_id' => $context->organization->id, 'project_id' => $project->id,
                'document_set_id' => $set->id, 'created_by' => $context->user->id,
                'document_type' => $type, 'title' => $type, 'status' => 'draft',
            ]);
            $relations[] = ['relation_type' => $key, 'target_type' => $type, 'target_id' => $source->id];
        }
        $this->allowAccess();

        return [$context, $set, [
            'document_type' => 'hidden_work_act', 'title' => 'Акт подготовки основания',
            'completed_work_id' => $work->id, 'journal_entry_id' => $entry->id,
            'profile_data' => ['next_works_permission' => 'После приемки основания'],
            'relations' => $relations,
            'signatories' => array_map(static fn (string $role): array => [
                'role' => $role, 'name' => 'Учебный представитель', 'organization' => 'Учебная организация', 'authority_document' => 'Учебный приказ',
            ], ['developer_control_representative', 'construction_representative', 'contractor_control_representative']),
        ]];
    }

    private function allowAccess(): void
    {
        $this->mock(AccessController::class, function (MockInterface $mock): void {
            $mock->shouldReceive('hasModuleAccess')->andReturn(true);
        });
        $this->mock(AuthorizationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('canAccessInterface')->andReturn(true);
            $mock->shouldReceive('can')->andReturn(true);
            $mock->shouldReceive('hasRole')->andReturn(true);
            $mock->shouldReceive('getUserRoleSlugs')->andReturn(['web_admin']);
            $mock->shouldReceive('getUserRoles')->andReturnUsing(
                static function (User $user, ?AuthorizationContext $context = null) {
                    return $user->roleAssignments()->where('is_active', true)
                        ->when($context !== null, static fn ($query) => $query->where('context_id', $context->id))->get();
                }
            );
        });
    }
}
