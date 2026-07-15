<?php

declare(strict_types=1);

namespace App\Services\Billing;

use Illuminate\Http\Request;

final class YooKassaWebhookSourceResolver
{
    public function resolve(Request $request): ?string
    {
        $remoteAddress = trim((string) $request->server('REMOTE_ADDR'));

        if (filter_var($remoteAddress, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $trustedProxies = $this->configuredCidrs('services.yookassa.trusted_proxy_cidrs');
        $source = $remoteAddress;

        if ($trustedProxies !== [] && $this->belongsToAny($remoteAddress, $trustedProxies)) {
            $forwarded = array_values(array_filter(array_map(
                'trim',
                explode(',', (string) $request->headers->get('X-Forwarded-For', '')),
            )));

            for ($index = count($forwarded) - 1; $index >= 0; $index--) {
                $candidate = $forwarded[$index];

                if (filter_var($candidate, FILTER_VALIDATE_IP) === false) {
                    return null;
                }

                $source = $candidate;

                if (! $this->belongsToAny($candidate, $trustedProxies)) {
                    break;
                }
            }
        }

        return $this->belongsToAny(
            $source,
            $this->configuredCidrs('services.yookassa.webhook_source_cidrs'),
        ) ? $source : null;
    }

    private function configuredCidrs(string $key): array
    {
        $value = config($key, []);

        if (is_string($value)) {
            $value = explode(',', $value);
        }

        return array_values(array_filter(array_map(
            static fn (mixed $cidr): string => trim((string) $cidr),
            is_array($value) ? $value : [],
        )));
    }

    private function belongsToAny(string $address, array $cidrs): bool
    {
        foreach ($cidrs as $cidr) {
            if ($this->belongsTo($address, $cidr)) {
                return true;
            }
        }

        return false;
    }

    private function belongsTo(string $address, string $cidr): bool
    {
        [$network, $prefix] = array_pad(explode('/', $cidr, 2), 2, null);
        $addressBytes = @inet_pton($address);
        $networkBytes = @inet_pton($network);

        if ($addressBytes === false || $networkBytes === false || strlen($addressBytes) !== strlen($networkBytes)) {
            return false;
        }

        $bits = $prefix === null ? strlen($networkBytes) * 8 : filter_var($prefix, FILTER_VALIDATE_INT);

        if ($bits === false || $bits < 0 || $bits > strlen($networkBytes) * 8) {
            return false;
        }

        $wholeBytes = intdiv((int) $bits, 8);
        $remainingBits = (int) $bits % 8;

        if ($wholeBytes > 0 && substr($addressBytes, 0, $wholeBytes) !== substr($networkBytes, 0, $wholeBytes)) {
            return false;
        }

        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;

        return (ord($addressBytes[$wholeBytes]) & $mask) === (ord($networkBytes[$wholeBytes]) & $mask);
    }
}
