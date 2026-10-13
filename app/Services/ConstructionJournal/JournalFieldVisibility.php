<?php

declare(strict_types=1);

namespace App\Services\ConstructionJournal;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Project;
use App\Models\User;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\MissingValue;

final class JournalFieldVisibility
{
    public function __construct(private readonly AuthorizationService $authorization) {}

    public function capabilities(User $user, Project $project): array
    {
        return [
            'can_manage_contracts' => $this->allows($user, (int) $project->id, 'construction-journal.approve'),
            'can_view_financials' => $this->canViewFinancials($user, (int) $project->id),
        ];
    }

    public function canViewFinancials(User $user, int $projectId): bool
    {
        foreach (['budget-estimates.finance.view', 'contracts.amounts.view', 'finance.view_project_budget'] as $permission) {
            if ($this->allows($user, $projectId, $permission)) {
                return true;
            }
        }

        return false;
    }

    public function allows(User $user, int $projectId, string $permission): bool
    {
        return $this->authorization->can($user, $permission, [
            'organization_id' => (int) $user->current_organization_id,
            'project_id' => $projectId,
            'strict_project_scope' => true,
        ]);
    }

    public function journal(array $payload, User $user, Project $project): array
    {
        $capabilities = $this->capabilities($user, $project);

        return $this->filter($payload, $capabilities['can_manage_contracts'], $capabilities['can_view_financials']) + $capabilities;
    }

    public function resource(array $payload, mixed $user, int $projectId): array
    {
        $contracts = $user instanceof User && $this->allows($user, $projectId, 'contracts.view');
        $finance = $user instanceof User && $this->canViewFinancials($user, $projectId);

        return $this->filter($payload, $contracts, $finance) + ['can_view_contracts' => $contracts, 'can_view_financials' => $finance];
    }

    public function filter(array $payload, bool $contracts, bool $finance): array
    {
        $result = [];
        foreach ($payload as $key => $value) {
            $name = (string) $key;
            if (! $contracts && (str_contains(strtolower($name), 'contract') || str_contains(strtolower($name), 'coverage') || in_array($name, ['unassigned_rows', 'unassigned_quantity'], true))) {
                continue;
            }
            if (! $finance && (preg_match('/price|cost|amount|profit|overhead|vat|earned_value|budget|indices|totals|import_diagnostics|regional_price_context/i', $name)
                || in_array($name, ['metadata', 'additional_info', 'justification'], true))) {
                continue;
            }
            if ($value instanceof MissingValue || ($value instanceof JsonResource && $value->resource instanceof MissingValue)) {
                continue;
            }
            if ($value instanceof JsonResource) {
                $value = $value->resolve(request());
            } elseif ($value instanceof Arrayable) {
                $value = $value->toArray();
            }
            $result[$key] = is_array($value) ? $this->filter($value, $contracts, $finance) : $value;
        }

        return $result;
    }
}
