<?php

declare(strict_types=1);

namespace App\Broadcasting;

use App\Models\User;
use Illuminate\Http\Request;

final readonly class UserChannel
{
    public function __construct(private Request $request) {}

    public function join(User $user, int|string $id, string $interface): bool
    {
        $expectedInterface = match (true) {
            $this->request->is('api/v1/admin/*') => 'admin',
            $this->request->is('api/v1/landing/*') => 'lk',
            default => null,
        };

        return $expectedInterface !== null
            && $interface === $expectedInterface
            && (int) $user->id === (int) $id;
    }
}
