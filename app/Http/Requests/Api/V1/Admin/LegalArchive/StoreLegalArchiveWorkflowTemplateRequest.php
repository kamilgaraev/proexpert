<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\LegalArchive;

use App\Domain\Authorization\Models\OrganizationCustomRole;
use App\Domain\Authorization\Services\RoleScanner;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Validator;

use function trans_message;

final class StoreLegalArchiveWorkflowTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:128', 'regex:/^[a-z0-9_.-]+$/'],
            'name' => ['required', 'string', 'max:255'],
            'steps' => ['required', 'array', 'min:1', 'max:100'],
            'steps.*.key' => ['required', 'string', 'max:128'],
            'steps.*.label' => ['required', 'string', 'max:255'],
            'steps.*.sequence' => ['required', 'integer', 'min:1'],
            'steps.*.parallel_group' => ['nullable', 'string', 'max:128'],
            'steps.*.policy_key' => ['nullable', 'string', 'max:128'],
            'steps.*.actor_type' => ['required', 'string', 'max:64'],
            'steps.*.actor_reference' => ['required', 'string', 'max:191'],
            'steps.*.required' => ['sometimes', 'boolean'],
            'steps.*.due_in_hours' => ['nullable', 'integer', 'min:1', 'max:8760'],
            'steps.*.settings' => ['sometimes', 'array', 'max:50'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $organizationId = (int) $this->user()?->current_organization_id;
            if ($organizationId < 1) {
                return;
            }

            foreach ((array) $this->input('steps', []) as $index => $step) {
                if (! is_array($step)) {
                    continue;
                }
                $actorType = trim((string) ($step['actor_type'] ?? ''));
                $reference = trim((string) ($step['actor_reference'] ?? ''));
                if ($actorType === 'user') {
                    $isActiveMember = ctype_digit($reference) && DB::table('organization_user')
                        ->where('organization_id', $organizationId)
                        ->where('user_id', (int) $reference)
                        ->where('is_active', true)
                        ->exists();
                    if (! $isActiveMember) {
                        $validator->errors()->add("steps.{$index}.actor_reference", trans_message('legal_archive.messages.workflow_assignee_invalid'));
                    }
                }
                if ($actorType === 'role') {
                    $isSystemRole = $reference !== '' && app(RoleScanner::class)->roleExists($reference);
                    $isOrganizationRole = $reference !== '' && (new OrganizationCustomRole)
                        ->where('organization_id', $organizationId)
                        ->where('slug', $reference)
                        ->active()
                        ->exists();
                    if (! $isSystemRole && ! $isOrganizationRole) {
                        $validator->errors()->add("steps.{$index}.actor_reference", trans_message('legal_archive.messages.workflow_assignee_invalid'));
                    }
                }
            }
        });
    }
}
