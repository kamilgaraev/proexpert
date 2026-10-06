<?php

declare(strict_types=1);

namespace App\Services\Privacy\PublicCore;

use App\BusinessModules\Features\AIAssistant\Services\Loop\AssistantLocalLoop;
use App\Services\Privacy\Gateway\Contracts\GatewayModelRequest;
use App\Services\Privacy\Gateway\Contracts\GatewayModelResponse;
use Closure;
use Throwable;

final class PublicCoreProcessor
{
    private readonly string $processRef;
    private array $runtimes = [];
    private array $runtimeInstances = [];
    private bool $publishing = false;
    private array $publicationRefs = [];
    private bool $publicationViolated = false;

    public function __construct(
        private readonly RegisteredPublicFixtureRegistry $registry,
        private readonly PublicCoreReceiptStore $store,
        private readonly PublicCoreSessionAuthority $sessions,
        private readonly PublicCoreRuntimeReadiness $readiness,
        private readonly ?Closure $peerSource = null,
        private readonly ?Closure $viewerBindingSource = null,
        private readonly ?Closure $nativeComposition = null,
        private readonly ?Closure $currentRuntimeSource = null,
        private readonly ?Closure $finalPublication = null,
        private readonly ?Closure $publicationBounds = null,
    ) {
        $this->processRef = 'ref_' . bin2hex(random_bytes(16));
    }

    public function handle(string $command, array $payload, array $verifiedPeer): array
    {
        if ($this->publishing) {
            $this->publicationViolated = true;
            return self::blocked('receipt_changed');
        }
        try {
            $peer = $this->peerSource === null ? null : ($this->peerSource)($verifiedPeer);
            if (!GatewayModelRequest::hasExactKeys($verifiedPeer, ['pid', 'uid', 'gid'])
                || !is_int($verifiedPeer['pid']) || $verifiedPeer['pid'] <= 0
                || !is_int($verifiedPeer['uid']) || $verifiedPeer['uid'] < 0 || !is_int($verifiedPeer['gid']) || $verifiedPeer['gid'] < 0
                || !GatewayModelRequest::hasExactKeys($peer, ['role', 'identityRef', 'kernelPeer'])
                || $peer['role'] !== 'app' || !GatewayModelRequest::isReference($peer['identityRef']) || $peer['kernelPeer'] !== $verifiedPeer) {
                return self::blocked('gateway_identity_unavailable');
            }
            if ($command === 'readiness') {
                return $payload === [] ? $this->readinessDto() : self::blocked('source_unavailable');
            }
            if ($this->viewerBindingSource === null || !GatewayModelRequest::isReference($payload['viewer_ticket_ref'] ?? null)) {
                return self::blocked('authorization_changed');
            }
            $viewer = ($this->viewerBindingSource)($payload['viewer_ticket_ref'], $peer);
            if (!GatewayModelRequest::hasExactKeys($viewer, ['viewerTicketRef']) || $viewer['viewerTicketRef'] !== $payload['viewer_ticket_ref']) {
                return self::blocked('authorization_changed');
            }
            if ($command === 'open_or_resume') {
                $selection = $payload;
                unset($selection['viewer_ticket_ref'], $selection['public_session_ref']);
                $session = $payload['public_session_ref'] ?? null;
                if ($session !== null && !is_string($session)) {
                    return self::blocked('source_unavailable');
                }
                $opened = $this->sessions->openOrResume($viewer, $selection, $session);
                if ($opened['status'] !== 'accepted') {
                    return $opened;
                }
                return $this->store->transaction(function (array &$state) use ($viewer, $opened): array {
                    if ($this->sessions->currentRequest($state, $viewer, $opened['request_ref']) === null) {
                        return self::blocked('authorization_changed');
                    }
                    $saved = $state['requests'][$opened['request_ref']]['processorViewerBinding'] ?? null;
                    if ($saved !== null && $saved !== $viewer) {
                        return self::blocked('authorization_changed');
                    }
                    $state['requests'][$opened['request_ref']]['processorViewerBinding'] = PublicCoreReceiptStore::owned($viewer);
                    return $opened;
                }) ?? self::blocked('receipt_unavailable');
            }
            if (!in_array($command, ['execute_owned', 'lookup_owned'], true)
                || !GatewayModelRequest::hasExactKeys($payload, ['viewer_ticket_ref', 'request_ref'])
                || !GatewayModelRequest::isReference($payload['request_ref'])) {
                return self::blocked('source_unavailable');
            }
            $request = $this->sessions->lookup($viewer, $payload['request_ref']);
            if ($request === null || ($request['processorViewerBinding'] ?? null) !== $viewer) {
                return self::blocked('authorization_changed');
            }
            return $command === 'execute_owned' ? $this->executeOwned($payload['request_ref']) : $this->lookupOwned($viewer, $payload['request_ref']);
        } catch (Throwable) {
            return self::blocked('receipt_unavailable');
        }
    }

