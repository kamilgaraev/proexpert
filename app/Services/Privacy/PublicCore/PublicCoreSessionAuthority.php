<?php

declare(strict_types=1);

namespace App\Services\Privacy\PublicCore;

use Closure;
use Throwable;

final class PublicCoreSessionAuthority
{
    public function __construct(
        private readonly RegisteredPublicFixtureRegistry $registry,
        private readonly PublicCoreReceiptStore $store,
        private readonly ?Closure $currentViewer = null,
        private readonly ?Closure $clock = null,
    ) {
    }

    public function openOrResume(array $trustedViewerBinding, array $selection, ?string $publicSessionRef = null): array
    {
        $keys = array_keys($selection);
        sort($keys);
        if ($keys !== ['fixture_id', 'fixture_version', 'input_id', 'request_id']
            || !is_string($selection['fixture_id']) || !is_string($selection['fixture_version'])
            || !is_string($selection['input_id']) || !is_string($selection['request_id'])
            || preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/Di', $selection['request_id']) !== 1
            || ($publicSessionRef !== null && preg_match('/^ref_[a-f0-9]{32}$/D', $publicSessionRef) !== 1)) {
            return self::blocked('source_unavailable');
        }
        $registered = $this->registry->resolve($selection['fixture_id'], $selection['fixture_version'], $selection['input_id']);
        if ($registered === null) {
            return self::blocked('source_unavailable');
        }
        $selection['request_id'] = strtolower($selection['request_id']);
        return $this->store->transaction(function (array &$state) use ($trustedViewerBinding, $selection, $registered, $publicSessionRef): array {
            $authorization = $this->authorize($trustedViewerBinding);
            $now = $this->now();
            if ($authorization === null || $now === null) {
                return self::blocked('authorization_changed');
            }
            $owner = $this->store->ownerDigest($authorization);
            $session = $publicSessionRef === null ? null : ($state['sessions'][$publicSessionRef] ?? null);
            if ($publicSessionRef !== null && ($session === null || !$this->currentSession($session, $owner, $registered, $now))) {
                return self::blocked('authorization_changed');
            }
            $idempotency = hash('sha256', $owner . '/' . strtolower($selection['request_id']));
            foreach ($state['requests'] as $saved) {
                if ($saved['idempotency'] === $idempotency) {
                    if ($saved['selection'] !== $selection || ($publicSessionRef !== null && $saved['sessionRef'] !== $publicSessionRef)
                        || $this->currentRequest($state, $trustedViewerBinding, $saved['requestRef']) === null) {
                        return self::blocked('source_changed');
                    }
                    return self::accepted($saved);
                }
            }
            if ($session === null) {
                if (count($state['sessions']) >= 128) {
                    return self::blocked('budget_exceeded');
                }
                $publicSessionRef = 'ref_' . bin2hex(random_bytes(16));
                $session = [
                    'sessionRef' => $publicSessionRef, 'ownerDigest' => $owner,
                    'fixtureId' => $selection['fixture_id'], 'fixtureVersion' => $selection['fixture_version'],
                    'registryDigest' => $this->registry->manifestDigest(), 'sourceGeneration' => $registered['source_generation_ref'],
                    'issuedAt' => $now, 'expiresAt' => $now + 300, 'revoked' => false, 'lastInput' => null,
                ];
            }
            if ($session['lastInput'] !== null) {
                $previous = $this->registry->resolve($session['fixtureId'], $session['fixtureVersion'], $session['lastInput']);
                if ($previous === null || !in_array($selection['input_id'], $previous['scenario_order'], true)) {
                    return self::blocked('source_changed');
                }
            }
            $ownedCount = 0;
            foreach ($state['requests'] as $saved) {
                $ownedCount += $saved['sessionRef'] === $publicSessionRef ? 1 : 0;
            }
            if ($ownedCount >= 32) {
                return self::blocked('budget_exceeded');
            }
            $requestRef = 'ref_' . bin2hex(random_bytes(16));
            $request = [
                'requestRef' => $requestRef, 'sessionRef' => $publicSessionRef, 'idempotency' => $idempotency,
                'selection' => PublicCoreReceiptStore::owned($selection), 'registered' => $registered,
                'requestRevision' => 'public-request/1', 'conversationRef' => 'ref_' . bin2hex(random_bytes(16)),
                'issuedAt' => $now, 'expiresAt' => min($now + 120, $session['expiresAt']), 'revoked' => false,
            ];
            $session['lastInput'] = $selection['input_id'];
            $state['sessions'][$publicSessionRef] = $session;
            $state['requests'][$requestRef] = $request;
            $fresh = $this->authorize($trustedViewerBinding);
            if ($fresh === null || $this->store->ownerDigest($fresh) !== $owner) {
                unset($state['requests'][$requestRef]);
                $state['sessions'][$publicSessionRef]['revoked'] = true;
                return self::blocked('authorization_changed');
            }
            return self::accepted($request);
        }) ?? self::blocked('receipt_unavailable');
    }

