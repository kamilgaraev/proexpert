<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\CompletedWork;

use App\DTOs\CompletedWork\CompletedWorkDTO;
use App\DTOs\CompletedWork\CompletedWorkMaterialDTO;
use App\Http\Middleware\ProjectContextMiddleware;
use App\Models\Project;
use App\Models\CompletedWork;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

final class StoreCompletedWorkBulkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Auth::check();
    }

    public function rules(): array
    {
        $projectId = (int) $this->route('project');
        $organizationId = (int) (ProjectContextMiddleware::getProject($this)?->organization_id
            ?? Auth::user()?->current_organization_id);

        return [
            'works' => ['required', 'array', 'min:1'],
            'works.*.work_type_id' => ['nullable', 'integer', Rule::exists('work_types', 'id')->where('organization_id', $organizationId)],
            'works.*.user_id' => ['nullable', 'integer'],
            'works.*.schedule_task_id' => ['nullable', 'integer'],
            'works.*.estimate_item_id' => ['nullable', 'integer'],
            'works.*.contract_id' => ['nullable', 'integer', Rule::exists('contracts', 'id')->where('organization_id', $organizationId)],
            'works.*.contractor_id' => ['nullable', 'integer', Rule::exists('contractors', 'id')->where('organization_id', $organizationId)],
            'works.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'works.*.completed_quantity' => ['nullable', 'numeric', 'min:0'],
            'works.*.price' => ['nullable', 'numeric', 'min:0'],
            'works.*.total_amount' => ['nullable', 'numeric', 'min:0'],
            'works.*.completion_date' => ['required', 'date_format:Y-m-d'],
            'works.*.notes' => ['nullable', 'string'],
            'works.*.description' => ['nullable', 'string', 'max:65535'],
            'works.*.status' => ['nullable', 'string', 'in:draft,pending,in_review,confirmed,cancelled,rejected'],
            'works.*.work_origin_type' => ['nullable', 'string', 'in:manual,schedule,journal'],
            'works.*.planning_status' => ['nullable', 'string', 'in:planned,requires_schedule'],
            'works.*.additional_info' => ['nullable', 'array'],
            'works.*.materials' => ['nullable', 'array'],
            'works.*.materials.*.material_id' => ['required_with:works.*.materials', 'integer', Rule::exists('materials', 'id')->where('organization_id', $organizationId)],
            'works.*.materials.*.quantity' => ['required', 'numeric', 'min:0.0001'],
            'works.*.materials.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'works.*.materials.*.total_amount' => ['nullable', 'numeric', 'min:0'],
            'works.*.materials.*.notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function toDtos(): array
    {
        $project = ProjectContextMiddleware::getProject($this);
        $organizationId = (int) ($project?->organization_id ?? Auth::user()?->current_organization_id);
        $projectId = (int) $this->route('project');

        return array_map(function (array $work) use ($organizationId, $projectId): CompletedWorkDTO {
            $materials = isset($work['materials'])
                ? array_map(static fn (array $material): CompletedWorkMaterialDTO => CompletedWorkMaterialDTO::fromArray($material), $work['materials'])
                : null;

            return new CompletedWorkDTO(
                id: null,
                organization_id: $organizationId,
                project_id: $projectId,
                schedule_task_id: $work['schedule_task_id'] ?? null,
                estimate_item_id: $work['estimate_item_id'] ?? null,
                journal_entry_id: null,
                work_origin_type: $work['work_origin_type'] ?? CompletedWork::ORIGIN_MANUAL,
                planning_status: $work['planning_status'] ?? (($work['schedule_task_id'] ?? null) ? CompletedWork::PLANNING_PLANNED : CompletedWork::PLANNING_REQUIRES_SCHEDULE),
                contract_id: $work['contract_id'] ?? null,
                contractor_id: $work['contractor_id'] ?? null,
                work_type_id: $work['work_type_id'] ?? null,
                user_id: $work['user_id'] ?? null,
                quantity: (float) $work['quantity'],
                completed_quantity: isset($work['completed_quantity']) ? (float) $work['completed_quantity'] : null,
                price: isset($work['price']) ? (float) $work['price'] : null,
                total_amount: isset($work['total_amount']) ? (float) $work['total_amount'] : null,
                completion_date: Carbon::parse($work['completion_date']),
                notes: $work['notes'] ?? null,
                status: $work['status'] ?? 'draft',
                additional_info: $work['additional_info'] ?? null,
                materials: $materials,
                description: $work['description'] ?? null,
            );
        }, $this->validated('works'));
    }
}