    public function executeOwned(string $requestRef): array
    {
        if ($this->publishing) {
            $this->publicationViolated = true;
            return self::blocked('receipt_changed');
        }
        $profile = $this->readiness->qualifiedProfile();
        if ($this->nativeComposition === null || $profile === null || $this->currentRuntimeSource === null
            || $this->finalPublication === null || $this->publicationBounds === null) {
            return self::blocked('runtime_not_activated');
        }
        $claim = $this->store->transaction(function (array &$state) use ($requestRef): array {
            $viewer = $state['requests'][$requestRef]['processorViewerBinding'] ?? null;
            $request = is_array($viewer) ? $this->sessions->currentRequest($state, $viewer, $requestRef) : null;
            if ($request === null) {
                return self::blocked('authorization_changed');
            }
            $execution = $request['execution'] ?? null;
            if ($execution !== null) {
                return ['status' => 'existing', 'viewer' => $viewer];
            }
            if ($this->qualifiedPublicationBounds($request) === null) {
                return self::blocked('runtime_not_activated');
            }
            $session = $state['sessions'][$request['sessionRef']];
            if (isset($session['runtimeGenerationRef']) && !isset($this->runtimes[$request['sessionRef']])) {
                return self::blocked('source_changed');
            }
            $state['requests'][$requestRef]['execution'] = ['status' => 'running', 'processRef' => $this->processRef];
            return ['status' => 'claimed', 'request' => $request, 'viewer' => $viewer];
        });
        if ($claim === null) {
            return self::blocked('receipt_unavailable');
        }
        if ($claim['status'] === 'existing') {
            return $this->lookupOwned($claim['viewer'], $requestRef);
        }
        if ($claim['status'] !== 'claimed') {
            return $claim;
        }
        $request = $claim['request'];
        $viewer = $claim['viewer'];
        $result = self::blocked('source_unavailable');
        try {
            $composition = ($this->nativeComposition)(PublicCoreReceiptStore::owned($request), PublicCoreReceiptStore::owned($viewer),
                $this->store, $this->sessions, $this->readiness, $this->runtimes[$request['sessionRef']] ?? null);
            if (!GatewayModelRequest::hasExactKeys($composition, ['loop', 'profileRef', 'refs', 'sourceState', 'runtime'])
                || !$composition['loop'] instanceof AssistantLocalLoop || !is_string($composition['profileRef'])
                || !is_array($composition['refs']) || !$composition['sourceState'] instanceof Closure || !is_object($composition['runtime'])) {
                throw new \LogicException('source_unavailable');
            }
            $source = ($composition['sourceState'])();
            if (!GatewayModelRequest::hasExactKeys($source, ['registryDigest', 'manifestGenerationRef', 'runtimeGenerationRef'])
                || $source['registryDigest'] !== $this->registry->manifestDigest()
                || $source['manifestGenerationRef'] !== $request['registered']['source_generation_ref']
                || !GatewayModelRequest::isReference($source['runtimeGenerationRef'])
                || $composition['profileRef'] !== $profile->values()['profileRef']) {
                throw new \LogicException('source_changed');
            }
            $bound = $this->store->transaction(function (array &$state) use ($viewer, $requestRef, $source): array {
                $current = $this->sessions->currentRequest($state, $viewer, $requestRef);
                if ($current === null || ($current['execution']['processRef'] ?? null) !== $this->processRef
                    || ($current['execution']['status'] ?? null) !== 'running') {
                    return ['bound' => false];
                }
                $previous = $state['sessions'][$current['sessionRef']]['runtimeGenerationRef'] ?? null;
                if ($previous !== null && $previous !== $source['runtimeGenerationRef']) {
                    return ['bound' => false];
                }
                $state['sessions'][$current['sessionRef']]['runtimeGenerationRef'] = $source['runtimeGenerationRef'];
                $state['requests'][$requestRef]['runtimeGenerationRef'] = $source['runtimeGenerationRef'];
                return ['bound' => true];
            });
            if ($bound !== ['bound' => true]) {
                throw new \LogicException('source_changed');
            }
            $instance = $this->runtimeInstances[$request['sessionRef']] ?? null;
            if ($instance !== null && $instance['runtime'] !== $composition['runtime']) {
                throw new \LogicException('source_changed');
            }
            $this->runtimes[$request['sessionRef']] = $composition['runtime'];
            $this->runtimeInstances[$request['sessionRef']] = $instance ?? [
                'runtime' => $composition['runtime'], 'instanceRef' => 'ref_' . bin2hex(random_bytes(16)),
            ];
            $currentRequest = $this->sessions->lookup($viewer, $requestRef);
            if ($currentRequest === null || ($this->currentRuntimeSource)($composition['runtime'], $currentRequest, $profile) !== $source
                || $this->readiness->currentProfileFingerprint() !== $profile->fingerprint()) {
                throw new \LogicException('source_changed');
            }
            $native = $composition['loop']->run($composition['profileRef'], $composition['refs']);
            $freshSource = ($composition['sourceState'])();
            if ($freshSource !== $source || $this->readiness->currentProfileFingerprint() !== $profile->fingerprint()
                || !GatewayModelRequest::hasExactKeys($native, ['status', 'mode', 'transportAllowed', 'reply', 'trace'])
                || $native['status'] !== 'READY' || $native['mode'] !== 'offline-synthetic' || $native['transportAllowed'] !== false
                || !is_string($native['reply']) || trim($native['reply']) === '' || strlen($native['reply']) > 32768
                || !$this->validTrace($native['trace'])) {
                throw new \LogicException('invalid_model_output');
            }
            $result = ['status' => 'completed', 'reasonCode' => 'none', 'request_ref' => $requestRef,
                'reply' => $native['reply'], 'trace' => $native['trace'], 'transportAllowed' => false];
        } catch (Throwable) {
            $result = self::blocked('source_unavailable') + ['request_ref' => $requestRef];
        }
        $finished = $this->store->transaction(function (array &$state) use ($viewer, $requestRef, $result, $profile): array {
            $request = $this->sessions->currentRequest($state, $viewer, $requestRef);
            if ($request === null || ($request['execution']['processRef'] ?? null) !== $this->processRef
                || ($request['execution']['status'] ?? null) !== 'running') {
                return self::blocked('authorization_changed');
            }
            if ($result['status'] === 'completed' && $this->readiness->currentProfileFingerprint() !== $profile->fingerprint()) {
                $state['requests'][$requestRef]['execution']['status'] = 'blocked';
                $state['requests'][$requestRef]['execution']['result'] = self::blocked('profile_changed');
                return self::blocked('profile_changed');
            }
            if ($result['status'] === 'completed') {
                $binding = $this->completedBinding($request, $state['sessions'][$request['sessionRef']], $result);
                $fresh = $this->sessions->currentRequest($state, $viewer, $requestRef);
                if ($binding === null || $fresh === null || $fresh !== $request
                    || $this->completedBinding($fresh, $state['sessions'][$request['sessionRef']], $result) !== $binding) {
                    $result = self::blocked('source_changed') + ['request_ref' => $requestRef];
                } else {
                    $state['requests'][$requestRef]['execution']['resultBinding'] = $binding;
                }
            }
            $state['requests'][$requestRef]['execution']['status'] = $result['status'];
            $state['requests'][$requestRef]['execution']['result'] = PublicCoreReceiptStore::owned($result);
            return $result['status'] === 'completed' ? ['status' => 'stored'] : $result;
        }) ?? self::blocked('receipt_unavailable');
        return $finished['status'] === 'stored' ? $this->lookupOwned($viewer, $requestRef) : $finished;
    }

