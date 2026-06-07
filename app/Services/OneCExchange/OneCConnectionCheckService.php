<?php

declare(strict_types=1);

namespace App\Services\OneCExchange;

use App\Models\OneCBase;
use App\Models\OneCIntegrationProfile;
use App\Models\OneCProfileAuditEvent;
use App\Models\OneCProfileSecret;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

final class OneCConnectionCheckService
{
    private const RESULT_OK = 'ok';
    private const RESULT_TIMEOUT = 'timeout';
    private const RESULT_TRANSPORT_ERROR = 'transport_error';
    private const RESULT_UNAUTHORIZED = 'unauthorized';
    private const RESULT_INCOMPATIBLE_VERSION = 'incompatible_version';
    private const RESULT_MISSING_SCOPE = 'missing_scope';
    private const RESULT_UNCONFIGURED = 'unconfigured';
    private const RESULT_SECRET_MISSING = 'secret_missing';

    private const STATUS_OK = 'ok';
    private const STATUS_FAILED = 'failed';
    private const STATUS_UNAUTHORIZED = 'unauthorized';
    private const STATUS_UNCONFIGURED = 'unconfigured';

    /**
     * @return list<array<string, mixed>>
     */
    public function profiles(int $organizationId): array
    {
        /** @var Collection<int, OneCIntegrationProfile> $profiles */
        $profiles = OneCIntegrationProfile::query()
            ->with(['base', 'latestConnectionAuditEvent'])
            ->forOrganization($organizationId)
            ->orderBy('environment')
            ->orderBy('name')
            ->get();

        return $profiles
            ->map(fn (OneCIntegrationProfile $profile): array => $this->profilePayload($profile))
            ->values()
            ->all();
    }

    public function show(int $organizationId, int $profileId): ?array
    {
        $profile = $this->profileQuery($organizationId)
            ->whereKey($profileId)
            ->first();

        return $profile ? $this->profilePayload($profile) : null;
    }

