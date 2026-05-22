<?php

namespace App\Http\Resources\Api\V1\Landing\ContractorInvitation;

use App\Http\Resources\PaginatedResourceCollection;
use Illuminate\Http\Request;

class ContractorInvitationCollection extends PaginatedResourceCollection
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
            ],
        ];
    }
}