    private function lookupOwned(array $viewer, string $requestRef): array
    {
        $request = $this->sessions->lookup($viewer, $requestRef);
        if ($request === null || ($request['processorViewerBinding'] ?? null) !== $viewer) {
            return self::blocked('authorization_changed');
        }
        $execution = $request['execution'] ?? null;
        if ($execution === null) {
            return ['status' => 'accepted', 'reasonCode' => 'none', 'request_ref' => $requestRef, 'transportAllowed' => false];
        }
        if ($execution['status'] === 'running') {
            return self::blocked('receipt_changed') + ['request_ref' => $requestRef];
        }
        if ($execution['status'] !== 'completed') {
            $reason = $execution['result']['reasonCode'] ?? null;
            return self::blocked(is_string($reason) && $reason !== 'none' && in_array($reason, GatewayModelResponse::REASON_CODES, true)
                ? $reason : 'receipt_unavailable') + ['request_ref' => $requestRef];
        }
        $saved = $execution['resultBinding'] ?? null;
        $runtime = $this->runtimes[$request['sessionRef']] ?? null;
        if (!is_array($saved) || !is_object($runtime) || $this->finalPublication === null || $this->publicationBounds === null || $this->publishing) {
            return self::blocked('runtime_not_activated');
        }
        $active = true;
        $called = false;
        $staged = null;
        $this->publishing = true;
        $this->publicationViolated = false;
        try {
            $bounds = $this->qualifiedPublicationBounds($request);
            if ($bounds === null || count($this->publicationRefs) >= 4096) {
                return self::blocked('runtime_not_activated');
            }
            $start = intdiv(hrtime(true), 1000000);
            $operationRef = 'ref_' . bin2hex(random_bytes(16));
            $input = ['schemaVersion' => 'public-core-publication-operation/1', 'operationRef' => $operationRef,
                'viewerBinding' => PublicCoreReceiptStore::owned($viewer), 'resultBinding' => PublicCoreReceiptStore::owned($saved),
                'genuineExpiresAt' => $request['expiresAt'], 'maxDurationMs' => $bounds['maxDurationMs']];
            $isActive = static function () use (&$active): bool {
                return $active;
            };
            $prepare = function () use ($viewer, $requestRef, $saved, $runtime, $start, $bounds, $isActive, &$called, &$staged): ?array {
                if (!$isActive() || $called || intdiv(hrtime(true), 1000000) - $start >= $bounds['maxDurationMs']) {
                    $this->publicationViolated = true;
                    return null;
                }
                $called = true;
                $staged = $this->store->transaction(function (array &$state) use ($viewer, $requestRef, $saved, $runtime): ?array {
                    $request = $this->sessions->currentRequest($state, $viewer, $requestRef);
                    if ($request === null || ($request['processorViewerBinding'] ?? null) !== $viewer
                        || ($this->runtimes[$request['sessionRef']] ?? null) !== $runtime
                        || ($request['execution']['status'] ?? null) !== 'completed'
                        || ($request['execution']['resultBinding'] ?? null) !== $saved) {
                        return null;
                    }
                    $result = $request['execution']['result'] ?? null;
                    $session = $state['sessions'][$request['sessionRef']];
                    $current = is_array($result) ? $this->completedBinding($request, $session, $result) : null;
                    if ($current === null || $current !== $saved) {
                        return null;
                    }
                    $fresh = $this->sessions->currentRequest($state, $viewer, $requestRef);
                    if ($fresh === null || $fresh !== $request || $this->completedBinding($fresh, $session, $result) !== $saved) {
                        return null;
                    }
                    return ['binding' => PublicCoreReceiptStore::owned($saved), 'resultBytes' => RegisteredPublicFixtureRegistry::canonical($result),
                        'resultDigest' => $saved['resultDigest']];
                });
                if ($this->publicationWasViolated()) {
                    $staged = null;
                }
                return $staged;
            };
            $receipt = ($this->finalPublication)(PublicCoreReceiptStore::owned($input), $runtime, $prepare);
            if (!$called || $staged === null || $this->publicationWasViolated() || intdiv(hrtime(true), 1000000) - $start >= $bounds['maxDurationMs']
                || !GatewayModelRequest::hasExactKeys($receipt, ['schemaVersion', 'binding', 'publicationRef'])
                || $receipt['schemaVersion'] !== 'public-core-result-publication/1'
                || $receipt['binding'] !== $saved || !GatewayModelRequest::isReference($receipt['publicationRef'])
                || isset($this->publicationRefs[$receipt['publicationRef']])) {
                return self::blocked('source_changed');
            }
            $this->publicationRefs[$receipt['publicationRef']] = true;
            return PublicCoreReceiptStore::owned($receipt);
        } catch (Throwable) {
            return self::blocked('source_changed');
        } finally {
            $active = false;
            $this->publishing = false;
        }
    }

