<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use App\Models\Organization;
use App\Models\User;

class OrganizationProfileUpdated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Organization $organization,
        public string $field,
        public mixed $oldValue,
        public mixed $newValue,
        public ?User $updatedBy = null
    ) {}
}
