<?php

declare(strict_types=1);

namespace App\Http\Resources\Brigades;

use App\BusinessModules\Contractors\Brigades\Domain\Models\BrigadeMember;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin BrigadeMember */
class BrigadeMemberResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'full_name' => $this->full_name,
            'role' => $this->role,
            'phone' => $this->phone,
            'is_manager' => $this->is_manager,
            'is_active' => $this->is_active,
        ];
    }
}
