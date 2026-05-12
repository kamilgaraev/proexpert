<?php

declare(strict_types=1);

namespace App\Services\OneCExchange;

use App\Models\OneCExchangeToken;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class OneCTokenService
{
    public function createToken(int $organizationId, string $label): array
    {
        $plainToken = 'ph_1c_' . Str::random(64);

        $token = OneCExchangeToken::create([
            'organization_id' => $organizationId,
            'label' => $label,
            'token_hash' => hash('sha256', $plainToken),
        ]);

        return [
            'plain_token' => $plainToken,
            'token' => $token,
        ];
    }

    public function validateToken(string $plainToken): ?OneCExchangeToken
    {
        $token = OneCExchangeToken::query()
            ->where('token_hash', hash('sha256', $plainToken))
            ->whereNull('revoked_at')
            ->first();

        if ($token !== null) {
            $token->forceFill(['last_used_at' => now()])->save();
        }

        return $token;
    }

    public function revokeToken(int $organizationId, int $tokenId): bool
    {
        return OneCExchangeToken::query()
            ->where('organization_id', $organizationId)
            ->whereKey($tokenId)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]) === 1;
    }

    public function listTokens(int $organizationId): Collection
    {
        return OneCExchangeToken::query()
            ->where('organization_id', $organizationId)
            ->orderByDesc('created_at')
            ->get();
    }
}
