<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Cycle\CiEvidence;

use RuntimeException;

final class GitHubActionsOidcProvenanceVerifier
{
    private const ISSUER = 'https://token.actions.githubusercontent.com';

    private const JWKS_URL = self::ISSUER.'/.well-known/jwks';

    /** @var array<string, array<string, mixed>>|null */
    private ?array $cachedKeys = null;

    private int $cachedAt = 0;

    public function __construct(
        private readonly R15HttpClient $http,
        private readonly R15Clock $clock,
        private readonly string $repository,
        private readonly string $ref,
        private readonly string $workflow,
        private readonly string $workflowRef,
        private readonly string $audience = 'most-r15-candidate-evidence',
    ) {}

    public function verify(string $endpointUrl, string $requestToken, string $sha, string $runId): void
    {
        if (! $this->isGithubActionsEndpoint($endpointUrl)
            || $requestToken === ''
            || preg_match('/^[a-f0-9]{40}$/D', $sha) !== 1
            || preg_match('/^[1-9][0-9]*$/D', $runId) !== 1) {
            $this->reject();
        }
        $separator = str_contains($endpointUrl, '?') ? '&' : '?';
        $response = $this->decodeJson($this->http->get(
            $endpointUrl.$separator.'audience='.rawurlencode($this->audience),
            ['Authorization: Bearer '.$requestToken, 'Accept: application/json'],
        ));
        $jwt = $response['value'] ?? null;
        if (! is_string($jwt)) {
            $this->reject();
        }
        [$header, $claims, $signature, $signingInput] = $this->parseJwt($jwt);
        $kid = $header['kid'] ?? null;
        if (($header['alg'] ?? null) !== 'RS256' || ! is_string($kid) || $kid === '') {
            $this->reject();
        }
        $key = $this->keys()[$kid] ?? null;
        if (! is_array($key)
            || openssl_verify($signingInput, $signature, self::jwkToPem($key), OPENSSL_ALGO_SHA256) !== 1) {
            $this->reject();
        }
        $now = $this->clock->now()->getTimestamp();
        $audience = $claims['aud'] ?? null;
        $audienceMatches = is_string($audience) ? hash_equals($this->audience, $audience) : is_array($audience) && in_array($this->audience, $audience, true);
        if (! $audienceMatches
            || ($claims['iss'] ?? null) !== self::ISSUER
            || ($claims['repository'] ?? null) !== $this->repository
            || ($claims['ref'] ?? null) !== $this->ref
            || ($claims['workflow'] ?? null) !== $this->workflow
            || ($claims['workflow_ref'] ?? null) !== $this->workflowRef
            || ($claims['sha'] ?? null) !== $sha
            || ! hash_equals($runId, (string) ($claims['run_id'] ?? ''))
            || ! is_int($claims['exp'] ?? null)
            || ! is_int($claims['nbf'] ?? null)
            || $claims['exp'] <= $now
            || $claims['nbf'] > $now) {
            $this->reject();
        }
    }

    /** @return array<string, array<string, mixed>> */
    private function keys(): array
    {
        $now = $this->clock->now()->getTimestamp();
        if ($this->cachedKeys !== null && $now - $this->cachedAt < 300) {
            return $this->cachedKeys;
        }
        $payload = $this->decodeJson($this->http->get(self::JWKS_URL, ['Accept: application/json']));
        $keys = $payload['keys'] ?? null;
        if (! is_array($keys) || ! array_is_list($keys)) {
            $this->reject();
        }
        $indexed = [];
        foreach ($keys as $key) {
            if (! is_array($key) || ! is_string($key['kid'] ?? null) || isset($indexed[$key['kid']])) {
                $this->reject();
            }
            $indexed[$key['kid']] = $key;
        }
        if ($indexed === []) {
            $this->reject();
        }
        $this->cachedKeys = $indexed;
        $this->cachedAt = $now;

        return $indexed;
    }

    /** @return array<string, mixed> */
    private function decodeJson(string $json): array
    {
        try {
            $value = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            $this->reject();
        }
        if (! is_array($value) || array_is_list($value)) {
            $this->reject();
        }

        return $value;
    }

    /** @return array{0:array<string,mixed>,1:array<string,mixed>,2:string,3:string} */
    private function parseJwt(string $jwt): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3 || in_array('', $parts, true)) {
            $this->reject();
        }
        [$encodedHeader, $encodedClaims, $encodedSignature] = $parts;
        $header = $this->decodeJson(self::base64UrlDecode($encodedHeader));
        $claims = $this->decodeJson(self::base64UrlDecode($encodedClaims));

        return [$header, $claims, self::base64UrlDecode($encodedSignature), $encodedHeader.'.'.$encodedClaims];
    }

    /** @param array<string, mixed> $key */
    public static function jwkToPem(array $key): string
    {
        if (($key['kty'] ?? null) !== 'RSA' || ($key['use'] ?? 'sig') !== 'sig' || ! is_string($key['n'] ?? null) || ! is_string($key['e'] ?? null)) {
            throw new RuntimeException('r15_candidate_evidence_oidc_untrusted');
        }
        $modulus = self::base64UrlDecode($key['n']);
        $exponent = self::base64UrlDecode($key['e']);
        if (strlen($modulus) < 256 || $modulus === '' || $exponent === '') {
            throw new RuntimeException('r15_candidate_evidence_oidc_untrusted');
        }
        $integer = static fn (string $value): string => self::der("\x02", (ord($value[0]) > 127 ? "\0" : '').$value);
        $rsa = self::der("\x30", $integer($modulus).$integer($exponent));
        $algorithm = "\x30\r\x06\t*\x86H\x86\xf7\r\x01\x01\x01\x05\0";
        $spki = self::der("\x30", $algorithm.self::der("\x03", "\0".$rsa));

        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($spki), 64, "\n")."-----END PUBLIC KEY-----\n";
    }

    private static function der(string $tag, string $value): string
    {
        $length = strlen($value);
        if ($length < 128) {
            return $tag.chr($length).$value;
        }
        $octets = ltrim(pack('N', $length), "\0");

        return $tag.chr(0x80 | strlen($octets)).$octets.$value;
    }

    private static function base64UrlDecode(string $value): string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/D', $value) !== 1) {
            throw new RuntimeException('r15_candidate_evidence_oidc_untrusted');
        }
        $decoded = base64_decode(strtr($value, '-_', '+/').str_repeat('=', (4 - strlen($value) % 4) % 4), true);
        if (! is_string($decoded)) {
            throw new RuntimeException('r15_candidate_evidence_oidc_untrusted');
        }

        return $decoded;
    }

    private function isGithubActionsEndpoint(string $url): bool
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || ! is_string($parts['host'] ?? null)) {
            return false;
        }
        $host = strtolower($parts['host']);

        return $host === 'actions.githubusercontent.com' || str_ends_with($host, '.actions.githubusercontent.com');
    }

    private function reject(): never
    {
        throw new RuntimeException('r15_candidate_evidence_oidc_untrusted');
    }
}
