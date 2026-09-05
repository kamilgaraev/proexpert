<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\AuthSessionStatus;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class UserAuthSessionQuery
{
    public function paginate(User $user, string $group = 'all', int $perPage = 20, int $page = 1): LengthAwarePaginator
    {
        $query = $user->authSessions();

        if ($group === 'active') {
            $query->where('status', AuthSessionStatus::Active->value)->whereNull('revoked_at');
        } elseif ($group === 'history') {
            $query->where(function ($history): void {
                $history->where('status', '!=', AuthSessionStatus::Active->value)
                    ->orWhereNotNull('revoked_at');
            });
        }

        return $query->orderByDesc('last_seen_at')->orderByDesc('id')
            ->paginate(max(1, min(50, $perPage)), ['*'], 'page', max(1, $page));
    }
}
