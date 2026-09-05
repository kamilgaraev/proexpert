<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Services;

use App\BusinessModules\Features\Procurement\Models\ProcurementApproval;

final class ProcurementApprovalSummaryService
{
    public function forOrganization(int $organizationId, ?string $reasonCode = null): array
    {
        $query = ProcurementApproval::query()->forOrganization($organizationId);
        if ($reasonCode !== null) {
            $query->where('reason_code', $reasonCode);
        }

        $counts = $query->selectRaw('status, COUNT(*) AS aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return [
            'pending' => (int) $counts->get('pending', 0),
            'approved' => (int) $counts->get('approved', 0),
            'rejected' => (int) $counts->get('rejected', 0),
            'cancelled' => (int) $counts->get('cancelled', 0),
        ];
    }
}
