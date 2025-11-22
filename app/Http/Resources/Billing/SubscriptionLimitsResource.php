<?php

namespace App\Http\Resources\Billing;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SubscriptionLimitsResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'has_subscription' => $this->resource['has_subscription'],
            'subscription' => $this->resource['subscription'],
            'limits' => [
                'foremen' => [
                    'limit' => $this->resource['limits']['foremen']['limit'],
                    'used' => $this->resource['limits']['foremen']['used'],
                    'remaining' => $this->resource['limits']['foremen']['remaining'],
                    'percentage_used' => $this->resource['limits']['foremen']['percentage_used'],
                    'is_unlimited' => $this->resource['limits']['foremen']['is_unlimited'],
                    'status' => $this->getLimitStatus($this->resource['limits']['foremen']),
                ],
                'projects' => [
                    'limit' => $this->resource['limits']['projects']['limit'],
                    'used' => $this->resource['limits']['projects']['used'],
                    'remaining' => $this->resource['limits']['projects']['remaining'],
                    'percentage_used' => $this->resource['limits']['projects']['percentage_used'],
                    'is_unlimited' => $this->resource['limits']['projects']['is_unlimited'],
                    'status' => $this->getLimitStatus($this->resource['limits']['projects']),
                ],
                'users' => [
                    'limit' => $this->resource['limits']['users']['limit'] ?? null,
                    'used' => $this->resource['limits']['users']['used'] ?? 0,
                    'remaining' => $this->resource['limits']['users']['remaining'] ?? null,
                    'percentage_used' => $this->resource['limits']['users']['percentage_used'] ?? 0,
                    'is_unlimited' => $this->resource['limits']['users']['is_unlimited'] ?? false,
                    'status' => isset($this->resource['limits']['users']) ? $this->getLimitStatus($this->resource['limits']['users']) : 'normal',
                ],
                'storage' => [
                    'limit_gb' => $this->resource['limits']['storage']['limit_gb'],
                    'used_gb' => $this->resource['limits']['storage']['used_gb'],
                    'remaining_gb' => $this->resource['limits']['storage']['remaining_gb'],
                    'percentage_used' => $this->resource['limits']['storage']['percentage_used'],
                    'is_unlimited' => $this->resource['limits']['storage']['is_unlimited'],
                    'status' => $this->getLimitStatus($this->resource['limits']['storage']),
                ],
            ],
            'features' => $this->resource['features'] ?? [],
            'warnings' => $this->resource['warnings'] ?? [],
            'upgrade_required' => $this->resource['upgrade_required'] ?? false,
        ];
    }

    private function getLimitStatus(array $limitData): string
    {
        if ($limitData['is_unlimited']) {
            return 'unlimited';
        }
        
        $percentage = $limitData['percentage_used'];
        
        if ($percentage >= 100) {
            return 'exceeded';
        } elseif ($percentage >= 80) {
            return 'warning';
        } elseif ($percentage >= 60) {
            return 'approaching';
        } else {
            return 'normal';
        }
    }
} 