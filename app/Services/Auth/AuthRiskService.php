<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use App\Models\UserAuthSession;
use App\Models\UserSecurityEvent;
use Illuminate\Http\Request;

class AuthRiskService
{
    public function score(User $user, Request $request, string $deviceFingerprint): array
    {
        $score = 0;
        $flags = [];
        $ip = $request->ip();
        $userAgent = (string) $request->userAgent();

        $knownDevice = UserAuthSession::query()
            ->where('user_id', $user->id)
            ->where('device_fingerprint', $deviceFingerprint)
            ->exists();

        if (!$knownDevice) {
            $score += 30;
            $flags[] = 'new_device';
        }

        $knownIp = UserAuthSession::query()
            ->where('user_id', $user->id)
            ->where('ip_address', $ip)
            ->exists();

        if ($ip && !$knownIp) {
            $score += 10;
            $flags[] = 'new_ip';
        }

        $recentIpCount = UserSecurityEvent::query()
            ->where('user_id', $user->id)
            ->where('created_at', '>=', now()->subHour())
            ->whereNotNull('ip_address')
            ->distinct('ip_address')
            ->count('ip_address');

        if ($recentIpCount >= 2) {
            $score += 25;
            $flags[] = 'many_recent_ips';
        }

        $recentAgentCount = UserSecurityEvent::query()
            ->where('user_id', $user->id)
            ->where('created_at', '>=', now()->subDay())
            ->whereNotNull('user_agent')
            ->distinct('user_agent')
            ->count('user_agent');

        if ($userAgent !== '' && $recentAgentCount >= 3) {
            $score += 25;
            $flags[] = 'many_recent_user_agents';
        }

        return [
            'score' => min($score, 100),
            'flags' => array_values(array_unique($flags)),
        ];
    }
}
