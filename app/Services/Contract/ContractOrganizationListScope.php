<?php

declare(strict_types=1);

namespace App\Services\Contract;

use Illuminate\Database\Eloquent\Builder;

final class ContractOrganizationListScope
{
    public function apply(Builder $query, int $organizationId, array $filters, callable $legacyScope): void
    {
        $statuses = array_values(array_filter((array) ($filters['status'] ?? [])));
        $query->where(function (Builder $scope) use ($organizationId, $statuses, $legacyScope): void {
            $scope->where(function (Builder $modern) use ($organizationId, $statuses): void {
                $modern->whereHas('organizationViews', function (Builder $view) use ($organizationId, $statuses): void {
                    $view->where('organization_id', $organizationId)->whereNull('access_revoked_at')
                        ->where(function (Builder $placement) use ($statuses): void {
                            $legalStatuses = array_values(array_diff($statuses, ['archived']));
                            if ($statuses === [] || $legalStatuses !== []) {
                                $placement->where(function (Builder $active) use ($legalStatuses): void {
                                    $active->where('visibility', 'active');
                                    if ($legalStatuses !== []) {
                                        $active->whereIn('contracts.status', $legalStatuses);
                                    }
                                });
                            }
                            if (in_array('archived', $statuses, true)) {
                                $placement->orWhere('visibility', 'archived');
                            }
                        });
                });
            })->orWhere(function (Builder $legacy) use ($statuses, $legacyScope): void {
                $legacy->whereDoesntHave('organizationViews');
                $legacyScope($legacy);
                if ($statuses !== []) {
                    $legacy->whereIn('contracts.status', $statuses);
                }
            });
        });
    }
}
