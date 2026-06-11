<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Crm\Http\Resources\Concerns;

use Illuminate\Http\Request;

trait ResolvesCrmResourceState
{
    private function canViewAmounts(Request $request): bool
    {
        $user = $request->user();

        if ($user === null) {
            return false;
        }

        return $user->can('crm.amounts.view', [
            'organization_id' => (int) $this->organization_id,
        ]);
    }

    private function userSummary(mixed $user): ?array
    {
        if ($user === null) {
            return null;
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
        ];
    }

    private function referenceSummary(mixed $model): ?array
    {
        if ($model === null) {
            return null;
        }

        return [
            'id' => $model->id,
            'code' => $model->code ?? null,
            'label' => $model->label ?? null,
        ];
    }
}
