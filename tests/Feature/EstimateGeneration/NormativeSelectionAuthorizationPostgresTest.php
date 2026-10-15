<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Review\SelectNormativeCandidate;
use App\BusinessModules\Addons\EstimateGeneration\Http\Controllers\EstimateGenerationReviewController;
use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\SelectEstimateGenerationNormativeCandidateRequest;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeCandidateSelectionService;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Validator;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\Support\EstimateGeneration\EstimateGenerationCanonicalPostgresTestCase;

final class NormativeSelectionAuthorizationPostgresTest extends EstimateGenerationCanonicalPostgresTestCase
{
    #[DataProvider('deniedActors')]
    public function test_current_actor_is_denied_before_direct_selection(string $reason): void
    {
        [$rights, $session, $project] = $this->fixture();
        $actor = $rights->member;
        if ($reason === 'revoked_permission') {
            $rights->memberRole->update(['module_permissions' => ['ai-estimates' => []]]);
        } elseif ($reason === 'inactive_user') {
            User::query()->whereKey($actor->id)->update(['is_active' => false]);
        } elseif ($reason === 'inactive_membership') {
            $actor->organizations()->updateExistingPivot($rights->organization->id, ['is_active' => false]);
        } elseif ($reason === 'revoked_assignment') {
            UserRoleAssignment::query()->whereKey($rights->memberAssignment->id)->update(['is_active' => false]);
        } elseif ($reason === 'foreign_actor') {
            $actor = $rights->foreignOwner;
        } else {
            $project->update(['is_archived' => true]);
        }
        $selection = Mockery::mock(NormativeCandidateSelectionService::class);
        $selection->shouldNotReceive('select');
        $this->app->instance(NormativeCandidateSelectionService::class, $selection);
        $before = $session->fresh()->getRawOriginal();
        try {
            $this->select($actor, $session);
            self::fail('Direct selection must require current actor permission.');
        } catch (AuthorizationException) {
            self::assertSame($before, $session->fresh()->getRawOriginal());
        }
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public static function deniedActors(): iterable
    {
        foreach (['revoked_permission', 'inactive_user', 'inactive_membership', 'revoked_assignment', 'foreign_actor', 'archived_project'] as $reason) {
            yield $reason => [$reason];
        }
    }

    public function test_authorized_actor_reaches_existing_selection_once(): void
    {
        [$rights, $session] = $this->fixture();
        $selection = Mockery::mock(NormativeCandidateSelectionService::class);
        $selection->shouldReceive('select')->once()->with(Mockery::on(static fn ($actual): bool => $actual instanceof EstimateGenerationSession
            && $actual->id === $session->id && $actual->organization_id === $session->organization_id), 'work-1', 101, false)->andReturn([]);
        $this->app->instance(NormativeCandidateSelectionService::class, $selection);
        self::assertSame($session->id, $this->select($rights->member, $session)->id);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_foreign_organization_or_project_is_not_found_before_selection(): void
    {
        [$rights, $session] = $this->fixture();
        $selection = Mockery::mock(NormativeCandidateSelectionService::class);
        $selection->shouldNotReceive('select');
        $this->app->instance(NormativeCandidateSelectionService::class, $selection);
        foreach (['organization', 'project'] as $scope) {
            try {
                app(SelectNormativeCandidate::class)->handle((int) $session->id,
                    $scope === 'organization' ? (int) $rights->foreignOrganization->id : (int) $session->organization_id,
                    $scope === 'project' ? (int) $session->project_id + 100000 : (int) $session->project_id,
                    $rights->member, 3, 'work-1', 101, false);
                self::fail('Foreign session scope must fail.');
            } catch (ModelNotFoundException) {
                self::assertSame(3, $session->fresh()->state_version);
            }
        }
    }

    public function test_revocation_after_request_validation_returns_admin403(): void
    {
        [$rights, $session, $project] = $this->fixture();
        $request = $this->request($rights->member);
        $rights->memberRole->update(['module_permissions' => ['ai-estimates' => []]]);
        $selection = Mockery::mock(NormativeCandidateSelectionService::class);
        $selection->shouldNotReceive('select');
        $this->app->instance(NormativeCandidateSelectionService::class, $selection);
        $response = app(EstimateGenerationReviewController::class)->select($request, $project, $session);
        self::assertSame(403, $response->getStatusCode());
        self::assertFalse($response->getData(true)['success']);
        self::assertSame(3, $session->fresh()->state_version);
    }

    public function test_scope_guard403_is_preserved_instead_of_becoming500(): void
    {
        [$rights, $session] = $this->fixture();
        $foreign = Project::factory()->for($rights->foreignOrganization)->create(['is_archived' => false]);
        try {
            app(EstimateGenerationReviewController::class)->select($this->request($rights->member), $foreign, $session);
            self::fail('Wrong project must fail.');
        } catch (HttpExceptionInterface $exception) {
            self::assertSame(403, $exception->getStatusCode());
        }
    }

    private function select(User $actor, EstimateGenerationSession $session): EstimateGenerationSession
    {
        return app(SelectNormativeCandidate::class)->handle((int) $session->id, (int) $session->organization_id,
            (int) $session->project_id, $actor, 3, 'work-1', 101, false);
    }

    private function request(User $actor): SelectEstimateGenerationNormativeCandidateRequest
    {
        $input = ['state_version' => 3, 'work_item_key' => 'work-1', 'norm_id' => 101, 'selection_source' => 'offered_candidate', 'response_scope' => 'review_queue'];
        $request = SelectEstimateGenerationNormativeCandidateRequest::create('/normative-candidate', 'POST', $input);
        $request->setUserResolver(static fn (): User => $actor);
        $request->setValidator(Validator::make($input, $request->rules()));

        return $request;
    }

    private function fixture(): array
    {
        config(['cache.default' => 'array']);
        Http::fake([]);
        Http::preventStrayRequests();
        Queue::fake();
        $rights = AssistantRealAuthorizationFixture::create();
        $rights->memberRole->update(['module_permissions' => ['ai-estimates' => ['estimate_generation.select_normative']]]);
        $rights->member->organizations()->updateExistingPivot($rights->organization->id, ['project_access_mode' => 'all_projects']);
        $project = Project::factory()->for($rights->organization)->create(['is_archived' => false]);
        AuthorizationContext::getProjectContext((int) $project->id, (int) $rights->organization->id);
        $session = EstimateGenerationSession::query()->create(['organization_id' => $rights->organization->id,
            'project_id' => $project->id, 'user_id' => $rights->member->id, 'status' => 'estimate_review_required',
            'state_version' => 3, 'input_payload' => ['description' => 'Selection authorization'], 'draft_payload' => []]);
        $this->actingAs($rights->member);

        return [$rights, $session, $project];
    }
}
