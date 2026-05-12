<?php

declare(strict_types=1);

namespace App\Services\Auth;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

class DeviceFingerprintService
{
    public function fingerprint(Request $request): string
    {
        $parts = [
            Str::lower((string) $request->header('X-Device-Fingerprint', '')),
            Str::lower((string) $request->userAgent()),
            Str::lower((string) $request->header('Accept-Language')),
            Str::lower((string) $request->header('Sec-CH-UA-Platform')),
            Str::lower((string) $request->header('Sec-CH-UA-Mobile')),
        ];

        return hash('sha256', implode('|', array_filter($parts)));
    }

    public function deviceName(Request $request): string
    {
        $userAgent = (string) $request->userAgent();
        $deviceName = trim($this->platform($userAgent) . ', ' . $this->browser($userAgent), ' ,');

        return $deviceName !== '' ? $deviceName : trans_message('auth.security_unknown_device');
    }

    private function platform(string $userAgent): string
    {
        return match (true) {
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'Macintosh') || str_contains($userAgent, 'Mac OS') => 'macOS',
            str_contains($userAgent, 'iPhone') || str_contains($userAgent, 'iPad') => 'iOS',
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => trans_message('auth.security_unknown_platform'),
        };
    }

    private function browser(string $userAgent): string
    {
        return match (true) {
            str_contains($userAgent, 'Edg/') => 'Edge',
            str_contains($userAgent, 'Chrome/') => 'Chrome',
            str_contains($userAgent, 'Firefox/') => 'Firefox',
            str_contains($userAgent, 'Safari/') => 'Safari',
            default => trans_message('auth.security_unknown_browser'),
        };
    }
}
