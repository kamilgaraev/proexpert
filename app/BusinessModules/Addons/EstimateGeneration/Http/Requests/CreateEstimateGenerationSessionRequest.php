<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Http\Requests;

use App\BusinessModules\Addons\EstimateGeneration\Enums\EstimateGenerationMode;
use App\Http\Responses\AdminResponse;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

use function trans_message;

class CreateEstimateGenerationSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $organizationId = $user?->current_organization_id;
        $project = $this->route('project');
        $projectId = is_object($project) && method_exists($project, 'getKey') ? $project->getKey() : $project;

        if ($user === null || $organizationId === null || ! is_numeric($projectId)) {
            return false;
        }

        return app(\App\Domain\Authorization\Services\AuthorizationService::class)->can(
            $user,
            'estimate_generation.create',
            ['organization_id' => (int) $organizationId, 'project_id' => (int) $projectId],
        );
    }

    public function rules(): array
    {
        return [
            'description' => ['nullable', 'string', 'max:10000'],
            'building_type' => ['nullable', 'string', 'max:255'],
            'generation_mode' => ['nullable', 'string', Rule::in(EstimateGenerationMode::values())],
            'region' => ['nullable', 'string', 'max:255'],
            'estimate_regional_price_version_id' => ['nullable', 'integer', 'exists:estimate_regional_price_versions,id'],
            'region_id' => ['nullable', 'integer', 'exists:estimate_regions,id'],
            'price_zone_id' => ['nullable', 'integer', 'exists:estimate_price_zones,id'],
            'period_id' => ['nullable', 'integer', 'exists:estimate_price_periods,id'],
            'normative_dataset_version' => ['nullable', 'string', 'max:100'],
            'business_date' => ['nullable', 'date_format:Y-m-d'],
            'normative_rerank_requested' => ['nullable', 'boolean'],
            'area' => ['nullable', 'numeric', 'min:0'],
            'parameters' => ['nullable', 'array'],
        ];
    }

    protected function failedAuthorization(): void
    {
        throw new \Illuminate\Http\Exceptions\HttpResponseException(
            AdminResponse::error(trans_message('estimate_generation.access_denied'), 403)
        );
    }
}