    public function test(int $organizationId, int $profileId, ?int $actorId): ?array
    {
        $profile = $this->profileQuery($organizationId)
            ->whereKey($profileId)
            ->first();

        if (!$profile instanceof OneCIntegrationProfile) {
            return null;
        }

        $checkedAt = CarbonImmutable::now();
        $startedAt = hrtime(true);

        try {
            $result = $this->runCheck($profile, $checkedAt, $startedAt);
        } catch (Throwable) {
            $result = $this->result(
                self::RESULT_TRANSPORT_ERROR,
                self::STATUS_FAILED,
                [$this->step('endpoint_reachable', 'failed', self::RESULT_TRANSPORT_ERROR)],
                $checkedAt,
                $startedAt,
            );
        }

        $this->persistResult($profile, $result, $actorId);

        $profile->load(['base', 'latestConnectionAuditEvent']);

        return [
            ...$result,
            'profile' => $this->profilePayload($profile),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function runCheck(
        OneCIntegrationProfile $profile,
        CarbonImmutable $checkedAt,
        int $startedAt,
    ): array {
        $base = $this->baseForProfile($profile);
        $steps = [];

        if (!$base instanceof OneCBase) {
            $steps[] = $this->step('configuration', 'failed', self::RESULT_UNCONFIGURED);

            return $this->result(self::RESULT_UNCONFIGURED, self::STATUS_UNCONFIGURED, $steps, $checkedAt, $startedAt);
        }

        $metadataUrl = $this->metadataUrl($base);

        if (!$this->isConfiguredForCheck($profile, $base, $metadataUrl)) {
            $steps[] = $this->step('configuration', 'failed', self::RESULT_UNCONFIGURED);

            return $this->result(
                self::RESULT_UNCONFIGURED,
                self::STATUS_UNCONFIGURED,
                $steps,
                $checkedAt,
                $startedAt,
                endpointFingerprint: $this->endpointFingerprint($base),
            );
        }

        $steps[] = $this->step('configuration', 'ok');

        $secret = $this->activeSecret($profile);

        if (!$this->isSecretUsable($profile, $secret)) {
            $steps[] = $this->step('authorization', 'failed', self::RESULT_SECRET_MISSING);

            return $this->result(
                self::RESULT_SECRET_MISSING,
                self::STATUS_UNCONFIGURED,
                $steps,
                $checkedAt,
                $startedAt,
                endpointFingerprint: $this->endpointFingerprint($base),
            );
        }

        try {
            $response = $this->request($profile, $base, $secret)->get((string) $metadataUrl);
        } catch (ConnectionException $exception) {
            $code = $this->isTimeoutException($exception)
                ? self::RESULT_TIMEOUT
                : self::RESULT_TRANSPORT_ERROR;
            $steps[] = $this->step('endpoint_reachable', 'failed', $code);

            return $this->result(
                $code,
                self::STATUS_FAILED,
                $steps,
                $checkedAt,
                $startedAt,
                endpointFingerprint: $this->endpointFingerprint($base),
            );
        }

        if ($response->status() === 408) {
            $steps[] = $this->step('endpoint_reachable', 'failed', self::RESULT_TIMEOUT);

            return $this->result(
                self::RESULT_TIMEOUT,
                self::STATUS_FAILED,
                $steps,
                $checkedAt,
                $startedAt,
                transportStatus: $response->status(),
                endpointFingerprint: $this->endpointFingerprint($base),
            );
        }

        if ($response->status() === 401 || $response->status() === 403) {
            $steps[] = $this->step('endpoint_reachable', 'ok');
            $steps[] = $this->step('authorization', 'failed', self::RESULT_UNAUTHORIZED);

            return $this->result(
                self::RESULT_UNAUTHORIZED,
                self::STATUS_UNAUTHORIZED,
                $steps,
                $checkedAt,
                $startedAt,
                transportStatus: $response->status(),
                endpointFingerprint: $this->endpointFingerprint($base),
            );
        }

        if (!$response->successful()) {
            $steps[] = $this->step('endpoint_reachable', 'failed', self::RESULT_TRANSPORT_ERROR);

            return $this->result(
                self::RESULT_TRANSPORT_ERROR,
                self::STATUS_FAILED,
                $steps,
                $checkedAt,
                $startedAt,
                transportStatus: $response->status(),
                endpointFingerprint: $this->endpointFingerprint($base),
            );
        }

        $steps[] = $this->step('endpoint_reachable', 'ok');
        $steps[] = $this->step('authorization', 'ok');

        $metadata = $this->metadata($response);

        if ($metadata === []) {
            $steps[] = $this->step('metadata', 'failed', self::RESULT_INCOMPATIBLE_VERSION);

            return $this->result(
                self::RESULT_INCOMPATIBLE_VERSION,
                self::STATUS_FAILED,
                $steps,
                $checkedAt,
                $startedAt,
                transportStatus: $response->status(),
                endpointFingerprint: $this->endpointFingerprint($base),
            );
        }

        $steps[] = $this->step('metadata', 'ok');

        $protocolVersion = $this->stringValue($metadata['protocol_version'] ?? null);
        $connectorVersion = $this->stringValue($metadata['connector_version'] ?? null);
        $supportedScopes = $this->stringList($metadata['supported_scopes'] ?? []);

        if (!$this->isProtocolSupported($protocolVersion)) {
            $steps[] = $this->step('protocol_version', 'failed', self::RESULT_INCOMPATIBLE_VERSION);

            return $this->result(
                self::RESULT_INCOMPATIBLE_VERSION,
                self::STATUS_FAILED,
                $steps,
                $checkedAt,
                $startedAt,
                protocolVersion: $protocolVersion,
                connectorVersion: $connectorVersion,
                supportedScopes: $supportedScopes,
                transportStatus: $response->status(),
                endpointFingerprint: $this->endpointFingerprint($base),
            );
        }

        $steps[] = $this->step('protocol_version', 'ok');

        $missingScopes = $this->missingScopes($profile, $supportedScopes);

        if ($missingScopes !== []) {
            $steps[] = $this->step('scopes', 'failed', self::RESULT_MISSING_SCOPE, [
                'missing_scopes' => $missingScopes,
            ]);

            return $this->result(
                self::RESULT_MISSING_SCOPE,
                self::STATUS_FAILED,
                $steps,
                $checkedAt,
                $startedAt,
                protocolVersion: $protocolVersion,
                connectorVersion: $connectorVersion,
                supportedScopes: $supportedScopes,
                warningCodes: ['missing_scope'],
                transportStatus: $response->status(),
                endpointFingerprint: $this->endpointFingerprint($base),
            );
        }

        $steps[] = $this->step('scopes', 'ok');

        return $this->result(
            self::RESULT_OK,
            self::STATUS_OK,
            $steps,
            $checkedAt,
            $startedAt,
            protocolVersion: $protocolVersion,
            connectorVersion: $connectorVersion,
            supportedScopes: $supportedScopes,
            transportStatus: $response->status(),
            endpointFingerprint: $this->endpointFingerprint($base),
        );
    }

    private function profileQuery(int $organizationId): Builder
    {
        return OneCIntegrationProfile::query()
            ->with(['base', 'latestConnectionAuditEvent'])
            ->forOrganization($organizationId);
    }

    private function isConfiguredForCheck(OneCIntegrationProfile $profile, OneCBase $base, ?string $metadataUrl): bool
    {
        return in_array($profile->status, ['draft', 'active', 'paused', 'degraded'], true)
            && in_array($base->status, ['draft', 'active', 'paused'], true)
            && $base->connector === 'http'
            && $metadataUrl !== null;
    }

    private function activeSecret(OneCIntegrationProfile $profile): ?OneCProfileSecret
    {
        $type = $this->secretTypeForProfile($profile);

        if ($type === null) {
            return null;
        }

        return $profile->secrets()
            ->where('organization_id', $profile->organization_id)
            ->where('type', $type)
            ->active()
            ->latest('id')
            ->first();
    }

    private function secretTypeForProfile(OneCIntegrationProfile $profile): ?string
    {
        if ($profile->auth_type === 'none') {
            return null;
        }

        if ($profile->auth_type === 'basic') {
            return 'basic';
        }

        return 'bearer_token';
    }

    private function isSecretUsable(OneCIntegrationProfile $profile, ?OneCProfileSecret $secret): bool
    {
        if ($profile->auth_type === 'none') {
            return true;
        }

        if (!$secret instanceof OneCProfileSecret) {
            return false;
        }

        if ($profile->auth_type === 'basic') {
            return $secret->username() !== null && $secret->password() !== null;
        }

        return $secret->secretValue() !== null;
    }

    private function request(
        OneCIntegrationProfile $profile,
        OneCBase $base,
        ?OneCProfileSecret $secret,
    ): PendingRequest {
        $request = Http::acceptJson()
            ->asJson()
            ->timeout(max(1, min(120, (int) $base->timeout_seconds)))
            ->connectTimeout(max(1, min(30, (int) $base->connect_timeout_seconds)))
            ->withHeaders([
                'X-ProHelper-Connection-Check' => 'read-only',
                'X-ProHelper-Integration-Profile' => (string) $profile->id,
            ]);

        if ($profile->auth_type === 'basic' && $secret instanceof OneCProfileSecret) {
            return $request->withBasicAuth((string) $secret->username(), (string) $secret->password());
        }

        if ($profile->auth_type !== 'none' && $secret instanceof OneCProfileSecret) {
            return $request->withToken((string) $secret->secretValue());
        }

        return $request;
    }

    /**
     * @return array<string, mixed>
     */
    private function metadata(HttpResponse $response): array
    {
        $body = $response->json();

        if (!is_array($body)) {
            return [];
        }

        $metadata = $body['data'] ?? $body;

        return is_array($metadata) ? $metadata : [];
    }

    private function metadataUrl(OneCBase $base): ?string
    {
        $endpoint = $this->stripQueryAndFragment($base->endpointUrl());

        if ($endpoint === null) {
            return null;
        }

        $path = trim((string) $base->metadata_path);

        if ($path === '') {
            $path = '/metadata';
        }

        if (!str_starts_with($path, '/')) {
            $path = '/'.$path;
        }

        return rtrim($endpoint, '/').$path;
    }

    private function stripQueryAndFragment(?string $endpoint): ?string
    {
        if ($endpoint === null) {
            return null;
        }

        $parts = parse_url($endpoint);

        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $port = isset($parts['port']) ? ':'.(string) $parts['port'] : '';
        $path = isset($parts['path']) ? rtrim((string) $parts['path'], '/') : '';

        return (string) $parts['scheme'].'://'.(string) $parts['host'].$port.$path;
    }

    private function endpointFingerprint(OneCBase $base): ?string
    {
        $safeEndpoint = $this->stripQueryAndFragment($base->endpointUrl());

        return $safeEndpoint ? hash('sha256', $safeEndpoint) : null;
    }

    private function isProtocolSupported(?string $protocolVersion): bool
    {
        if ($protocolVersion === null || $protocolVersion === '') {
            return false;
        }

        $supported = config('one_c_exchange.connection_check.supported_protocol_versions', ['1.0']);
        $supported = is_array($supported) ? $supported : ['1.0'];

        return in_array($protocolVersion, array_map('strval', $supported), true);
    }

    /**
     * @return list<string>
     */
    private function missingScopes(OneCIntegrationProfile $profile, array $supportedScopes): array
    {
        $requiredScopes = $this->stringList($profile->allowed_scopes ?? []);

        if ($requiredScopes === []) {
            return [];
        }

        return array_values(array_diff($requiredScopes, $supportedScopes));
    }

    /**
     * @return array<string, mixed>
     */
    private function result(
        string $code,
        string $status,
        array $steps,
        CarbonImmutable $checkedAt,
        int $startedAt,
        ?string $protocolVersion = null,
        ?string $connectorVersion = null,
        array $supportedScopes = [],
        array $warningCodes = [],
        ?int $transportStatus = null,
        ?string $endpointFingerprint = null,
    ): array {
        return [
            'status' => $status,
            'code' => $code,
            'message' => trans_message("one_c_exchange.connection_check.result_codes.{$code}"),
            'steps' => $steps,
            'checked_at' => $checkedAt->toJSON(),
            'duration_ms' => $this->durationMs($startedAt),
            'protocol_version' => $protocolVersion,
            'connector_version' => $connectorVersion,
            'supported_scopes' => $supportedScopes,
            'warning_codes' => $warningCodes,
            'transport_status' => $transportStatus,
            'endpoint_fingerprint' => $endpointFingerprint,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function step(string $key, string $status, ?string $code = null, array $safeDetails = []): array
    {
        return [
            'key' => $key,
            'label' => trans_message("one_c_exchange.connection_check.steps.{$key}"),
            'status' => $status,
            'code' => $code,
            'message' => $code ? trans_message("one_c_exchange.connection_check.result_codes.{$code}") : null,
            'safe_details' => $safeDetails,
        ];
    }

    private function persistResult(OneCIntegrationProfile $profile, array $result, ?int $actorId): void
    {
        DB::transaction(function () use ($profile, $result, $actorId): void {
            $base = $this->baseForProfile($profile);

            $profile->forceFill([
                'connection_status' => $result['status'],
                'last_connection_check_at' => $result['checked_at'],
                'last_connection_check_code' => $result['code'],
                'protocol_version' => $result['protocol_version'],
                'connector_version' => $result['connector_version'],
                'supported_scopes' => $result['supported_scopes'],
                'warning_codes' => $result['warning_codes'],
                'status_reason_code' => $result['status'] === self::STATUS_OK ? null : $result['code'],
            ])->save();

            if ($base instanceof OneCBase) {
                $base->forceFill([
                    'connection_status' => $result['status'],
                    'last_connection_check_at' => $result['checked_at'],
                    'last_connection_check_code' => $result['code'],
                    'protocol_version' => $result['protocol_version'],
                    'connector_version' => $result['connector_version'],
                    'supported_scopes' => $result['supported_scopes'],
                    'warning_codes' => $result['warning_codes'],
                    'endpoint_fingerprint' => $result['endpoint_fingerprint'],
                ])->save();
            }

            OneCProfileAuditEvent::query()->create([
                'organization_id' => $profile->organization_id,
                'one_c_integration_profile_id' => $profile->id,
                'one_c_base_id' => $base?->id,
                'actor_id' => $actorId,
                'event_type' => 'connection_check_run',
                'result_code' => $result['code'],
                'result_status' => $result['status'],
                'duration_ms' => $result['duration_ms'],
                'safe_context' => [
                    'steps' => $result['steps'],
                    'protocol_version' => $result['protocol_version'],
                    'connector_version' => $result['connector_version'],
                    'supported_scopes' => $result['supported_scopes'],
                    'warning_codes' => $result['warning_codes'],
                    'transport_status' => $result['transport_status'],
                    'endpoint_fingerprint' => $result['endpoint_fingerprint'],
                ],
                'created_at' => $result['checked_at'],
            ]);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function profilePayload(OneCIntegrationProfile $profile): array
    {
        $base = $this->baseForProfile($profile);
        $audit = $profile->latestConnectionAuditEvent;

        return [
            'id' => $profile->id,
            'code' => $profile->code,
            'name' => $profile->name,
            'environment' => $profile->environment,
            'exchange_mode' => $profile->exchange_mode,
            'status' => $profile->status,
            'status_reason_code' => $profile->status_reason_code,
            'auth_type' => $profile->auth_type,
            'allowed_scopes' => $this->stringList($profile->allowed_scopes ?? []),
            'connection_status' => $profile->connection_status,
            'last_connection_check_at' => $this->date($profile->last_connection_check_at),
            'last_connection_check_code' => $profile->last_connection_check_code,
            'protocol_version' => $profile->protocol_version,
            'connector_version' => $profile->connector_version,
            'supported_scopes' => $this->stringList($profile->supported_scopes ?? []),
            'warning_codes' => $this->stringList($profile->warning_codes ?? []),
            'base' => $base instanceof OneCBase ? $this->basePayload($base) : null,
            'last_connection_check' => $audit instanceof OneCProfileAuditEvent
                ? $this->auditPayload($audit)
                : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function basePayload(OneCBase $base): array
    {
        return [
            'id' => $base->id,
            'code' => $base->code,
            'name' => $base->name,
            'environment' => $base->environment,
            'connector' => $base->connector,
            'endpoint_display' => $base->safeEndpointDisplay(),
            'endpoint_fingerprint' => $base->endpoint_fingerprint,
            'metadata_path' => $base->metadata_path,
            'status' => $base->status,
            'connection_status' => $base->connection_status,
            'last_connection_check_at' => $this->date($base->last_connection_check_at),
            'last_connection_check_code' => $base->last_connection_check_code,
            'protocol_version' => $base->protocol_version,
            'connector_version' => $base->connector_version,
            'supported_scopes' => $this->stringList($base->supported_scopes ?? []),
            'warning_codes' => $this->stringList($base->warning_codes ?? []),
            'timeout_seconds' => (int) $base->timeout_seconds,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function auditPayload(OneCProfileAuditEvent $audit): array
    {
        $context = is_array($audit->safe_context) ? $audit->safe_context : [];

        return [
            'id' => $audit->id,
            'event_type' => $audit->event_type,
            'result_code' => $audit->result_code,
            'result_status' => $audit->result_status,
            'duration_ms' => $audit->duration_ms,
            'steps' => is_array($context['steps'] ?? null) ? $context['steps'] : [],
            'warning_codes' => $this->stringList($context['warning_codes'] ?? []),
            'checked_at' => $this->date($audit->created_at),
        ];
    }

    private function durationMs(int $startedAt): int
    {
        return max(0, (int) round((hrtime(true) - $startedAt) / 1_000_000));
    }

    private function isTimeoutException(ConnectionException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'timed out')
            || str_contains($message, 'timeout')
            || str_contains($message, 'curl error 28');
    }

    private function stringValue(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $item): string => trim((string) $item), $value),
            static fn (string $item): bool => $item !== ''
        ));
    }

    private function date(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return CarbonImmutable::parse($value)->toJSON();
    }

    private function baseForProfile(OneCIntegrationProfile $profile): ?OneCBase
    {
        $base = $profile->base;

        if (!$base instanceof OneCBase) {
            return null;
        }

        return (int) $base->organization_id === (int) $profile->organization_id ? $base : null;
    }
}
