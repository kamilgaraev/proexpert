<?php

namespace App\Http\Resources\Api\V1\Admin\ContractorInvitation;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class ContractorInvitationCollection extends ResourceCollection
{
    public $collects = ContractorInvitationResource::class;

    public function toArray(Request $request): array
    {
        return [
            'data' => $this->collection,
            'pagination' => [
                'current_page' => $this->currentPage(),
                'last_page' => $this->lastPage(),
                'per_page' => $this->perPage(),
                'total' => $this->total(),
                'from' => $this->firstItem(),
                'to' => $this->lastItem(),
                'has_more_pages' => $this->hasMorePages(),
                'next_page_url' => $this->nextPageUrl(),
                'prev_page_url' => $this->previousPageUrl(),
            ],
            'meta' => [
                'count_by_status' => $this->getCountByStatus(),
            ],
        ];
    }

    protected function getCountByStatus(): array
    {
        $statusCounts = $this->collection->groupBy('status')->map->count();
        
        return [
            'pending' => $statusCounts->get('pending', 0),
            'accepted' => $statusCounts->get('accepted', 0),
            'declined' => $statusCounts->get('declined', 0),
            'expired' => $statusCounts->get('expired', 0),
        ];
    }
}