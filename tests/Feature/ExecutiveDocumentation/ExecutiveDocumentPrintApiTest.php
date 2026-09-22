<?php

declare(strict_types=1);

namespace Tests\Feature\ExecutiveDocumentation;

use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocument;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentSet;
use App\BusinessModules\Features\ExecutiveDocumentation\Services\ExecutiveDocumentationService;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class ExecutiveDocumentPrintApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_prepare_requires_expected_version_id(): void
    {
        Storage::fake('s3');
        [$context, $document] = $this->documentFixture();

        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/executive-documentation/documents/'.$document->id.'/prepare', [
                'template_version' => '344-369-v1',
                'version_number' => '2.0',
                'expected_revision' => 0,
                'operation_key' => 'prepare-missing-expected',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['expected_version_id']);
    }

    public function test_prepare_rejects_stale_expected_version(): void
    {
        Storage::fake('s3');
        [$context, $document, $current] = $this->documentFixture(withVersion: true);

        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/executive-documentation/documents/'.$document->id.'/prepare', [
                'template_version' => '344-369-v1',
                'version_number' => '2.0',
                'expected_version_id' => $current->id + 1000,
                'expected_revision' => 0,
                'operation_key' => 'prepare-stale-expected',
            ])
            ->assertStatus(409);
    }

    public function test_prepare_hides_foreign_document_and_server_controls_origin(): void
    {
        Storage::fake('s3');
        [$owner, $document, $current] = $this->documentFixture(withVersion: true);
        $foreign = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $this->withHeaders($foreign->authHeaders())
            ->postJson('/api/v1/admin/executive-documentation/documents/'.$document->id.'/prepare', [
                'template_version' => '344-369-v1', 'version_number' => '2.0',
                'expected_version_id' => $current->id, 'expected_revision' => 0,
                'operation_key' => 'foreign-prepare', 'origin' => 'registered_external',
            ])
            ->assertStatus(404);

        $response = $this->withHeaders($owner->authHeaders())
            ->postJson('/api/v1/admin/executive-documentation/documents/'.$document->id.'/prepare', [
                'template_version' => '344-369-v1', 'version_number' => '2.0',
                'expected_version_id' => $current->id, 'expected_revision' => 0,
                'operation_key' => 'prepare-origin-server', 'origin' => 'registered_external',
            ])
            ->assertOk();

        $response->assertJsonPath('data.origin', 'generated_preparation');
        $response->assertJsonPath('data.template_version', '344-369-v1');
    }

    public function test_print_returns_pdf_for_supported_template_and_rejects_unknown_template(): void
    {
        Storage::fake('s3');
        [$context, $document, $version] = $this->documentFixture(withVersion: true);

        $this->withHeaders($context->authHeaders())
            ->get('/api/v1/admin/executive-documentation/versions/'.$version->id.'/print?template_version=344-369-v1')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertSee('%PDF', false);

        $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/executive-documentation/versions/'.$version->id.'/print?template_version=unsupported')
            ->assertStatus(422);
    }

    public function test_prepare_replay_with_changed_body_is_conflict(): void
    {
        Storage::fake('s3');
        [$context, $document, $current] = $this->documentFixture(withVersion: true);
        $url = '/api/v1/admin/executive-documentation/documents/'.$document->id.'/prepare';
        $payload = [
            'template_version' => '344-369-v1', 'version_number' => '2.0',
            'expected_version_id' => $current->id, 'expected_revision' => 0,
            'operation_key' => 'prepare-replay-conflict',
        ];

        $this->withHeaders($context->authHeaders())->postJson($url, $payload)->assertOk();
        $this->withHeaders($context->authHeaders())
            ->postJson($url, array_replace($payload, ['version_number' => '2.1']))
            ->assertStatus(409);
    }

    private function documentFixture(bool $withVersion = false): array
    {
        $this->app->forgetInstance(\App\Domain\Authorization\Services\ModulePermissionChecker::class);
        $this->app->forgetInstance(\App\Domain\Authorization\Services\PermissionResolver::class);
        $this->app->forgetInstance(AuthorizationService::class);
        $this->mock(\App\Modules\Core\AccessController::class)->shouldReceive('hasModuleAccess')->andReturnTrue();
        $this->mock(AuthorizationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('canAccessInterface')->andReturnTrue();
            $mock->shouldReceive('can')->andReturnTrue();
            $mock->shouldReceive('hasRole')->andReturnTrue();
            $mock->shouldReceive('getUserRoleSlugs')->andReturn(['web_admin']);
        });
        $context = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $set = ExecutiveDocumentSet::query()->create([
            'organization_id' => $context->organization->id, 'project_id' => $project->id,
            'created_by' => $context->user->id, 'set_number' => 'PRINT-'.uniqid(),
            'title' => 'Print API set', 'status' => 'draft',
        ]);
        $document = ExecutiveDocument::query()->create([
            'organization_id' => $context->organization->id, 'project_id' => $project->id,
            'document_set_id' => $set->id, 'created_by' => $context->user->id,
            'document_type' => 'hidden_work_act', 'title' => 'Print API document', 'status' => 'draft',
            'profile_data' => ['act_number' => 'ACT-1', 'presented_works' => 'Работы', 'drawing_set_code' => 'RD-1'],
        ]);
        if (! $withVersion) {
            return [$context, $document, null];
        }
        $version = app(ExecutiveDocumentationService::class)->addVersion($document, $context->user->id, [
            'version_number' => '1.0', 'file' => UploadedFile::fake()->createWithContent('print.pdf', 'print'),
            'profile_data' => $document->profile_data, 'operation_key' => 'print-fixture-'.uniqid(),
        ]);

        return [$context, $document->fresh(), $version->fresh()];
    }
}
