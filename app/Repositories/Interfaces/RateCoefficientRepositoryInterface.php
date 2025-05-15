<?php

namespace App\Repositories\Interfaces;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface RateCoefficientRepositoryInterface extends BaseRepositoryInterface
{
    public function getCoefficientsForOrganizationPaginated(
        int $organizationId,
        int $perPage = 15,
        array $filters = [],
        string $sortBy = 'created_at',
        string $sortDirection = 'desc'
    ): LengthAwarePaginator;

    public function findApplicableCoefficients(
        int $organizationId,
        string $appliesTo, // RateCoefficientAppliesToEnum value
        ?string $scope = null,    // RateCoefficientScopeEnum value
        array $contextualIds = [],
        ?string $date = null
    ): Collection;
} 