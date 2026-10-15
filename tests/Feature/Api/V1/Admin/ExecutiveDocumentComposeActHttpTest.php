<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocument;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentSet;
use App\BusinessModules\Features\ExecutiveDocumentation\Services\ExecutiveDocumentInput;
use App\BusinessModules\Features\ExecutiveDocumentation\Services\ExecutiveDocumentRenderService;
use App\BusinessModules\Features\ExecutiveDocumentation\Services\ExecutiveDocumentationService;
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
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class ExecutiveDocumentComposeActHttpTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('printVolumes')]
    public function test_first_composed_pdf_contains_frozen_journal_requisites_and_volume(string|int|float $volume, string $expected, ?int $presentedWorks = null): void
    {
        [$context, $set, $payload] = $this->fixture();
        $payload['profile_data']['actual_volume'] = $volume;
        if ($presentedWorks !== null) {
            $payload['profile_data']['presented_works'] = $presentedWorks;
        }
        $response = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/executive-documentation/sets/{$set->id}/compose-act", $payload)->assertCreated();
        $document = ExecutiveDocument::query()->findOrFail($response->json('data.id'));
        $version = $document->versions->first();
        $snapshot = $version->metadata['print_snapshot'];
        self::assertSame('ОЖР-1', $snapshot['relations'][0]['domain_snapshot']['journal_number'] ?? null);
        self::assertSame('Общий журнал', $snapshot['relations'][0]['domain_snapshot']['journal_name']);
        self::assertSame(1, $snapshot['relations'][0]['domain_snapshot']['entry_number']);
        self::assertSame('2026-10-02', $snapshot['relations'][0]['domain_snapshot']['entry_date']);
        self::assertSame(ExecutiveDocumentRenderService::TEMPLATE_VERSION, $version->metadata['template_version']);
        self::assertSame($snapshot['relations'], $version->basis_snapshot['relations']);
        $bytes = Storage::disk('s3')->get($version->file_url);
        $text = (new \Smalot\PdfParser\Parser())->parseContent($bytes)->getText();
        $text = preg_replace('/\s+/u', ' ', $text);
        self::assertStringContainsString('Фактический объем работ: '.$expected, $text);
        if ($presentedWorks !== null) {
            self::assertStringContainsString((string) $presentedWorks.' Фактический объем работ:', $text);
        }
        self::assertStringContainsString('ОЖР-1', $text);
        self::assertStringContainsString('Общий журнал', $text);
        self::assertStringContainsString('запись № 1', $text);
        self::assertStringContainsString('02.10.2026', $text);
        self::assertSame(hash('sha256', $bytes), $version->content_hash);
    }

    public static function printVolumes(): array
    {
        return [
            'text with unit' => ['100.000 м²', '100.000 м²'],
            'integer' => [100, '100'],
            'fractional' => [12.5, '12.5'],
            'zero' => [0, '0'],
            'numeric work description' => ['10 м²', '10 м²', 12345],
        ];
    }

    public function test_changed_journal_requisites_reject_a_prepared_file_before_upload(): void
    {
        [$context, $set, $payload] = $this->fixture();
        $response = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/executive-documentation/sets/{$set->id}/compose-act", $payload)->assertCreated();
        $document = ExecutiveDocument::query()->findOrFail($response->json('data.id'));
        $version = $document->versions->first();
        $bytes = Storage::disk('s3')->get($version->file_url);
        $filesBefore = count(Storage::disk('s3')->allFiles());
        $journal = ConstructionJournal::query()->findOrFail($document->journalEntry->journal_id);
        $journal->update(['journal_number' => 'ОЖР-изменен']);

        try {
            app(ExecutiveDocumentationService::class)->addPreparedVersion($document, $context->user->id, [
                'version_number' => '2.0', 'expected_version_id' => $version->id,
                'file' => \Illuminate\Http\UploadedFile::fake()->createWithContent('act.pdf', $bytes),
                'metadata' => ['template_version' => ExecutiveDocumentRenderService::TEMPLATE_VERSION, 'print_snapshot' => $version->metadata['print_snapshot']],
            ]);
            self::fail('Файл со старыми реквизитами не должен получить новый снимок связей.');
        } catch (\DomainException $exception) {
            self::assertSame(trans_message('executive_documentation.errors.version_conflict'), $exception->getMessage());
        }
        self::assertSame(1, $document->versions()->count());
        self::assertSame($filesBefore, count(Storage::disk('s3')->allFiles()));
        self::assertSame($version->content_hash, hash('sha256', Storage::disk('s3')->get($version->file_url)));
    }

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
