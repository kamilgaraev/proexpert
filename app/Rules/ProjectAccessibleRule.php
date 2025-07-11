<?php

namespace App\Rules;

use Illuminate\Contracts\Validation\Rule as RuleContract;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ProjectAccessibleRule implements RuleContract
{
    public function passes($attribute, $value): bool
    {
        $currentOrgId = Auth::user()?->current_organization_id;
        if (!$currentOrgId) {
            return false;
        }

        return DB::table('projects')
            ->where('id', $value)
            ->where(function ($q) use ($currentOrgId) {
                $q->where('organization_id', $currentOrgId)
                  ->orWhereExists(function ($sub) use ($currentOrgId) {
                      $sub->select(DB::raw(1))
                          ->from('project_organization as po')
                          ->whereColumn('po.project_id', 'projects.id')
                          ->where('po.organization_id', $currentOrgId);
                  });
            })
            ->exists();
    }

    public function message(): string
    {
        return 'Этот проект недоступен для вашей организации.';
    }
} 