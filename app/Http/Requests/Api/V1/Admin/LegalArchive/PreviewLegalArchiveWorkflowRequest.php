<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\LegalArchive;

use Illuminate\Foundation\Http\FormRequest;

use function trans_message;

final class PreviewLegalArchiveWorkflowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'template_id' => ['missing'],
            'step_overrides' => ['missing'],
            'additional_steps' => ['missing'],
        ];
    }

    public function messages(): array
    {
        return array_fill_keys(
            ['template_id.missing', 'step_overrides.missing', 'additional_steps.missing'],
            trans_message('legal_archive.messages.workflow_preview_default_route_only'),
        );
    }
}
