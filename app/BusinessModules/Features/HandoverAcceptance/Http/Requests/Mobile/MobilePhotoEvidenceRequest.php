<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\HandoverAcceptance\Http\Requests\Mobile;

use App\Http\Responses\MobileResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

abstract class MobilePhotoEvidenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function photosRules(): array
    {
        return [
            'photos' => ['sometimes', 'array', 'max:5'],
            'photos.*' => ['required', 'image', 'mimes:jpg,jpeg,png', 'max:10240'],
        ];
    }

    public function messages(): array
    {
        return [
            'title.required' => trans_message('handover_acceptance.validation.title_required'),
            'severity.required' => trans_message('handover_acceptance.validation.severity_required'),
            'severity.in' => trans_message('handover_acceptance.validation.severity_invalid'),
            'create_quality_defect.required' => trans_message('handover_acceptance.validation.create_quality_defect_required'),
            'quality_defect_inspection_required.required_if' => trans_message('handover_acceptance.validation.quality_defect_inspection_required'),
            'resolution_comment.required' => trans_message('handover_acceptance.validation.resolution_comment_required'),
            'reason.required' => trans_message('handover_acceptance.validation.reason_required'),
            'status.required' => trans_message('handover_acceptance.validation.checklist_status_required'),
            'status.in' => trans_message('handover_acceptance.validation.checklist_status_invalid'),
            'comment.required_if' => trans_message('handover_acceptance.validation.checklist_rejection_comment_required'),
            'photos.max' => trans_message('handover_acceptance.validation.photos_limit'),
            'photos.*.image' => trans_message('handover_acceptance.validation.photo_invalid'),
            'photos.*.mimes' => trans_message('handover_acceptance.validation.photo_invalid'),
            'photos.*.max' => trans_message('handover_acceptance.validation.photo_too_large'),
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(MobileResponse::error(
            trans_message('handover_acceptance.errors.validation_failed'),
            422,
            $validator->errors()->toArray(),
        ));
    }
}
