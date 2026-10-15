<?php

declare(strict_types=1);

namespace App\Services\Billing\Bank;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Throwable;

class TochkaWebhookVerifier
{
    public const PUBLIC_KEY_URL = 'https://enter.tochka.com/doc/openapi/static/keys/public';

    public function verify(string $jwt): array
    {
        if (strlen($jwt) > 65_536 || count($parts = explode('.', $jwt)) !== 3) {
            throw new InvalidArgumentException('tochka_webhook_invalid');
        }
        try {
            $header = json_decode($this->decode($parts[0]), true, 16, JSON_THROW_ON_ERROR);
            $payload = json_decode($this->decode($parts[1]), true, 32, JSON_THROW_ON_ERROR);
            if (! is_array($header) || ! is_array($payload) || ($header['alg'] ?? null) !== 'RS256'
                || isset($header['jku']) || isset($header['x5u']) || isset($header['crit'])) {
                throw new InvalidArgumentException;
            }
            $signature = $this->decode($parts[2]);
            if (openssl_verify($parts[0].'.'.$parts[1], $signature, $this->publicKey(), OPENSSL_ALGO_SHA256) !== 1) {
                throw new InvalidArgumentException;
            }
            foreach (['exp', 'nbf', 'iat'] as $claim) {
                if (isset($payload[$claim]) && ! is_int($payload[$claim])) {
                    throw new InvalidArgumentException;
                }
            }
            if ((isset($payload['exp']) && $payload['exp'] <= time())
                || (isset($payload['nbf']) && $payload['nbf'] > time() + 30)
                || (isset($payload['iat']) && $payload['iat'] > time() + 30)) {
                throw new InvalidArgumentException;
            }

            return $payload;
        } catch (Throwable) {
            throw new InvalidArgumentException('tochka_webhook_invalid');
        }
    }

    protected function publicKey(): string
    {
        return Cache::remember('billing:tochka:public-key:v1', 300, function (): string {
            $response = Http::acceptJson()->connectTimeout(3)->timeout(8)
                ->withOptions(['allow_redirects' => false, 'progress' => static function ($total, $received): void {
                    if ($total > 16_384 || $received > 16_384) {
                        throw new InvalidArgumentException('tochka_public_key_invalid');
                    }
                }])->get(self::PUBLIC_KEY_URL);
            if (! $response->successful() || strlen($response->body()) > 16_384) {
                throw new InvalidArgumentException('tochka_public_key_unavailable');
            }
            $key = $response->json();
            if (! is_array($key) || ($key['kty'] ?? null) !== 'RSA'
                || ! is_string($key['n'] ?? null) || ! is_string($key['e'] ?? null)) {
                throw new InvalidArgumentException('tochka_public_key_invalid');
            }
            $rsa = $this->der(0x30, $this->integer($this->decode($key['n'])).$this->integer($this->decode($key['e'])));
            $algorithm = hex2bin('300d06092a864886f70d0101010500');
            $der = $this->der(0x30, $algorithm.$this->der(0x03, "\0".$rsa));

            return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END PUBLIC KEY-----\n";
        });
    }

    private function decode(string $value): string
    {
        if ($value === '' || preg_match('/\A[A-Za-z0-9_-]+\z/', $value) !== 1) {
            throw new InvalidArgumentException;
        }
        $decoded = base64_decode(strtr($value, '-_', '+/').str_repeat('=', (4 - strlen($value) % 4) % 4), true);
        if ($decoded === false) {
            throw new InvalidArgumentException;
        }

        return $decoded;
    }

    private function integer(string $value): string
    {
        $value = ltrim($value, "\0");
        if ($value === '') {
            throw new InvalidArgumentException;
        }

        return $this->der(0x02, (ord($value[0]) > 127 ? "\0" : '').$value);
    }

    private function der(int $tag, string $value): string
    {
        $length = strlen($value);
        $encoded = $length < 128 ? chr($length) : ltrim(pack('N', $length), "\0");

        return chr($tag).($length < 128 ? $encoded : chr(0x80 | strlen($encoded)).$encoded).$value;
    }
}
