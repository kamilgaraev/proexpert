<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Apply\ApplyGeneratedEstimate;
use App\BusinessModules\Addons\EstimateGeneration\Application\Apply\ApplyGeneratedEstimateCommand;
use App\BusinessModules\Addons\EstimateGeneration\Application\Apply\GeneratedEstimateWriter;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\EstimateGenerationSessionReconciler;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EstimateGenerationActionAuthorization;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EstimateGenerationRetryDispatcher;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\RetryEstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\RetryEstimateGenerationSessionCommand;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Project;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\Support\EstimateGeneration\EstimateGenerationCanonicalPostgresTestCase;

final class EstimatorActionAuthorizationPostgresTest extends EstimateGenerationCanonicalPostgresTestCase
{
    #[DataProvider('deniedActions')]
    public function test_current_authorization_rejects_before_state_writer_dispatcher_or_money(string $action, string $reason): void
    {
        config(['cache.default' => 'array']);
        Http::fake([]);
        Http::preventStrayRequests();
        Queue::fake();
        $fixture = AssistantRealAuthorizationFixture::create();
        $fixture->memberRole->update(['module_permissions' => ['ai-estimates' => ['estimate_generation.apply', 'estimate_generation.generate']]]);
        $actor = $fixture->member;
        $actor->organizations()->updateExistingPivot($fixture->organization->id, ['project_access_mode' => 'all_projects']);
        $project = Project::factory()->for($fixture->organization)->create(['is_archived' => false]);
        \App\Domain\Authorization\Models\AuthorizationContext::getProjectContext((int) $project->id, (int) $fixture->organization->id);
        $session = EstimateGenerationSession::query()->create(['organization_id' => $fixture->organization->id, 'project_id' => $project->id,
            'user_id' => $actor->id, 'status' => $action === 'apply' ? 'ready_to_apply' : 'failed',
            'resume_status' => $action === 'retry' ? 'generating' : null, 'state_version' => 3, 'input_payload' => ['description' => 'Проверка отказа прав'],
            'draft_payload' => null]);
        $permission = $action === 'apply' ? 'estimate_generation.apply' : 'estimate_generation.generate';
        $context = ['organization_id' => (int) $fixture->organization->id, 'project_id' => (int) $project->id];
        $this->actingAs($actor);
        self::assertTrue(app(AuthorizationService::class)->canCurrent($actor, $permission, $context));
        app(EstimateGenerationActionAuthorization::class)->authorize($actor, $session, $permission);
        $organizationId = (int) $fixture->organization->id;
        if ($reason === 'revoked_permission') {
            $fixture->memberRole->update(['module_permissions' => ['ai-estimates' => []]]);
        } elseif ($reason === 'revoked_assignment') {
            \App\Domain\Authorization\Models\UserRoleAssignment::query()->whereKey($fixture->memberAssignment->id)->update(['is_active' => false]);
        } elseif ($reason === 'inactive_user') {
            \App\Models\User::query()->whereKey($fixture->member->id)->update(['is_active' => false]);
            self::assertTrue($actor->is_active);
        } elseif ($reason === 'inactive_membership') {
            $fixture->member->organizations()->updateExistingPivot($organizationId, ['is_active' => false]);
        } elseif ($reason === 'foreign_actor') {
            $actor = $fixture->foreignOwner;
        } elseif ($reason === 'foreign_scope') {
            $organizationId = (int) $fixture->foreignOrganization->id;
        } else {
            $actor = null;
        }
        $writer = \Mockery::mock(GeneratedEstimateWriter::class);
        $writer->shouldNotReceive('createFromSession');
        $writer->shouldNotReceive('publishedMetadata');
        $this->app->instance(GeneratedEstimateWriter::class, $writer);
        $dispatcher = \Mockery::mock(EstimateGenerationRetryDispatcher::class);
        $dispatcher->shouldNotReceive('dispatchGeneration');
        $dispatcher->shouldNotReceive('dispatchDocuments');
        $this->app->instance(EstimateGenerationRetryDispatcher::class, $dispatcher);
        $reconciler = \Mockery::mock(EstimateGenerationSessionReconciler::class);
        $reconciler->shouldNotReceive('reconcile');
        $this->app->instance(EstimateGenerationSessionReconciler::class, $reconciler);
        $before = $session->fresh()->getRawOriginal();
        $moneyBefore = $this->financialRows();
        try {
            if ($action === 'apply') {
                app(ApplyGeneratedEstimate::class)->handle(new ApplyGeneratedEstimateCommand((int) $session->id, $organizationId,
                    (int) $project->id, 3, idempotencyKey: (string) Str::uuid(), actor: $actor));
            } else {
                app(RetryEstimateGenerationSession::class)->handle(new RetryEstimateGenerationSessionCommand((int) $session->id,
                    $organizationId, (int) $project->id, 3, $actor, ['quote_id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid()]));
            }
            self::fail('Expected current authorization refusal.');
        } catch (AuthorizationException|ModelNotFoundException $error) {
            self::assertInstanceOf($reason === 'foreign_scope' ? ModelNotFoundException::class : AuthorizationException::class, $error);
        }
        self::assertSame($before, $session->fresh()->getRawOriginal());
        self::assertSame($moneyBefore, $this->financialRows());
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public static function deniedActions(): array
    {
        $cases = [];
        foreach (['apply', 'retry'] as $action) {
            foreach (['revoked_permission', 'revoked_assignment', 'inactive_user', 'inactive_membership', 'foreign_actor', 'foreign_scope', 'missing_actor'] as $reason) {
                $cases[$action.':'.$reason] = [$action, $reason];
            }
        }

        return $cases;
    }

    private function financialRows(): array
    {
        $tables = [];
        foreach (Schema::getTables() as $table) {
            $name = $table['name'];
            if (! str_contains($name, 'credit') && ! str_contains($name, 'quota') && ! str_contains($name, 'wallet')) {
                continue;
            }
            $rows = DB::table($name)->get()->map(static fn (object $row): string => json_encode((array) $row, JSON_THROW_ON_ERROR))->all();
            sort($rows, SORT_STRING);
            $tables[$name] = $rows;
        }
        ksort($tables, SORT_STRING);

        return $tables;
    }
}
