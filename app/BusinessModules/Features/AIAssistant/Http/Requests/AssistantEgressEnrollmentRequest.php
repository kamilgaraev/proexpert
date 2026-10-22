<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Http\Requests;

use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantEnrollmentService;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantSourceProjection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class AssistantEgressEnrollmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return match ($this->route()?->getActionMethod()) {
            'assign' => ['organization_id' => ['required', 'integer', 'min:1'], 'actor_id' => ['required', 'integer', 'min:1'],
                'project_id' => ['required', 'integer', 'min:1'], 'tools' => ['required', 'array', 'min:1', 'max:2'],
                'tools.*' => ['required', 'string', 'distinct', Rule::in(OrdinaryAssistantSourceProjection::TOOLS)]],
            'preview' => ['cohort_id' => ['required', 'uuid'], 'quote_id' => ['required', 'uuid'],
                'input' => ['required', 'array:message,conversation_id,profile,allow_actions,context,goal,desired_mode'],
                'input.message' => ['required', 'string', 'max:4000'], 'input.conversation_id' => ['nullable', 'integer', 'min:1'],
                'input.profile' => ['sometimes', Rule::in(['short', 'normal', 'detailed'])], 'input.allow_actions' => ['sometimes', 'boolean'],
                'input.context' => ['sometimes', 'array'], 'input.goal' => ['nullable', 'string', 'max:120'], 'input.desired_mode' => ['nullable', 'string', 'max:120'],
                'sources' => ['required', 'array', 'min:1', 'max:6'], 'sources.*' => ['required', 'array:tool,arguments'],
                'sources.*.tool' => ['required', 'string', Rule::in(OrdinaryAssistantSourceProjection::TOOLS)], 'sources.*.arguments' => ['required', 'array']],
            'confirm' => ['manifest_hash' => ['required', 'string', 'regex:/\A[0-9a-f]{64}\z/D'],
                'declaration' => ['required', Rule::in([OrdinaryAssistantEnrollmentService::DECLARATION])]],
            default => [],
        };
    }
}