    private function completedBinding(array $request, array $session, array $result): ?array
    {
        $profile = $this->readiness->qualifiedProfile();
        $runtime = $this->runtimes[$request['sessionRef']] ?? null;
        $instance = $this->runtimeInstances[$request['sessionRef']] ?? null;
        if ($profile === null || $this->currentRuntimeSource === null || !is_object($runtime) || !is_array($instance)
            || array_keys($instance) !== ['runtime', 'instanceRef'] || $instance['runtime'] !== $runtime
            || !GatewayModelRequest::isReference($instance['instanceRef'])
            || ($request['execution']['processRef'] ?? null) !== $this->processRef
            || !GatewayModelRequest::hasExactKeys($result, ['status', 'reasonCode', 'request_ref', 'reply', 'trace', 'transportAllowed'])
            || array_keys($result) !== ['status', 'reasonCode', 'request_ref', 'reply', 'trace', 'transportAllowed']
            || $result['status'] !== 'completed' || $result['reasonCode'] !== 'none' || $result['request_ref'] !== $request['requestRef']
            || $result['transportAllowed'] !== false || !$this->validTrace($result['trace']) || !is_string($result['reply'])
            || trim($result['reply']) === '' || strlen($result['reply']) > 32768 || preg_match('//u', $result['reply']) !== 1
            || str_contains($result['reply'], "\0")) {
            return null;
        }
        try {
            $source = ($this->currentRuntimeSource)($runtime, PublicCoreReceiptStore::owned($request), $profile);
            if (!GatewayModelRequest::hasExactKeys($source, ['registryDigest', 'manifestGenerationRef', 'runtimeGenerationRef'])
                || $source['registryDigest'] !== $this->registry->manifestDigest()
                || $source['manifestGenerationRef'] !== $request['registered']['source_generation_ref']
                || !GatewayModelRequest::isReference($source['runtimeGenerationRef'])
                || $source['runtimeGenerationRef'] !== ($request['runtimeGenerationRef'] ?? null)
                || $source['runtimeGenerationRef'] !== ($session['runtimeGenerationRef'] ?? null)
                || $this->readiness->currentProfileFingerprint() !== $profile->fingerprint()) {
                return null;
            }
            return ['schemaVersion' => 'public-core-result-binding/1', 'requestRef' => $request['requestRef'],
                'sessionRef' => $request['sessionRef'], 'processRef' => $this->processRef, 'ownerDigest' => $session['ownerDigest'],
                'profileFingerprint' => $profile->fingerprint(), 'registryDigest' => $source['registryDigest'],
                'manifestGenerationRef' => $source['manifestGenerationRef'], 'runtimeGenerationRef' => $source['runtimeGenerationRef'],
                'runtimeInstanceRef' => $instance['instanceRef'], 'resultDigest' => hash('sha256', RegisteredPublicFixtureRegistry::canonical($result))];
        } catch (Throwable) {
            return null;
        }
    }

