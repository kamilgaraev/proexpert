<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Mobile;

use App\BusinessModules\Features\DesignManagement\Http\Requests\StoreDesignProjectIssueRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;

final class MobileDesignManagementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->route('clientId') !== null) {
            $this->merge(['client_id' => $this->route('clientId')]);
        }
        $aliases = ['model_version_id' => 'version_id', 'express_id' => 'bim_element_id', 'view_state' => 'camera', 'q' => 'search'];
        foreach ($aliases as $source => $target) {
            if ($this->has($source) && ! $this->has($target)) {
                $this->merge([$target => $target === 'bim_element_id' ? (string) $this->input($source) : $this->input($source)]);
            }
        }
        if ($this->header('Idempotency-Key') !== null) {
            $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
        }
    }

    public function rules(): array
    {
        $action = $this->route()?->getActionMethod();
        $rules = [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'nullable', 'string', 'max:100'],
            'project_id' => [in_array($action, ['versions', 'packages', 'sets', 'issues', 'assignees', 'sessions', 'storeSession'], true) ? 'required' : 'sometimes', 'integer', 'min:1'],
            'version_id' => ['sometimes', 'integer', 'min:1'],
            'idempotency_key' => ['sometimes', 'string', 'regex:/^[A-Za-z0-9_-]{16,128}$/'],
        ];
        if ($action === 'storeIssue') {
            $rules = array_merge($rules, (new StoreDesignProjectIssueRequest())->rules(), [
                'project_id' => ['required', 'integer', 'min:1'],
                'bim_element_id' => ['nullable', 'string', 'regex:/^[0-9]{1,16}$/'],
                'elements.*.element_id' => ['required_with:elements', 'integer', 'min:1', 'max:9007199254740991'],
            ]);
        }
        if (in_array($action, ['issueAction', 'snapshot', 'photo', 'deleteSet'], true)) {
            $rules['expected_revision'] = ['required', 'integer', 'min:1'];
        }
        if ($action === 'issueAction') {
            $operation = $this->route('action');
            $rules += [
                'assignee_id' => [$operation === 'assign' ? 'required' : 'sometimes', 'integer', 'min:1'],
                'accepted' => [$operation === 'verify' ? 'required' : 'sometimes', 'boolean'],
                'active' => [$operation === 'blocking' ? 'required' : 'sometimes', 'boolean'],
                'reason' => ['nullable', 'string', 'max:1000'],
                'comment' => ['nullable', 'string', 'max:1000'],
            ];
        }
        if (in_array($action, ['snapshot', 'photo'], true)) {
            $rules += ['file' => ['required', File::image()->max(10 * 1024)], 'caption' => ['nullable', 'string', 'max:1000']];
        }
        if ($action === 'viewState') {
            $rules += ['client_id' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9_-]+$/'], 'revision' => ['sometimes', 'integer', 'min:1']];
        }
        if ($action === 'storeSession') {
            $rules += [
                'title' => ['required', 'string', 'max:255'],
                'model_set_revision_id' => ['required_without:model_set_id', 'integer', 'min:1', 'prohibits:model_set_id,model_set_revision'],
                'model_set_id' => ['required_without:model_set_revision_id', 'integer', 'min:1'],
                'model_set_revision' => ['required_without:model_set_revision_id', 'integer', 'min:1'],
            ];
        }
        if ($action === 'versions') {
            $rules['status'] = ['sometimes', 'nullable', Rule::in(['uploaded', 'current', 'superseded', 'archived', 'missing', 'queued', 'processing', 'ready', 'failed'])];
        }

        return $rules;
    }
}
