<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\GetClarificationAnswerReceipt;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ApplyProjectModelDecision;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Http\Controllers\EstimateGenerationAnswerReceiptController;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Models\Project;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\Support\EstimateGeneration\EstimateGenerationCanonicalPostgresTestCase;

final class ClarificationAnswerReceiptPostgresTest extends EstimateGenerationCanonicalPostgresTestCase
{
    public function test_committed_answer_can_be_read_after_question_disappears_without_mutation_and_with_current_scope_and_permissions(): void
    {
        config(['cache.default' => 'array']);
        Http::fake([]);
        Http::preventStrayRequests();
        Queue::fake();
        $rights = AssistantRealAuthorizationFixture::create();
        $rights->memberRole->update(['module_permissions' => ['ai-estimates' => ['estimate_generation.view']]]);
        $actor = $rights->member;
        $actor->organizations()->updateExistingPivot($rights->organization->id, ['project_access_mode' => 'all_projects']);
        $project = Project::factory()->for($rights->organization)->create(['is_archived' => false]);
        AuthorizationContext::getProjectContext((int) $project->id, (int) $rights->organization->id);
        $this->actingAs($actor);
        $session = EstimateGenerationSession::query()->create(['organization_id' => $rights->organization->id,
            'project_id' => $project->id, 'user_id' => $actor->id, 'status' => 'draft', 'state_version' => 0,
            'input_payload' => [], 'draft_payload' => null]);
        $scope = [(int) $rights->organization->id, (int) $project->id, (int) $session->id];
        $input = ['idempotency_key' => 'answer-receipt-test', 'expected_source_version' => 'sha256:'.str_repeat('a', 64),
            'answer_fingerprint' => str_repeat('b', 64)];
        $service = app(GetClarificationAnswerReceipt::class);
        self::assertSame('not_found', $service->handle($actor, $scope[1], $scope[2], 'wall_material', $input)['status']);
        $models = app(ProjectModelRepository::class);
        $models->saveSourceModel([new Entity('wall:test', ...[...$scope, $input['expected_source_version'], 'wall', 'wall:test',
            ['start' => [0, 0], 'end' => [1, 0]]])],
            [new Fact('fact:test', ...[...$scope, $input['expected_source_version'], 'wall:test', 'wall_material', null, null,
                0.0, 'unresolved', 'unresolved', []])], []);
        $decision = app(ApplyProjectModelDecision::class)->applyClarificationChoice(...[...$scope,
            $input['expected_source_version'], 'fact:test', 'wall_material', 'selected', 'select:'.str_repeat('c', 64),
            'Материал', null, $input['answer_fingerprint'], [], (string) $actor->id, 'Ответ пользователя',
            'decision:clarification:'.hash('sha256', $input['idempotency_key'])]);
        $before = [$session->fresh()->getAttributes(), DB::table('estimate_generation_project_model_corrections')->count(),
            DB::table('estimate_generation_credit_operations')->count(), DB::table('estimate_generation_ai_usage')->count()];
        $receipt = $service->handle($actor, $scope[1], $scope[2], 'wall_material', $input);
        self::assertSame('saved', $receipt['status']);
        self::assertSame($decision->id, $receipt['decision_id']);
        self::assertSame('select:'.str_repeat('c', 64), $receipt['response']);
        self::assertSame('0', $receipt['cost_units']);
        self::assertSame($receipt, $service->handle($actor, $scope[1], $scope[2], 'wall_material', $input));
        foreach ([['answer_fingerprint' => str_repeat('d', 64)], ['expected_source_version' => 'sha256:'.str_repeat('e', 64)]] as $change) {
            $unavailable = $service->handle($actor, $scope[1], $scope[2], 'wall_material', [...$input, ...$change]);
            self::assertSame('unavailable', $unavailable['status']);
            self::assertNull($unavailable['decision_id']);
            self::assertNull($unavailable['response']);
        }
        self::assertSame('unavailable', $service->handle($actor, $scope[1], $scope[2], 'roof_material', $input)['status']);
        $rights->owner->organizations()->updateExistingPivot($rights->organization->id, ['project_access_mode' => 'all_projects']);
        $otherActor = $service->handle($rights->owner, $scope[1], $scope[2], 'wall_material', $input);
        self::assertSame('unavailable', $otherActor['status']);
        self::assertNull($otherActor['response']);
        self::assertNull($otherActor['decision_id']);
        $request = Request::create('/', 'GET', $input);
        $request->setUserResolver(static fn () => $actor);
        $response = (new EstimateGenerationAnswerReceiptController)->show($request, $project, $session, 'wall_material', $service);
        self::assertTrue($response->getData(true)['success']);
        self::assertSame('saved', $response->getData(true)['data']['status']);
        self::assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertSame($before, [$session->fresh()->getAttributes(), DB::table('estimate_generation_project_model_corrections')->count(),
            DB::table('estimate_generation_credit_operations')->count(), DB::table('estimate_generation_ai_usage')->count()]);
        try {
            $service->handle($actor, $scope[1] + 99999, $scope[2], 'wall_material', $input);
            self::fail('Foreign project accepted.');
        } catch (ModelNotFoundException) {
            self::addToAssertionCount(1);
        }
        $rights->memberRole->update(['module_permissions' => ['ai-estimates' => []]]);
        try {
            $service->handle($actor, $scope[1], $scope[2], 'wall_material', $input);
            self::fail('Revoked permissions accepted.');
        } catch (AuthorizationException) {
            self::addToAssertionCount(1);
        }
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }
}
