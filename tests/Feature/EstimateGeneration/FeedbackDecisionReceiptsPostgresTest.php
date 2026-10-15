<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Review\RecordEstimateGenerationFeedback;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalReviewSummaryProjector;
use App\BusinessModules\Addons\EstimateGeneration\Http\Controllers\EstimateGenerationReviewController;
use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\EstimateGenerationFeedbackRequest;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationFeedback;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationReviewItemService;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationReviewQueueQuery;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Models\Project;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\Support\EstimateGeneration\EstimateGenerationCanonicalPostgresTestCase;

final class FeedbackDecisionReceiptsPostgresTest extends EstimateGenerationCanonicalPostgresTestCase
{
    public function test_lost_response_replay_returns_original_feedback_without_reapplying_removed_work(): void
    {
        [$rights, $session, $input] = $this->fixture();
        $id = $this->record($rights->member, $session, $input);
        $after = $session->fresh()->getRawOriginal();
        self::assertGreaterThan(3, $session->fresh()->state_version);
        self::assertSame(['work-1'], array_column($session->fresh()->draft_payload['local_estimates'][0]['sections'][0]['work_items'], 'key'));
        // Old version remains intentional: the command has already committed.
        self::assertSame($id, $this->record($rights->member, $session, $input));
        self::assertSame($after, $session->fresh()->getRawOriginal());
        self::assertSame(1, EstimateGenerationFeedback::query()->where('session_id', $session->id)->count());
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_receipt_cannot_be_reused_for_changed_command_or_by_another_actor(): void
    {
        [$rights, $session, $input] = $this->fixture();
        $this->record($rights->member, $session, $input);
        $after = $session->fresh()->getRawOriginal();
        foreach (['payload', 'version', 'actor'] as $change) {
            $changed = $input;
            $actor = $rights->member;
            if ($change === 'payload') {
                $changed['payload']['action'] = 'keep_item';
            } elseif ($change === 'version') {
                $changed['state_version']++;
            } else {
                $actor = $rights->owner;
                $actor->organizations()->updateExistingPivot($rights->organization->id, ['project_access_mode' => 'all_projects']);
            }
            try {
                $this->record($actor, $session, $changed);
                self::fail('A different command must not reuse the receipt.');
            } catch (ValidationException $exception) {
                self::assertArrayHasKey('request_id', $exception->errors());
            }
        }
        self::assertSame($after, $session->fresh()->getRawOriginal());
        self::assertSame(1, EstimateGenerationFeedback::query()->where('session_id', $session->id)->count());
    }

    public function test_revoked_rights_are_checked_before_replaying_a_successful_receipt(): void
    {
        [$rights, $session, $input] = $this->fixture();
        $this->record($rights->member, $session, $input);
        $rights->memberRole->update(['module_permissions' => ['ai-estimates' => []]]);
        $after = $session->fresh()->getRawOriginal();
        try {
            $this->record($rights->member, $session, $input);
            self::fail('Revoked actor must not read a successful receipt.');
        } catch (AuthorizationException) {
            self::assertSame($after, $session->fresh()->getRawOriginal());
            self::assertSame(1, EstimateGenerationFeedback::query()->where('session_id', $session->id)->count());
        }
    }

    public function test_foreign_scope_and_actor_cannot_mutate_the_session_directly(): void
    {
        [$rights, $session, $input] = $this->fixture();
        $before = $session->fresh()->getRawOriginal();
        foreach (['actor', 'organization', 'project'] as $change) {
            try {
                app(RecordEstimateGenerationFeedback::class)->handle((int) $session->id,
                    $change === 'organization' ? (int) $rights->foreignOrganization->id : (int) $session->organization_id,
                    $change === 'project' ? (int) $session->project_id + 100000 : (int) $session->project_id,
                    $change === 'actor' ? $rights->foreignOwner : $rights->member, 3, $input);
                self::fail('Cross-scope mutation must fail.');
            } catch (AuthorizationException|ModelNotFoundException) {
                self::assertSame($before, $session->fresh()->getRawOriginal());
            }
        }
        self::assertSame(0, EstimateGenerationFeedback::query()->where('session_id', $session->id)->count());
    }

    public function test_failed_duplicate_guard_rolls_back_feedback_and_receipt_and_allows_corrected_retry(): void
    {
        [$rights, $session, $input] = $this->fixture();
        $input['payload']['action'] = 'keep_item';
        $before = $session->fresh()->getRawOriginal();
        try {
            $this->record($rights->member, $session, $input);
            self::fail('Unverified physical duplicate identity must fail.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('work_item_key', $exception->errors());
        }
        self::assertSame($before, $session->fresh()->getRawOriginal());
        self::assertSame(0, EstimateGenerationFeedback::query()->where('session_id', $session->id)->count());
        $input['payload']['action'] = 'remove_item';
        self::assertGreaterThan(0, $this->record($rights->member, $session, $input));
    }

    public function test_legacy_client_without_request_id_can_still_save_an_explicit_removal(): void
    {
        [$rights, $session, $input] = $this->fixture();
        unset($input['request_id']);
        self::assertGreaterThan(0, $this->record($rights->member, $session, $input));
        self::assertArrayNotHasKey('feedback_receipts', $session->fresh()->input_payload);
    }

    public function test_permission_revoked_after_request_validation_returns_admin_403_instead_of_500(): void
    {
        [$rights, $session, $input] = $this->fixture();
        $request = EstimateGenerationFeedbackRequest::create('/feedback', 'POST', $input);
        $request->setUserResolver(static fn (): User => $rights->member);
        $request->setValidator(Validator::make($input, $request->rules()));
        $rights->memberRole->update(['module_permissions' => ['ai-estimates' => []]]);
        $response = app(EstimateGenerationReviewController::class)->feedback($request, Project::query()->findOrFail($session->project_id), $session);
        self::assertSame(403, $response->getStatusCode());
        self::assertFalse($response->getData(true)['success']);
        self::assertSame(0, EstimateGenerationFeedback::query()->where('session_id', $session->id)->count());
        self::assertSame(3, $session->fresh()->state_version);
    }

    public function test_malformed_request_id_is_rejected_without_side_effects(): void
    {
        [$rights, $session, $input] = $this->fixture();
        $input['request_id'] = 'not-a-uuid';
        try {
            $this->record($rights->member, $session, $input);
            self::fail('Invalid replay identity must fail.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('request_id', $exception->errors());
        }
        self::assertSame(0, EstimateGenerationFeedback::query()->where('session_id', $session->id)->count());
        self::assertSame(3, $session->fresh()->state_version);
    }

    public function test_verified_group_is_previewed_across_sql_pages_and_merge_replays_once(): void
    {
        [$rights, $session, $input] = $this->fixture(true);
        self::assertTrue(app(EstimateGenerationReviewQueueQuery::class)->hasFreshProjection($session));
        $queue = app(EstimateGenerationReviewItemService::class)->forSession($session, ['per_page' => 1, 'search' => 'work-2']);
        self::assertCount(1, $queue['items']);
        self::assertSame(3, $queue['state_version']);
        self::assertSame(['work-1', 'work-2'], array_column($queue['items'][0]['duplicate_group']['items'], 'key'));
        self::assertTrue($queue['items'][0]['duplicate_group']['can_resolve']);
        $input['payload'] = ['action' => 'merge_with_existing', 'target_work_item_key' => 'work-1'];
        $id = $this->record($rights->member, $session, $input);
        self::assertSame(['work-1'], array_column($session->fresh()->draft_payload['local_estimates'][0]['sections'][0]['work_items'], 'key'));
        self::assertSame($id, $this->record($rights->member, $session, $input));
        self::assertSame(1, EstimateGenerationFeedback::query()->where('session_id', $session->id)->count());
    }

    private function record(User $actor, EstimateGenerationSession $session, array $input): int
    {
        return app(RecordEstimateGenerationFeedback::class)->handle((int) $session->id, (int) $session->organization_id,
            (int) $session->project_id, $actor, $input['state_version'], $input);
    }

    private function fixture(bool $verified = false): array
    {
        config(['cache.default' => 'array']);
        Http::fake([]);
        Http::preventStrayRequests();
        Queue::fake();
        $rights = AssistantRealAuthorizationFixture::create();
        $rights->memberRole->update(['module_permissions' => ['ai-estimates' => ['estimate_generation.review']]]);
        $rights->member->organizations()->updateExistingPivot($rights->organization->id, ['project_access_mode' => 'all_projects']);
        $project = Project::factory()->for($rights->organization)->create(['is_archived' => false]);
        AuthorizationContext::getProjectContext((int) $project->id, (int) $rights->organization->id);
        $work = ['item_type' => 'priced_work', 'name' => 'Concrete works', 'unit' => 'm3', 'quantity' => 8,
            'quantity_basis' => 'Ведомость объёмов', 'source_refs' => [], 'confidence' => 0.5,
            'total_cost' => 120000, 'normative_search_key' => 'foundation|concrete|m3',
            'validation_flags' => ['possible_duplicate_work_item', 'requires_duplicate_review']];
        if ($verified) {
            $work['metadata'] = ['scope_entity_id' => 'entity:101', 'composition_work_key' => 'foundation.concrete'];
        }
        $draft = ['source_input_version' => 'sha256:'.str_repeat('a', 64), 'local_estimates' => [[
            'key' => 'local-1', 'title' => 'Локальная смета', 'scope_type' => 'earthworks', 'source_refs' => [],
            'sections' => [['key' => 'section-1', 'title' => 'Земляные работы',
                'work_items' => [['key' => 'work-1', ...$work], ['key' => 'work-2', ...$work]],
            ]],
        ]], 'problem_flags' => []];
        $draft = app(UniversalReviewSummaryProjector::class)->project($draft);
        $session = EstimateGenerationSession::query()->create(['organization_id' => $rights->organization->id,
            'project_id' => $project->id, 'user_id' => $rights->member->id, 'status' => 'estimate_review_required',
            'state_version' => 3, 'input_payload' => ['description' => 'Duplicate feedback replay'], 'draft_payload' => $draft]);
        $this->actingAs($rights->member);

        return [$rights, $session, ['request_id' => (string) Str::uuid(), 'state_version' => 3,
            'feedback_type' => 'duplicate_resolution', 'work_item_key' => 'work-2', 'payload' => ['action' => 'remove_item']]];
    }
}
