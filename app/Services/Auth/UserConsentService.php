<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use App\Models\UserConsent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

final class UserConsentService
{
    public function record(User $user, string $type, string $version, CarbonImmutable $acceptedAt): UserConsent
    {
        $ip = request()->ip();
        $metadata = [
            'source' => 'landing_registration',
            'ip_fingerprint' => is_string($ip)
                ? hash_hmac('sha256', $ip, (string) config('app.key'))
                : null,
            'user_agent' => Str::limit((string) request()->userAgent(), 255, ''),
        ];

        return UserConsent::query()->firstOrCreate(
            [
                'user_id' => $user->id,
                'type' => $type,
                'version' => $version,
            ],
            [
                'accepted_at' => $acceptedAt,
                'metadata' => $metadata,
            ],
        );
    }
}
