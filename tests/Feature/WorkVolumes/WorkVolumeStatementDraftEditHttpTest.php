<?php

declare(strict_types=1);

namespace Tests\Feature\WorkVolumes;

use App\BusinessModules\Features\BudgetEstimates\Services\WorkVolumeStatementService;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Project;
use App\Models\User;
use App\Modules\Core\AccessController;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class WorkVolumeStatementDraftEditHttpTest extends TestCase
{
    public function test_draft_edit_validates_http_contract_preserves_line_ids_and_immutable_retry_history(): void
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
        $line = ['line_key' => (string) Str::uuid(), 'name' => 'Стена', 'unit_code' => 'м²', 'quantity' => '100', 'place' => ['axis' => 'А-1']];
        $statement = app(WorkVolumeStatementService::class)->createDraft($context->user, $project->id, ['lines' => [$line]]);
        $url = '/api/v1/admin/projects/'.$project->id.'/work-volume-statements/'.$statement->id.'/draft';
        $payload = ['expected_draft_version' => 1, 'operation_key' => 'http-edit', 'lines' => [[...$line, 'quantity' => '80.000001']]];
        for ($index = 2; $index <= 26; $index++) {
            $payload['lines'][] = [...$line, 'line_key' => (string) Str::uuid(), 'place' => ['axis' => 'А-'.$index]];
        }
        $this->withHeaders($context->authHeaders());
        $this->putJson($url, [...$payload, 'expected_draft_version' => null])->assertUnprocessable();
        $this->putJson($url, [...$payload, 'source_file_hash' => str_repeat('a', 64)])->assertUnprocessable();
        $this->putJson($url, [...$payload, 'lines' => [[...$line, 'quantity' => ['bad']]]])->assertUnprocessable();
        $writes = 0;
        $captureWrites = true;
        DB::listen(static function ($query) use (&$writes, &$captureWrites): void {
            if ($captureWrites && (str_starts_with($query->sql, 'insert into "work_volume_statement_lines"') || str_starts_with($query->sql, 'update "work_volume_statement_lines"'))) {
                $writes++;
            }
        });
        $first = $this->putJson($url, $payload)->assertOk()->assertJsonPath('data.draft_version', 2)->assertJsonCount(26, 'data.lines')
            ->assertJsonPath('data.lines.0.id', $statement->lines->first()->id)->assertJsonPath('data.lines.0.quantity', '80.000001');
        $captureWrites = false;
        self::assertLessThanOrEqual(2, $writes, 'Правка импортированной ведомости не должна выполнять запрос на каждую строку');
        $this->putJson($url, $payload)->assertOk()->assertExactJson($first->json());
        $this->putJson($url, [...$payload, 'operation_key' => 'stale-http-edit'])->assertConflict();
        $editId = DB::table('work_volume_statement_draft_edits')->where('statement_id', $statement->id)->value('id');
        foreach (['update', 'delete'] as $mutation) {
            DB::beginTransaction();
            try {
                $query = DB::table('work_volume_statement_draft_edits')->where('id', $editId);
                $mutation === 'update' ? $query->update(['result_snapshot' => '{}']) : $query->delete();
                self::fail('Историю ответа операции нельзя переписать или удалить');
            } catch (QueryException $exception) {
                self::assertSame('55000', $exception->errorInfo[0]);
            } finally {
                DB::rollBack();
            }
        }
        $foreign = AdminApiTestContext::create();
        $foreign->user->organizations()->updateExistingPivot($foreign->organization->id, ['project_access_mode' => 'all_projects']);
        $project->organizations()->attach($foreign->organization->id, ['role' => 'contractor', 'is_active' => true]);
        $this->withHeaders($foreign->authHeaders())->putJson($url, [...$payload, 'expected_draft_version' => 2, 'operation_key' => 'foreign-edit'])->assertNotFound();
        self::assertSame('80.000001', $statement->fresh('lines')->lines->first()->quantity);
    }
}
