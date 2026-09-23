<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\QualityControl\Http\Requests\Mobile;

use App\BusinessModules\Features\QualityControl\Enums\QualityDefectSeverityEnum;
use App\BusinessModules\Features\QualityControl\Enums\QualityDefectStatusEnum;
use App\Http\Responses\MobileResponse;
use App\Models\User;
use App\Services\Mobile\MobileProjectAccessResolver;
use DomainException;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;

final class MobileQualityDefectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return match ($this->route()?->getActionMethod()) {
            'index' => [
                'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
                'status' => ['nullable', 'string', Rule::in(array_column(QualityDefectStatusEnum::cases(), 'value'))],
                'project_id' => ['nullable', 'integer'],
                'assigned_to' => ['nullable', 'integer'],
                'severity' => ['nullable', 'string', Rule::in(array_column(QualityDefectSeverityEnum::cases(), 'value'))],
                'overdue' => ['nullable', 'boolean'],
                'sort_by' => ['nullable', 'string', Rule::in(['created_at', 'due_date', 'severity', 'status'])],
                'sort_dir' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
            ],
            'store' => [
                'project_id' => ['required', 'integer'],
                'contractor_id' => ['nullable', 'integer'],
                'assigned_to' => ['nullable', 'integer'],
                'title' => ['required', 'string', 'max:255'],
                'description' => ['nullable', 'string', 'max:5000'],
                'severity' => ['required', 'string', Rule::in(['minor', 'major', 'critical'])],
                'location_name' => ['nullable', 'string', 'max:255'],
                'schedule_task_id' => ['nullable', 'integer'],
                'construction_journal_entry_id' => ['nullable', 'integer'],
                'completed_work_id' => ['nullable', 'integer'],
                'due_date' => ['nullable', 'date'],
                'inspection_required' => ['required', 'boolean'],
                'metadata' => ['nullable', 'array'],
                'photos' => ['nullable', 'array'],
                'photos.*.type' => ['required_with:photos', 'string', Rule::in(['before', 'after', 'evidence', 'other'])],
                'photos.*.url' => ['nullable', 'required_without:photos.*.file', 'string', 'max:2000'],
                'photos.*.file' => ['nullable', 'required_without:photos.*.url', File::image()->max(10 * 1024)],
                'photos.*.caption' => ['nullable', 'string', 'max:255'],
                'photos.*.metadata' => ['nullable', 'array'],
            ],
            'start', 'verify' => ['comment' => ['nullable', 'string', 'max:1000']],
            'resolve' => [
                'comment' => ['nullable', 'string', 'max:1000'],
                'photos' => ['nullable', 'array'],
                'photos.*.type' => ['required_with:photos', 'string', Rule::in(['before', 'after', 'evidence', 'other'])],
                'photos.*.url' => ['nullable', 'required_without:photos.*.file', 'string', 'max:2000'],
                'photos.*.file' => ['nullable', 'required_without:photos.*.url', File::image()->max(10 * 1024)],
                'photos.*.caption' => ['nullable', 'string', 'max:255'],
                'photos.*.metadata' => ['nullable', 'array'],
            ],
            'reject' => ['comment' => ['required', 'string', 'max:1000']],
            default => [],
        };
    }

    public function messages(): array
    {
        return [
            'project_id.required' => trans_message('quality_control.validation.project_required'),
            'project_id.integer' => trans_message('quality_control.validation.project_invalid'),
            'assigned_to.integer' => trans_message('quality_control.validation.assignee_invalid'),
            'status.in' => trans_message('quality_control.validation.status_invalid'),
            'title.required' => trans_message('quality_control.validation.title_required'),
            'severity.required' => trans_message('quality_control.validation.severity_required'),
            'severity.in' => trans_message('quality_control.validation.severity_invalid'),
            'inspection_required.required' => trans_message('quality_control.validation.inspection_required'),
            'overdue.boolean' => trans_message('quality_control.validation.overdue_invalid'),
            'sort_by.in' => trans_message('quality_control.validation.sort_invalid'),
            'sort_dir.in' => trans_message('quality_control.validation.sort_invalid'),
            'comment.required' => trans_message('quality_control.validation.comment_required'),
            'photos.*.type.required_with' => trans_message('quality_control.validation.photo_type_required'),
            'photos.*.type.in' => trans_message('quality_control.validation.photo_type_required'),
            'photos.*.url.required_without' => trans_message('quality_control.validation.photo_required'),
            'photos.*.file.required_without' => trans_message('quality_control.validation.photo_required'),
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->has('project_id') || $this->input('project_id') === null) {
                return;
            }
            $projectId = $this->input('project_id');

            $user = $this->user();
            if (! $user instanceof User) {
                $validator->errors()->add('project_id', trans_message('quality_control.errors.project_not_found'));

                return;
            }

            try {
                app(MobileProjectAccessResolver::class)->assert(
                    $user,
                    (int) $this->attributes->get('current_organization_id'),
                    (int) $projectId,
                    trans_message('quality_control.errors.project_not_found'),
                );
            } catch (DomainException $exception) {
                $validator->errors()->add('project_id', $exception->getMessage());
            }
        }];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(MobileResponse::error(
            trans_message('quality_control.errors.validation_failed'),
            422,
            $validator->errors(),
        ));
    }
}