    public function currentRequest(array $state, array $trustedViewerBinding, string $requestRef): ?array
    {
        $authorization = $this->authorize($trustedViewerBinding);
        $now = $this->now();
        $request = $state['requests'][$requestRef] ?? null;
        if ($authorization === null || $now === null || $request === null || $request['revoked']
            || $request['issuedAt'] > $now || $request['expiresAt'] <= $now) {
            return null;
        }
        $session = $state['sessions'][$request['sessionRef']] ?? null;
        $registered = $this->registry->resolve($request['selection']['fixture_id'], $request['selection']['fixture_version'], $request['selection']['input_id']);
        if ($session === null || $registered === null || $registered !== $request['registered']
            || !$this->currentSession($session, $this->store->ownerDigest($authorization), $registered, $now)) {
            return null;
        }
        return $request;
    }

    public function lookup(array $trustedViewerBinding, string $requestRef): ?array
    {
        return $this->store->transaction(fn (array &$state): ?array => $this->currentRequest($state, $trustedViewerBinding, $requestRef));
    }

    public function publisher(array $trustedViewerBinding, string $requestRef, Closure $currentCoreBinding): PublicCoreReceiptStore
    {
        return $this->store->bound(function (array $state) use ($trustedViewerBinding, $requestRef, $currentCoreBinding): array {
            $request = $this->currentRequest($state, $trustedViewerBinding, $requestRef);
            $now = $this->now();
            if ($request === null || $now === null) {
                return [];
            }
            $binding = $currentCoreBinding(PublicCoreReceiptStore::owned($request));
            if (!is_array($binding) || array_keys($binding) !== ['scope', 'snapshotHash', 'profileFingerprint', 'registryDigest', 'aliases', 'sources', 'trustedModelProfile']
                || $binding['registryDigest'] !== $this->registry->manifestDigest()
                || $this->currentRequest($state, $trustedViewerBinding, $requestRef) === null) {
                return [];
            }
            return ['lineage' => [
                'requestRef' => $request['requestRef'], 'requestRevision' => $request['requestRevision'],
                'conversationRef' => $request['conversationRef'], 'issuedAt' => $request['issuedAt'],
                'expiresAt' => $request['expiresAt'], 'now' => $now,
            ]] + PublicCoreReceiptStore::owned($binding);
        });
    }

    public function revoke(array $trustedViewerBinding, string $requestRef): bool
    {
        $result = $this->store->transaction(function (array &$state) use ($trustedViewerBinding, $requestRef): array {
            $request = $this->currentRequest($state, $trustedViewerBinding, $requestRef);
            if ($request === null) {
                return ['revoked' => false];
            }
            $state['requests'][$requestRef]['revoked'] = true;
            $state['requests'][$requestRef]['requestRevision'] = 'public-request/revoked';
            return ['revoked' => true];
        });
        return $result !== null && $result['revoked'] === true;
    }

    private function currentSession(array $session, ?string $owner, array $registered, int $now): bool
    {
        return $owner !== null && $session['ownerDigest'] === $owner && !$session['revoked']
            && $session['issuedAt'] <= $now && $now < $session['expiresAt']
            && $session['fixtureId'] === $registered['fixture_id'] && $session['fixtureVersion'] === $registered['fixture_version']
            && $session['sourceGeneration'] === $registered['source_generation_ref']
            && $session['registryDigest'] === $this->registry->manifestDigest();
    }

    private function authorize(array $binding): ?array
    {
        if ($this->currentViewer === null) {
            return null;
        }
        try {
            $current = ($this->currentViewer)(PublicCoreReceiptStore::owned($binding));
            if (!is_array($current) || array_keys($current) !== ['authorized', 'viewerRef', 'organizationRef', 'authorizationRevision', 'policyRevision']
                || $current['authorized'] !== true) {
                return null;
            }
            foreach (['viewerRef', 'organizationRef', 'authorizationRevision', 'policyRevision'] as $key) {
                if (!is_string($current[$key]) || $current[$key] === '' || strlen($current[$key]) > 160
                    || preg_match('//u', $current[$key]) !== 1) {
                    return null;
                }
            }
            return PublicCoreReceiptStore::owned($current);
        } catch (Throwable) {
            return null;
        }
    }

    private function now(): ?int
    {
        try {
            $now = $this->clock === null ? time() : ($this->clock)();
            return is_int($now) && $now > 0 ? $now : null;
        } catch (Throwable) {
            return null;
        }
    }

    private static function accepted(array $request): array
    {
        return ['status' => 'accepted', 'reasonCode' => 'none', 'request_ref' => $request['requestRef'],
            'public_session_ref' => $request['sessionRef'], 'transportAllowed' => false];
    }

    private static function blocked(string $reason): array
    {
        return ['status' => 'blocked', 'reasonCode' => $reason, 'transportAllowed' => false];
    }
}