    private function qualifiedPublicationBounds(array $request): ?array
    {
        if ($this->publicationBounds === null || $this->finalPublication === null) {
            return null;
        }
        try {
            $bounds = ($this->publicationBounds)(PublicCoreReceiptStore::owned($request), $this->finalPublication);
            $profile = $this->readiness->qualifiedProfile();
            return GatewayModelRequest::hasExactKeys($bounds, ['qualification', 'profileFingerprint', 'guardEvidenceRef', 'maxDurationMs', 'genuineExpiresAt'])
                && $profile !== null && $bounds['qualification'] === ($profile->isActualProfile() ? 'actual-publication-guard' : 'source-simulated-tcb')
                && $bounds['profileFingerprint'] === $profile->fingerprint() && GatewayModelRequest::isReference($bounds['guardEvidenceRef'])
                && is_int($bounds['maxDurationMs']) && $bounds['maxDurationMs'] > 0 && $bounds['maxDurationMs'] <= 30000
                && $bounds['genuineExpiresAt'] === $request['expiresAt'] ? $bounds : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function validTrace(mixed $trace): bool
    {
        if (!is_array($trace) || !array_is_list($trace)) {
            return false;
        }
        foreach ($trace as $event) {
            if (!GatewayModelRequest::hasExactKeys($event, ['action', 'step', 'tokens', 'callRef'])
                || array_keys($event) !== ['action', 'step', 'tokens', 'callRef']
                || !in_array($event['action'], ['plan', 'tool', 'refine', 'summary', 'final', 'repair', 'ready', 'blocked'], true)
                || !is_int($event['step']) || $event['step'] < 0 || !is_int($event['tokens']) || $event['tokens'] < 0
                || ($event['callRef'] !== null && (!is_string($event['callRef']) || preg_match('/^ref_[a-f0-9]{32}$/D', $event['callRef']) !== 1))) {
                return false;
            }
        }
        return true;
    }

    private function publicationWasViolated(): bool
    {
        return $this->publicationViolated;
    }

    private function readinessDto(): array
    {
        $dto = $this->readiness->resolve();
        if ($this->viewerBindingSource === null || $this->nativeComposition === null || $this->currentRuntimeSource === null
            || $this->finalPublication === null || $this->publicationBounds === null || !$this->store->available()) {
            $dto['status'] = 'unavailable';
            $dto['reason_code'] = 'runtime_not_activated';
            $dto['actual_model'] = null;
            $dto['model_enabled'] = false;
            $dto['capabilities'] = ['text' => false, 'tools' => false, 'vision' => false];
        }
        return $dto;
    }

    private static function blocked(string $reason): array
    {
        return ['status' => 'blocked', 'reasonCode' => $reason, 'transportAllowed' => false];
    }
}
