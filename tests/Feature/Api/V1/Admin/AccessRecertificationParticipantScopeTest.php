<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Core\AccessRecertification\Services\AccessRecertificationService;
use App\Http\Requests\Api\V1\Admin\AccessRecertification\AccessRecertificationCampaignRequest;
use App\Http\Requests\Api\V1\Admin\AccessRecertification\AccessRecertificationDecisionRequest;
use App\Http\Requests\Api\V1\Admin\AccessRecertification\AccessRecertificationExceptionDecisionRequest;
use App\Http\Requests\Api\V1\Admin\AccessRecertification\AccessRecertificationReassignRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class AccessRecertificationParticipantScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_campaign_request_limits_participants_to_active_users_of_current_organization(): void
    {
        $context = AdminApiTestContext::create();
        $foreignContext = AdminApiTestContext::create();

        $validPayload = [
            'name' => 'Квартальная проверка доступов',
            'owner_user_id' => $context->user->id,
            'escalation_user_id' => $context->user->id,
            'due_at' => now()->addWeek()->toDateString(),
            'type' => 'risk_based',
            'risk_mode' => 'risk_based',
            'scope' => ['risk_levels' => ['high', 'critical']],
        ];

        $request = new AccessRecertificationCampaignRequest();
        $request->attributes->set('current_organization_id', $context->organization->id);

        $this->assertTrue(Validator::make($validPayload, $request->rules())->passes());

        $foreignOwner = Validator::make([
            ...$validPayload,
            'owner_user_id' => $foreignContext->user->id,
        ], $request->rules());
        $this->assertTrue($foreignOwner->fails());
        $this->assertArrayHasKey('owner_user_id', $foreignOwner->errors()->toArray());

        $foreignEscalation = Validator::make([
            ...$validPayload,
            'escalation_user_id' => $foreignContext->user->id,
        ], $request->rules());
        $this->assertTrue($foreignEscalation->fails());
        $this->assertArrayHasKey('escalation_user_id', $foreignEscalation->errors()->toArray());

        $foreignScopedUser = Validator::make([
            ...$validPayload,
            'scope' => [
                'risk_levels' => ['high', 'critical'],
                'user_ids' => [$foreignContext->user->id],
            ],
        ], $request->rules());
        $this->assertTrue($foreignScopedUser->fails());
        $this->assertArrayHasKey('scope.user_ids.0', $foreignScopedUser->errors()->toArray());
    }

    public function test_decision_and_reassign_requests_reject_foreign_participants(): void
    {
        $context = AdminApiTestContext::create();
        $foreignContext = AdminApiTestContext::create();

        $decisionRequest = new AccessRecertificationDecisionRequest();
        $decisionRequest->attributes->set('current_organization_id', $context->organization->id);
        $decisionValidator = Validator::make([
            'decision' => 'revoke',
            'revoke_executor_user_id' => $foreignContext->user->id,
        ], $decisionRequest->rules());

        $this->assertTrue($decisionValidator->fails());
        $this->assertArrayHasKey('revoke_executor_user_id', $decisionValidator->errors()->toArray());

        $reassignRequest = new AccessRecertificationReassignRequest();
        $reassignRequest->attributes->set('current_organization_id', $context->organization->id);
        $reassignValidator = Validator::make([
            'reviewer_user_id' => $foreignContext->user->id,
        ], $reassignRequest->rules());

        $this->assertTrue($reassignValidator->fails());
        $this->assertArrayHasKey('reviewer_user_id', $reassignValidator->errors()->toArray());
    }

    public function test_service_rejects_foreign_campaign_participant_outside_http_layer(): void
    {
        $context = AdminApiTestContext::create();
        $foreignContext = AdminApiTestContext::create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('participant_outside_organization');

        app(AccessRecertificationService::class)->createCampaign(
            (int) $context->organization->id,
            $context->user,
            [
                'name' => 'Недопустимая кампания',
                'owner_user_id' => $foreignContext->user->id,
                'due_at' => now()->addWeek()->toDateString(),
            ],
        );
    }

    public function test_exception_decision_requires_a_reason_and_explicit_confirmation(): void
    {
        $request = new AccessRecertificationExceptionDecisionRequest();

        $missingReason = Validator::make([
            'status' => 'approved',
            'confirmation' => true,
        ], $request->rules());
        $this->assertTrue($missingReason->fails());
        $this->assertArrayHasKey('reason', $missingReason->errors()->toArray());

        $missingConfirmation = Validator::make([
            'status' => 'rejected',
            'reason' => 'Независимая проверка завершена',
        ], $request->rules());
        $this->assertTrue($missingConfirmation->fails());
        $this->assertArrayHasKey('confirmation', $missingConfirmation->errors()->toArray());
    }
}
