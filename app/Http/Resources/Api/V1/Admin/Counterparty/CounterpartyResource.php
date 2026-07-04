<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin\Counterparty;

use App\Enums\CounterpartyRoleEnum;
use App\Http\Resources\ModelJsonResource;
use App\Models\Counterparty;
use Illuminate\Http\Request;

class CounterpartyResource extends ModelJsonResource
{
    public function toArray(Request $request): array
    {
        $counterparty = $this->typedResource(Counterparty::class);
        $roles = is_array($counterparty->roles) ? $counterparty->roles : [];

        return [
            'id' => $counterparty->id,
            'organization_id' => $counterparty->organization_id,
            'linked_organization_id' => $counterparty->linked_organization_id,
            'linked_organization' => $this->whenLoaded('linkedOrganization', fn () => [
                'id' => $counterparty->linkedOrganization?->id,
                'name' => $counterparty->linkedOrganization?->name,
            ]),
            'name' => $counterparty->name,
            'legal_name' => $counterparty->legal_name,
            'inn' => $counterparty->inn,
            'kpp' => $counterparty->kpp,
            'ogrn' => $counterparty->ogrn,
            'email' => $counterparty->email,
            'phone' => $counterparty->phone,
            'contact_person' => $counterparty->contact_person,
            'legal_address' => $counterparty->legal_address,
            'postal_address' => $counterparty->postal_address,
            'bank_details' => $counterparty->bank_details,
            'roles' => $roles,
            'role_labels' => array_values(array_filter(array_map(
                static fn (string $role): ?string => CounterpartyRoleEnum::tryFrom($role)?->label(),
                $roles
            ))),
            'source' => $counterparty->source,
            'is_active' => $counterparty->is_active,
            'created_at' => $counterparty->created_at?->toIso8601String(),
            'updated_at' => $counterparty->updated_at?->toIso8601String(),
        ];
    }
}
