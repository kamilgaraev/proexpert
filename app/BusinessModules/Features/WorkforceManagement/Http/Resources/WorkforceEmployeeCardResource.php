<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class WorkforceEmployeeCardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = (array) $this->resource;
        $organizationId = (int) $request->attributes->get('current_organization_id');
        if (! app(\App\BusinessModules\Features\WorkforceManagement\Domain\HR\Services\HrAccess::class)->allows($organizationId, $request->user(), 'workforce.hr.private.read')) {
            foreach ($data['assignment_history'] ?? [] as $index => $history) {
                unset($history['basis_reference']);
                $data['assignment_history'][$index] = $history;
            }
        }
        return $data;
    }
}
