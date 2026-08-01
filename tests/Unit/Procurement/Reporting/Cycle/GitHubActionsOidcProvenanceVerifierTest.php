<?php

declare(strict_types=1);

namespace Tests\Unit\Procurement\Reporting\Cycle;

use App\BusinessModules\Features\Procurement\Reporting\Cycle\CiEvidence\GitHubActionsOidcProvenanceVerifier;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\CiEvidence\R15Clock;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\CiEvidence\R15HttpClient;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class GitHubActionsOidcProvenanceVerifierTest extends TestCase
{
    private const PRIVATE_KEY = <<<'PEM'
-----BEGIN PRIVATE KEY-----
MIIEuwIBADANBgkqhkiG9w0BAQEFAASCBKUwggShAgEAAoIBAQCb9m5r+yfu9d19
fDLQeaehMhLMhOT3AgGMMmR7KhU9Kkxd+I7GEUlKaoikAMm+lht0wRwApgDZBTES
7GssHtOwprg/RPbcgahlFz8l8zfaFEpJfBjdV2ZXpx3fByxtxm68Hz7dGFpSj4W4
y+dTwjuKWcc4EHouxkBzUk4p8qdo623YrocpNbR+30ch3pkSA6rIQPoAxDmnmaWC
IotQOWKXzBN7R5NN44GaCeKmN8uC7yOlJ5rQcrDCbc5Viq6VvTtfXoC97WAteTP3
d7W6oyqBFr1f+ZaDzWLtKj06gOHVWr7vKBqncDqLGYKtuldm5DhFnNKNXiUd2shx
zQizH/IDAgMBAAECgf87qsXXyUuaPKpa7HfeAgaX/qPl8kK16iJACn/AWPj931Gm
2sQTSLV/wKSkmMCno+92R4L0iAqUSQFaS1XaVsuoWgGSx9P4lZlertTekVWlbDgO
yJtybqpfDn7gMGycnbkdwK8O7Zm2ezIfUu34yAnPOZ1cA4rvkNwd/qllcBz8Tng9
sygv9fRdmZkoE6ec/aGikeiIEKAE4+j+0zi+HzlgEPIh3RnpcQzkVeyn2gQUF/pX
L7vfu6tcG3+ekEhUwlmxNg1HbgpnbJtTDRLNith0dK8I4n7SYaTG1YMc7SFfbkNM
tzLooefaDoNEUBKz1D2muZLeOxNLt57O67JfVIECgYEA0ONynRJ2hr2OD2b2gyZ3
1sKdenWqetNwdFUmLX69Yey5zRrpOw2CIKVj7N8/eWlnzvpjH/UtcjbeXfoBrnO7
710TKA7hnljAoBOaz3eihQPY1BTIKdwvLC9+iFlqTd/iuKjHNQob4wM5OkpCtGUr
NObnjlen6TPmP4e0JxDJ18ECgYEAvyM263p62RSVP+Us37Sk1fA/EJS1uEOtnE6Q
KZlWjq7ZAOR5dYBDS56zYA0rHDIsl7FlwzdG4oFUt/VG0O86SyoFxtZKR4EMCUEA
V3DSYdlpwsUdve/gYE4xqjR00nIMJ/oB/LD1/ddfaHkI/URrU7lu4vmz1cJwz5x6
wM83GsMCgYAMQizJtvmpspoRoVM6IxnJSqZ/N0SH7LyetxiEdvmaM5A7zyZ8xrAq
Rkp07i0FbQAgj2UdzP+bwkiOb0uF91jCFU44fZcT+dVkD4J4RmcNM0qYN5f/jOJf
z3G3grfQViEyAQWfwTYdvmSvXQhJGd7NPfe8zLwX3WnXtJkH6d5OgQKBgQCWjIIh
j+NbQN8d6MEjowGCkSzbPoazkHsadrcOTPzkfcvm7A7PAiv/UA0xRq4CE3MUOGJT
FSL8HapBCyywPP3JXlwWdYskLvzv/Qbr2K2c7MZk5yW6CUrOEEkGKzMo3zO7+Su/
gRMA0bcQbuUpt85hr9krZkrDv9djb3kwjQZ2SQKBgDnctNh0HkYncaJKQbdsZbYR
jpvtUUQHgFea5mDtnbvscmq728fTufBG1L2zKs3gstqRznLzSasCet5QuIQ9tDYd
uMxoCMgN2C+G3IN4HJC11McMFm1lpTjNGQiJtsMLA91lBATDIGxNHqDKadW85nZx
AJG/aWimEC7bDx4XU/sg
-----END PRIVATE KEY-----
PEM;

    public function test_accepts_real_rs256_signed_github_actions_token_and_caches_jwks(): void
    {
        [$privateKey, $jwk] = $this->key();
        $clock = new FixedR15Clock(new DateTimeImmutable('@2000000000'));
        $http = new FakeR15HttpClient($this->jwtResponse($this->token($privateKey)), ['keys' => [$jwk]]);
        $verifier = $this->verifier($http, $clock);
        $verifier->verify('https://pipelines.actions.githubusercontent.com/idtoken', 'endpoint-token', str_repeat('a', 40), '123');
        $verifier->verify('https://pipelines.actions.githubusercontent.com/idtoken', 'endpoint-token', str_repeat('a', 40), '123');

        self::assertSame(1, $http->jwksRequests);
    }

    #[DataProvider('invalidTokens')]
    public function test_rejects_invalid_signature_key_claims_and_expiry(string $variant): void
    {
        [$privateKey, $jwk] = $this->key();
        $claims = $this->claims();
        $jwks = ['keys' => [$jwk]];
        if ($variant === 'key') {
            $jwks = ['keys' => [array_replace($jwk, ['kid' => 'other'])]];
        }
        $claimChanges = [
            'issuer' => ['iss' => 'https://spoof.example'], 'audience' => ['aud' => 'other'], 'repository' => ['repository' => 'spoof/repository'], 'ref' => ['ref' => 'refs/heads/spoof'],
            'workflow' => ['workflow' => 'Spoof workflow'], 'workflow_ref' => ['workflow_ref' => 'spoof/repository/.github/workflows/spoof.yml@refs/heads/main'], 'sha' => ['sha' => str_repeat('b', 40)],
            'run_id' => ['run_id' => '999'], 'nbf' => ['nbf' => 2000000001], 'expiry' => ['exp' => 1999999999],
        ];
        if (isset($claimChanges[$variant])) {
            $claims = array_replace($claims, $claimChanges[$variant]);
        }
        if ($variant === 'expiry') {
            $claims['exp'] = 1999999999;
        }
        $jwt = $this->token($privateKey, $claims);
        if ($variant === 'signature') {
            $jwt = substr($jwt, 0, -1).(str_ends_with($jwt, 'A') ? 'B' : 'A');
        }
        if ($variant === 'alg') {
            $jwt = $this->token($privateKey, $claims, ['alg' => 'HS256', 'kid' => 'test-key']);
        }
        if ($variant === 'key_type') {
            $jwks = ['keys' => [array_replace($jwk, ['kty' => 'EC'])]];
        }
        if ($variant === 'rsa_size') {
            $jwks = ['keys' => [array_replace($jwk, ['n' => $this->base64Url(substr(base64_decode(strtr($jwk['n'], '-_', '+/').str_repeat('=', (4 - strlen($jwk['n']) % 4) % 4), true), 128))])]];
        }
        $http = new FakeR15HttpClient($this->jwtResponse($jwt), $jwks);

        $this->expectException(RuntimeException::class);
        $this->verifier($http, new FixedR15Clock(new DateTimeImmutable('@2000000000')))->verify('https://pipelines.actions.githubusercontent.com/idtoken', 'endpoint-token', str_repeat('a', 40), '123');
    }

    /** @return array<string,array{string}> */
    public static function invalidTokens(): array
    {
        return ['signature' => ['signature'], 'unknown key' => ['key'], 'issuer' => ['issuer'], 'audience' => ['audience'], 'repository' => ['repository'], 'ref' => ['ref'], 'workflow' => ['workflow'], 'workflow ref' => ['workflow_ref'], 'sha' => ['sha'], 'run id' => ['run_id'], 'not before' => ['nbf'], 'expiry' => ['expiry'], 'algorithm' => ['alg'], 'JWK type' => ['key_type'], 'RSA size' => ['rsa_size']];
    }

    public function test_rejects_non_github_endpoint_before_any_request(): void
    {
        [, $jwk] = $this->key();
        $http = new FakeR15HttpClient('{}', ['keys' => [$jwk]]);
        $this->expectException(RuntimeException::class);
        try {
            $this->verifier($http, new FixedR15Clock(new DateTimeImmutable('@2000000000')))->verify('https://spoof.example.test/id-token', 'syntactically-plausible-token', str_repeat('a', 40), '123');
        } finally {
            self::assertSame(0, $http->requests);
        }
    }

    #[DataProvider('untrustedEndpointUrls')]
    public function test_rejects_untrusted_actions_subdomains_ports_and_paths_before_any_request(string $url): void
    {
        [, $jwk] = $this->key();
        $http = new FakeR15HttpClient('{}', ['keys' => [$jwk]]);
        $this->expectException(RuntimeException::class);
        try {
            $this->verifier($http, new FixedR15Clock(new DateTimeImmutable('@2000000000')))->verify($url, 'syntactically-plausible-token', str_repeat('a', 40), '123');
        } finally {
            self::assertSame(0, $http->requests);
        }
    }

    /** @return array<string,array{string}> */
    public static function untrustedEndpointUrls(): array
    {
        return [
            'spoofed subdomain' => ['https://spoof.actions.githubusercontent.com/idtoken'],
            'non-default port' => ['https://pipelines.actions.githubusercontent.com:8443/idtoken'],
            'unexpected path' => ['https://pipelines.actions.githubusercontent.com/other'],
        ];
    }

    public function test_rejects_endpoint_failure(): void
    {
        [, $jwk] = $this->key();
        $http = new FakeR15HttpClient('{}', ['keys' => [$jwk]], true);
        $this->expectException(RuntimeException::class);
        $this->verifier($http, new FixedR15Clock(new DateTimeImmutable('@2000000000')))->verify('https://pipelines.actions.githubusercontent.com/idtoken', 'endpoint-token', str_repeat('a', 40), '123');
    }

    private function verifier(FakeR15HttpClient $http, R15Clock $clock): GitHubActionsOidcProvenanceVerifier
    {
        return new GitHubActionsOidcProvenanceVerifier($http, $clock, 'kamilgaraev/proexpert', 'refs/heads/main', 'Notification PostgreSQL Concurrency', 'kamilgaraev/proexpert/.github/workflows/notification-concurrency.yml@refs/heads/main');
    }

    /** @return array{mixed,array<string,string>} */
    private function key(): array
    {
        $privateKey = openssl_pkey_get_private(self::PRIVATE_KEY);
        self::assertNotFalse($privateKey);
        $details = openssl_pkey_get_details($privateKey);
        self::assertIsArray($details);

        return [$privateKey, ['kty' => 'RSA', 'use' => 'sig', 'kid' => 'test-key', 'n' => $this->base64Url($details['rsa']['n']), 'e' => $this->base64Url($details['rsa']['e'])]];
    }

    /** @param mixed $privateKey @param array<string,mixed>|null $claims */
    private function token(mixed $privateKey, ?array $claims = null, ?array $header = null): string
    {
        $header = $this->base64Url(json_encode($header ?? ['alg' => 'RS256', 'kid' => 'test-key'], JSON_THROW_ON_ERROR));
        $payload = $this->base64Url(json_encode($claims ?? $this->claims(), JSON_THROW_ON_ERROR));
        self::assertTrue(openssl_sign($header.'.'.$payload, $signature, $privateKey, OPENSSL_ALGO_SHA256));

        return $header.'.'.$payload.'.'.$this->base64Url($signature);
    }

    /** @return array<string,mixed> */
    private function claims(): array
    {
        return ['iss' => 'https://token.actions.githubusercontent.com', 'aud' => 'most-r15-candidate-evidence', 'repository' => 'kamilgaraev/proexpert', 'ref' => 'refs/heads/main', 'workflow' => 'Notification PostgreSQL Concurrency', 'workflow_ref' => 'kamilgaraev/proexpert/.github/workflows/notification-concurrency.yml@refs/heads/main', 'sha' => str_repeat('a', 40), 'run_id' => '123', 'nbf' => 1999999900, 'exp' => 2000000100];
    }

    private function jwtResponse(string $jwt): string
    {
        return json_encode(['value' => $jwt], JSON_THROW_ON_ERROR);
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}

final class FixedR15Clock implements R15Clock
{
    public function __construct(private readonly DateTimeImmutable $now) {}

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }
}
final class FakeR15HttpClient implements R15HttpClient
{
    public int $jwksRequests = 0;

    public int $requests = 0;

    /** @param array<string,mixed> $jwks */
    public function __construct(private readonly string $tokenResponse, private readonly array $jwks, private readonly bool $fails = false) {}

    public function get(string $url, array $headers = []): string
    {
        $this->requests++;
        if ($this->fails) {
            throw new RuntimeException('endpoint_failed');
        }
        if (str_contains($url, '/.well-known/jwks')) {
            $this->jwksRequests++;

            return json_encode($this->jwks, JSON_THROW_ON_ERROR);
        }

        return $this->tokenResponse;
    }
}
