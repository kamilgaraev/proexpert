<?php

declare(strict_types=1);

namespace Tests\Feature\WorkVolumes;

use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Project;
use App\Models\User;
use App\Modules\Core\AccessController;
use App\Services\Storage\DTO\CurrentStoredFile;
use App\Services\Storage\FileService;
use Illuminate\Http\UploadedFile;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class WorkVolumeStatementImportHttpTest extends TestCase
{
    public function test_http_import_retains_draft_and_scopes_source_download(): void
    {
        $context = AdminApiTestContext::create();
        $context->user->organizations()->updateExistingPivot($context->organization->id, ['project_access_mode' => 'all_projects']);
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $authorization = $this->mock(AuthorizationService::class);
        $authorization->shouldReceive('can')->andReturnTrue();
        $authorization->shouldReceive('canAccessInterface')->andReturnTrue();
        $authorization->shouldReceive('hasRole')->andReturnTrue();
        $authorization->shouldReceive('getUserRoleSlugs')->andReturn(['web_admin']);
        $authorization->shouldReceive('getUserRoles')->andReturnUsing(static fn (User $user, ?AuthorizationContext $scope = null) => $user->roleAssignments()->where('is_active', true)->when($scope !== null, static fn ($query) => $query->where('context_id', $scope->id))->get());
        $this->mock(AccessController::class)->shouldReceive('hasModuleAccess')->andReturnTrue();
        $storage = $this->mock(FileService::class);
        $storage->shouldReceive('putPrivate')->once()->andReturnUsing(static fn (string $key, mixed $stream, string $mime, string $hash) => new CurrentStoredFile($key, 'etag', strlen(stream_get_contents($stream)), $hash, $mime));
        $storage->shouldReceive('temporaryDownloadUrl')->once()->withArgs(static fn (string $key, int $ttl) => str_starts_with($key, 'org-'.$context->organization->id.'/work-volume-imports/') && $ttl === 900)->andReturn('https://storage.example.test/source.csv');
        $url = '/api/v1/admin/projects/'.$project->id.'/work-volume-statements/imports';
        $this->withHeaders([...$context->authHeaders(), 'Accept' => 'application/json']);
        $created = $this->post($url, [
            'file' => UploadedFile::fake()->createWithContent('ВОР.csv', "Работа;Количество;Единица;Место\nСтена;неразборчиво;м²;А-1\nКабель;20;м;А-2\n"),
            'operation_key' => 'http-import', 'first_data_row' => 2,
            'columns' => ['name' => 0, 'quantity' => 1, 'unit_code' => 2, 'place' => 3],
        ])->assertCreated()->assertJsonPath('data.status', 'draft');
        $importUrl = $url.'/'.$created->json('data.id');
        $this->getJson($url)->assertOk()->assertJsonPath('meta.total', 1);
        $preview = $this->getJson($importUrl.'?per_page=1')->assertOk()->assertJsonPath('data.preview_rows.0.quantity', 'неразборчиво')
            ->assertJsonCount(1, 'data.preview_rows')->assertJsonPath('data.pagination.total', 2);
        $this->getJson($importUrl.'?page='.PHP_INT_MAX.'&per_page=100')->assertOk()->assertJsonPath('data.pagination.current_page', 1);
        $this->postJson($importUrl.'/register', ['expected_preview_version' => 1])->assertUnprocessable();
        $rows = $preview->json('data.preview_rows');
        $rows[0]['quantity'] = '10.000001';
        $this->patchJson($importUrl.'/preview', ['expected_preview_version' => 1, 'rows' => $rows])
            ->assertOk()->assertJsonPath('data.preview_version', 2);
        $this->postJson($importUrl.'/register', ['expected_preview_version' => 2, 'name' => 'ВОР по файлу'])
            ->assertCreated()->assertJsonPath('data.lines.0.quantity', '10.000001')
            ->assertJsonCount(2, 'data.lines')->assertJsonPath('data.lines.1.quantity', '20.000000');
        $this->getJson($importUrl.'/source')->assertOk()->assertJsonPath('data.url', 'https://storage.example.test/source.csv');
        $foreign = AdminApiTestContext::create();
        $foreign->user->organizations()->updateExistingPivot($foreign->organization->id, ['project_access_mode' => 'all_projects']);
        $project->organizations()->attach($foreign->organization->id, ['role' => 'contractor', 'is_active' => true]);
        $this->withHeaders($foreign->authHeaders())->getJson($importUrl)->assertNotFound();
        $this->withHeaders($foreign->authHeaders())->getJson($importUrl.'/source')->assertNotFound();
        $this->withHeaders($foreign->authHeaders())->getJson($url)->assertOk()->assertJsonPath('meta.total', 0);
    }
}
