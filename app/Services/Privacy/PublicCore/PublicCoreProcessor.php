<?php

declare(strict_types=1);

namespace App\Services\Privacy\PublicCore;

use App\BusinessModules\Features\AIAssistant\Services\Loop\AssistantLocalLoop;
use App\Services\Privacy\Gateway\Contracts\GatewayModelRequest;
use Closure;
use Throwable;

final class PublicCoreProcessor
{
    private readonly string $processRef;
    private array $runtimes = [];

    public function __construct(
        private readonly RegisteredPublicFixtureRegistry $registry,
        private readonly PublicCoreReceiptStore $store,
        private readonly PublicCoreSessionAuthority $sessions,
        private readonly PublicCoreRuntimeReadiness $readiness,
        private readonly ?Closure $peerSource = null,
        private readonly ?Closure $viewerBindingSource = null,
        private readonly ?Closure $nativeComposition = null,
    ) {
        $this->processRef = 'ref_' . bin2hex(random_bytes(16));
    }

    public function handle(string $command, array $payload, array $verifiedPeer): array
    {
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
        $profile = $this->readiness->qualifiedProfile();
        if ($this->nativeComposition === null || $profile === null) {
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
            $this->runtimes[$request['sessionRef']] = $composition['runtime'];
            $native = $composition['loop']->run($composition['profileRef'], $composition['refs']);
            $freshSource = ($composition['sourceState'])();
            if ($freshSource !== $source || $this->readiness->currentProfileFingerprint() !== $profile->fingerprint()
                || !GatewayModelRequest::hasExactKeys($native, ['status', 'mode', 'transportAllowed', 'reply', 'trace'])
                || $native['status'] !== 'READY' || $native['mode'] !== 'offline-synthetic' || $native['transportAllowed'] !== false
                || !is_string($native['reply']) || trim($native['reply']) === '' || strlen($native['reply']) > 32768
                || !is_array($native['trace'])) {
                throw new \LogicException('invalid_model_output');
            }
            $result = ['status' => 'completed', 'reasonCode' => 'none', 'request_ref' => $requestRef,
                'reply' => $native['reply'], 'trace' => $native['trace'], 'transportAllowed' => false];
        } catch (Throwable) {
            $result = self::blocked('source_unavailable') + ['request_ref' => $requestRef];
        }
        return $this->store->transaction(function (array &$state) use ($viewer, $requestRef, $result, $profile): array {
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
            $state['requests'][$requestRef]['execution']['status'] = $result['status'];
            $state['requests'][$requestRef]['execution']['result'] = PublicCoreReceiptStore::owned($result);
            return $result;
        }) ?? self::blocked('receipt_unavailable');
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
        return $execution['result'] ?? self::blocked('receipt_unavailable');
    }

    private function readinessDto(): array
    {
        $dto = $this->readiness->resolve();
        if ($this->viewerBindingSource === null || $this->nativeComposition === null || !$this->store->available()) {
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
