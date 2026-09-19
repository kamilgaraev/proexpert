<?php

declare(strict_types=1);

namespace App\Rules;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

final class AvailableContractProject
{
    public static function forOrganization(int $organizationId): Exists
    {
        return Rule::exists('projects', 'id')->where(function ($query) use ($organizationId): void {
            $query->where(function ($scope) use ($organizationId): void {
                $scope->where('organization_id', $organizationId)
                    ->orWhereIn('id', function ($participants) use ($organizationId): void {
                        $participants->select('project_id')
                            ->from('project_organization')
                            ->where('organization_id', $organizationId)
                            ->where('is_active', true);
                    });
            });
        });
    }
}
