<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Http\Controllers;

use App\BusinessModules\Features\AIAssistant\Models\AssistantDocumentProcessingPolicy;
use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantDocumentProcessingPolicyService;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Throwable;

final class AssistantDocumentProcessingController extends Controller
{
    public function __construct(private readonly AssistantDocumentProcessingPolicyService $policies) {}

    public function assign(Request $request, int $document): JsonResponse
    {
        $data = $request->validate(['project_id' => ['required', 'integer', 'min:1'],
            'actor_user_id' => ['required', 'integer', 'min:1'],
            'policy_version' => ['required', Rule::in([AssistantDocumentProcessingPolicyService::VERSION])]]);
        $operator = $request->user();
        if (! $operator instanceof User) { return AdminResponse::error(trans_message('ai_assistant.unauthorized'), 401); }
        try {
            return AdminResponse::success($this->payload($this->policies->assign($operator,
                (int) $request->attributes->get('current_organization_id', $operator->current_organization_id),
                (int) $data['project_id'], $document, (int) $data['actor_user_id'])));
        } catch (Throwable) { return AdminResponse::error(trans_message('ai_assistant.access_denied'), 403); }
    }

    public function revoke(Request $request, int $document): JsonResponse
    {
        $operator = $request->user();
        if (! $operator instanceof User) { return AdminResponse::error(trans_message('ai_assistant.unauthorized'), 401); }
        try {
            return AdminResponse::success($this->payload($this->policies->revoke($operator,
                (int) $request->attributes->get('current_organization_id', $operator->current_organization_id), $document)));
        } catch (Throwable) { return AdminResponse::error(trans_message('ai_assistant.access_denied'), 403); }
    }

    private function payload(AssistantDocumentProcessingPolicy $policy): array
    {
        return ['document_id' => $policy->document_id, 'organization_id' => $policy->organization_id,
            'project_id' => $policy->project_id, 'policy_version' => $policy->policy_version,
            'generation' => $policy->generation, 'state' => $policy->state];
    }
}
