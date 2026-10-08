<?php

declare(strict_types=1);

namespace App\Services\Privacy\PublicCore;

use App\BusinessModules\Features\AIAssistant\Services\Loop\AssistantLocalLoop;
use App\BusinessModules\Features\AIAssistant\Http\Resources\PublicCoreRuntimeResource;
use App\Services\Privacy\Gateway\Contracts\GatewayModelRequest;
use App\Services\Privacy\Gateway\Contracts\GatewayModelResponse;
use App\Services\Privacy\PublicCore\Transport\AuthenticatedPublicCoreChannel;
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
    private ?array $gatewayTransfer = null;
    private ?array $normalOperation = null;
    private ?PublicCoreDispatchAuthority $normalAuthority = null;
    private array $normalOperations = [];
    private bool $normalViolated = false;
    private readonly ?Closure $finalPublication;
    private readonly ?Closure $publicationBounds;

    public function __construct(
        private readonly RegisteredPublicFixtureRegistry $registry,
        private readonly PublicCoreReceiptStore $store,
        private readonly PublicCoreSessionAuthority $sessions,
        private readonly PublicCoreRuntimeReadiness $readiness,
        private readonly ?Closure $peerSource = null,
        private readonly ?Closure $viewerBindingSource = null,
        private readonly ?Closure $nativeComposition = null,
        private readonly ?Closure $currentRuntimeSource = null,
        ?Closure $finalPublication = null,
        ?Closure $publicationBounds = null,
    ) {
        $this->processRef = 'ref_' . bin2hex(random_bytes(16));
        $this->finalPublication = $finalPublication ?? $this->publishNormalResult(...);
        $this->publicationBounds = $publicationBounds ?? $this->normalPublicationBounds(...);
    }

    public function handle(string $command, array $payload, array $verifiedPeer): array
    {
        if ($this->normalOperation !== null) {
            $this->normalViolated = true;
            return self::blocked('receipt_changed');
        }
        return $this->handleInput($command, $payload, $verifiedPeer);
    }

    private function handleInput(string $command, array $payload, array $verifiedPeer): array
    {
        if ($this->gatewayTransfer !== null) {
            return self::blocked('receipt_changed');
        }
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
        if ($this->normalOperation !== null) {
            $this->normalViolated = true;
            return self::blocked('runtime_not_activated');
        }
        return $this->executeOwnedInput($requestRef);
    }

    private function executeOwnedInput(string $requestRef): array
    {
        if ($this->gatewayTransfer !== null) {
            return self::blocked('receipt_changed');
        }
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
                || !(GatewayModelRequest::hasExactKeys($native, ['status', 'mode', 'transportAllowed', 'reply', 'trace', 'actual_model', 'tools', 'sources'])
                    || (!$profile->isActualProfile() && GatewayModelRequest::hasExactKeys($native, ['status', 'mode', 'transportAllowed', 'reply', 'trace'])))
                || $native['status'] !== 'READY' || $native['mode'] !== 'offline-synthetic' || $native['transportAllowed'] !== false
                || !is_string($native['reply']) || trim($native['reply']) === '' || strlen($native['reply']) > 32768
                || !$this->validTrace($native['trace'])) {
                throw new \LogicException('invalid_model_output');
            }
            $result = ['status' => 'completed', 'reasonCode' => 'none', 'request_ref' => $requestRef,
                'reply' => $native['reply'], 'trace' => $native['trace'], 'transportAllowed' => false,
                'actual_model' => $native['actual_model'] ?? null, 'tools' => $native['tools'] ?? [], 'sources' => $native['sources'] ?? []];
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

    public function serveAppChannel(AuthenticatedPublicCoreChannel $channel): void
    {
        if ($this->normalOperation !== null || $this->gatewayTransfer !== null || $this->publishing) {
            $this->normalViolated = true;
            $channel->close();
            return;
        }
        try {
            while (true) {
                $frame = $channel->receive();
                $peer = $channel->peer();
                if (!$this->validNormalRequest($frame)
                    || isset($this->normalOperations[$frame['payload']['operationRef']]) || count($this->normalOperations) >= 1024) {
                    break;
                }
                $operation = $frame['payload']['operationRef'];
                $this->normalOperations[$operation] = true;
                $this->normalViolated = false;
                $this->normalOperation = ['channel' => $channel, 'peer' => $peer, 'frame' => $frame,
                    'receivedSequence' => $frame['sequence'], 'phase' => 'NORMAL_RUNNING'];
                $this->normalAuthority = new PublicCoreDispatchAuthority($this->store, $this->readiness);
                try {
                    $verified = $this->peerSource === null ? null : ($this->peerSource)($peer);
                    if (!GatewayModelRequest::hasExactKeys($verified, ['role', 'identityRef', 'kernelPeer'])
                        || $verified['role'] !== 'app' || !GatewayModelRequest::isReference($verified['identityRef'])
                        || $verified['kernelPeer'] !== $peer) {
                        break;
                    }
                    $output = $this->normalOutput($frame['command'], $frame['payload']['input'], $peer);
                    if ($this->normalWasViolated() || time() >= $frame['expiresAt']) {
                        break;
                    }
                    $channel->send('result', $frame['requestRef'], $frame['attemptRef'],
                        ['schemaVersion' => 'public-core-processor-operation-result/1-proposal',
                            'operationRef' => $operation, 'output' => $output], $frame['expiresAt']);
                } finally {
                    $this->normalOperation = null;
                    $this->normalAuthority = null;
                }
            }
        } catch (Throwable) {
        } finally {
            $this->normalOperation = null;
            $this->normalAuthority = null;
            $channel->close();
        }
    }

    public function normalAppScope(PublicCoreDispatchAuthority $authority, string $ticket): ?array
    {
        $scope = $this->normalOperation;
        if ($scope === null || $this->normalAuthority !== $authority || $scope['phase'] !== 'NORMAL_RUNNING'
            || $scope['frame']['command'] === 'readiness' || $scope['frame']['payload']['input']['viewer_ticket_ref'] !== $ticket
            || $this->normalViolated || time() >= $scope['frame']['expiresAt']) {
            return null;
        }
        return $scope;
    }

    private function normalWasViolated(): bool
    {
        return $this->normalViolated;
    }

    public function exchangeNormalBootstrap(PublicCoreDispatchAuthority $authority, string $ticket): ?array
    {
        $scope = $this->normalAppScope($authority, $ticket);
        if ($scope === null) {
            return null;
        }
        $this->normalOperation['phase'] = 'VIEWER_CHECK';
        try {
            $channel = $scope['channel'];
            $channel->send('check_binding', null, null, ['schemaVersion' => 'public-core-app-viewer-ticket-check/1',
                'viewerTicketRef' => $ticket], $scope['frame']['expiresAt']);
            $frame = $channel->receive();
            $peer = $channel->peer();
            $this->normalOperation['receivedSequence'] = $frame['sequence'];
            return ['frame' => $frame, 'peer' => $peer];
        } catch (Throwable) {
            $this->normalViolated = true;
            return null;
        } finally {
            $this->normalOperation['phase'] = 'NORMAL_RUNNING';
        }
    }

    public function rejectNormalBootstrap(PublicCoreDispatchAuthority $authority): void
    {
        if ($this->normalAuthority === $authority && $this->normalOperation !== null) {
            $this->normalViolated = true;
        }
    }

    public function currentNormalViewer(array $binding): ?array
    {
        if (!GatewayModelRequest::hasExactKeys($binding, ['viewerTicketRef']) || $this->normalAuthority === null
            || !is_string($binding['viewerTicketRef'])) {
            return null;
        }
        return $this->normalAuthority->bootstrapNormalViewer($this, $binding['viewerTicketRef']);
    }

    private function validNormalRequest(array $frame): bool
    {
        $payload = $frame['payload'];
        if (!in_array($frame['command'], ['readiness', 'open_or_resume', 'execute_owned', 'lookup_owned'], true)
            || !GatewayModelRequest::hasExactKeys($payload, ['schemaVersion', 'operationRef', 'input'])
            || $payload['schemaVersion'] !== 'public-core-processor-operation/1-proposal'
            || !is_string($payload['operationRef']) || preg_match('/^ref_[a-f0-9]{32}$/D', $payload['operationRef']) !== 1
            || !is_array($payload['input']) || $frame['attemptRef'] !== $payload['operationRef'] || time() >= $frame['expiresAt']) {
            return false;
        }
        $input = $payload['input'];
        if ($frame['command'] === 'readiness') {
            return $input === [] && $frame['requestRef'] === $payload['operationRef'];
        }
        if (!is_string($input['viewer_ticket_ref'] ?? null) || preg_match('/^viewer_[a-f0-9]{48}$/D', $input['viewer_ticket_ref']) !== 1) {
            return false;
        }
        if ($frame['command'] === 'open_or_resume') {
            if (!GatewayModelRequest::hasExactKeys($input, ['viewer_ticket_ref', 'fixture_id', 'fixture_version', 'input_id', 'request_id', 'public_session_ref'])
                || $frame['requestRef'] !== $payload['operationRef'] || !is_string($input['request_id'])
                || preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $input['request_id']) !== 1
                || ($input['public_session_ref'] !== null && (!is_string($input['public_session_ref'])
                    || preg_match('/^ref_[a-f0-9]{32}$/D', $input['public_session_ref']) !== 1))) {
                return false;
            }
            foreach (['fixture_id', 'fixture_version', 'input_id'] as $key) {
                if (!is_string($input[$key]) || strlen($input[$key]) > 128 || preg_match('~^[A-Za-z0-9._/-]+$~D', $input[$key]) !== 1) {
                    return false;
                }
            }
            return true;
        }
        return GatewayModelRequest::hasExactKeys($input, ['viewer_ticket_ref', 'request_ref'])
            && is_string($input['request_ref']) && preg_match('/^ref_[a-f0-9]{32}$/D', $input['request_ref']) === 1
            && $frame['requestRef'] === $input['request_ref'];
    }

    private function normalOutput(string $command, array $input, array $peer): array
    {
        if ($this->normalViolated) {
            return self::blocked('receipt_changed');
        }
        if ($command === 'readiness') {
            return $this->readinessDto();
        }
        $binding = ['viewerTicketRef' => $input['viewer_ticket_ref']];
        $viewer = $this->currentNormalViewer($binding);
        if ($viewer === null || $this->sessions->currentViewer($binding) !== $viewer) {
            return self::blocked('authorization_changed');
        }
        if ($command === 'open_or_resume') {
            $replay = $this->store->transaction(function (array &$state) use ($input, $binding): array {
                foreach ($state['requests'] as $request) {
                    if (($request['processorViewerBinding'] ?? null) === $binding && isset($request['normalProcessRef'])
                        && $request['selection']['request_id'] === $input['request_id']) {
                        return ['valid' => $request['normalProcessRef'] === $this->processRef
                            && $this->normalOperation['frame']['expiresAt'] <= $request['expiresAt']
                            && $this->sessions->currentRequest($state, $binding, $request['requestRef']) !== null];
                    }
                }
                return ['valid' => true];
            });
            if ($replay !== ['valid' => true]) {
                return self::blocked('authorization_changed');
            }
            $output = $this->handleInput($command, $input, $peer);
            if (($output['status'] ?? null) !== 'accepted') {
                return self::blocked($output['reasonCode'] ?? 'receipt_unavailable');
            }
            return $this->store->transaction(function (array &$state) use ($binding, $output): array {
                $request = $this->sessions->currentRequest($state, $binding, $output['request_ref']);
                if ($request === null || $request['sessionRef'] !== $output['public_session_ref']
                    || ($request['processorViewerBinding'] ?? null) !== $binding
                    || (isset($request['normalProcessRef']) && $request['normalProcessRef'] !== $this->processRef)
                    || $this->normalOperation['frame']['expiresAt'] > $request['expiresAt']) {
                    return self::blocked('authorization_changed');
                }
                $state['requests'][$request['requestRef']]['normalProcessRef'] = $this->processRef;
                return $output + ['process_ref' => $this->processRef, 'original_expires_at' => $request['expiresAt']];
            }) ?? self::blocked('receipt_unavailable');
        }
        $request = $this->sessions->lookup($binding, $input['request_ref']);
        if ($request === null || ($request['processorViewerBinding'] ?? null) !== $binding
            || ($request['normalProcessRef'] ?? null) !== $this->processRef
            || $this->normalOperation['frame']['expiresAt'] > $request['expiresAt']) {
            return self::blocked('authorization_changed');
        }
        if ($command === 'lookup_owned' && ($request['execution'] ?? null) === null) {
            return ['status' => 'accepted', 'reasonCode' => 'none', 'request_ref' => $request['requestRef'], 'transportAllowed' => false];
        }
        return $command === 'execute_owned' ? $this->executeOwnedInput($request['requestRef'])
            : $this->lookupOwned($binding, $request['requestRef']);
    }

    public function dispatchGateway(PublicCoreDispatchAuthority $authority, AuthenticatedPublicCoreChannel $channel,
        GatewayModelRequest $packet): GatewayModelResponse
    {
        if ($this->normalOperation !== null && !$this->normalOwnsPacket($packet)) {
            $this->normalViolated = true;
            return GatewayModelResponse::unavailable($packet, 'runtime_not_activated');
        }
        $profile = $this->readiness->qualifiedProfile();
        if ($this->publishing || $this->gatewayTransfer !== null || $profile === null
            || $profile->fingerprint() !== $packet->profileFingerprint || !$authority->matchesGatewayChannel($channel)) {
            return GatewayModelResponse::unavailable($packet, 'gateway_identity_unavailable');
        }
        try {
            return $authority->withDispatchFence($packet, function () use ($authority, $channel, $packet, $profile): GatewayModelResponse {
                $this->gatewayTransfer = ['authority' => $authority, 'channel' => $channel, 'packet' => $packet,
                    'binding' => $packet->binding(), 'transferRef' => 'ref_' . bin2hex(random_bytes(16)),
                    'qualification' => $profile->isActualProfile() ? 'actual-native' : 'local-source-test',
                    'event' => 'pending', 'eventSequence' => null, 'frame' => null, 'completionIssued' => false,
                    'authorized' => false, 'cancelSent' => false, 'cleanupDeadline' => null];
                return $authority->withProcessorGateway($this, $channel, $packet, function () use ($authority, $channel, $packet): GatewayModelResponse {
                    $channel->dispatchGatewayRequest($packet, $packet->expiresAt);
                    $this->gatewayTransfer['genuineDeadline'] = $channel->gatewayDeadline();
                    if ($this->readGatewayLifecycle($authority, $packet) === null) {
                        return GatewayModelResponse::unavailable($packet, 'gateway_channel_unavailable');
                    }
                    while (true) {
                        if ($this->gatewayTransfer['authorized'] && $this->gatewayTransfer['event'] === 'pending'
                            && ($authority->remainingUploadMs() < 1 || hrtime(true) >= $channel->gatewayDeadline())) {
                            $authority->cancelUpload($packet, 'expired');
                            return GatewayModelResponse::unavailable($packet, 'expired');
                        }
                        $frame = $channel->poll();
                        if ($frame === null) {
                            if (hrtime(true) >= $channel->gatewayDeadline()) {
                                return GatewayModelResponse::unavailable($packet, 'expired');
                            }
                            usleep(1000);
                            continue;
                        }
                        $this->gatewayTransfer['frame'] = $frame;
                        if ($frame['command'] === 'result') {
                            return GatewayModelResponse::fromArray($frame['payload']);
                        }
                        if ($this->readGatewayLifecycle($authority, $packet) === null) {
                            return GatewayModelResponse::unavailable($packet, 'gateway_channel_unavailable');
                        }
                        $reply = $authority->handleGatewayFrame($frame, $channel->peer(), $packet);
                        if ($frame['command'] === 'abort') {
                            return GatewayModelResponse::blocked($packet, $frame['payload']['reasonCode']);
                        }
                        $command = match ($frame['command']) {
                            'check_binding' => 'binding', 'authorize_write' => 'write_authorized',
                            'upload_complete' => 'uploaded', default => null,
                        };
                        if ($command === null || ($frame['command'] === 'upload_complete' && isset($reply['reasonCode']))) {
                            return GatewayModelResponse::unavailable($packet, 'gateway_channel_unavailable');
                        }
                        $channel->send($command, $packet->requestRef, $packet->attemptRef, $reply, $packet->expiresAt);
                        if ($frame['command'] === 'authorize_write') {
                            $this->gatewayTransfer['authorized'] = !isset($reply['reasonCode']);
                        }
                    }
                });
            });
        } catch (Throwable) {
            return GatewayModelResponse::unavailable($packet, 'gateway_channel_unavailable');
        } finally {
            $channel->close();
            $this->gatewayTransfer = null;
        }
    }

    public function nativeGatewayTransfer(PublicCoreDispatchAuthority $authority, string $operation,
        GatewayModelRequest $packet, ?array $cancel = null): ?array
    {
        if (!$this->ownsGatewayTransfer($authority, $packet) || !in_array($operation, ['read', 'cancel'], true)) {
            return null;
        }
        try {
            if ($operation === 'cancel') {
                if (!GatewayModelRequest::hasExactKeys($cancel, ['schemaVersion', 'binding', 'reasonCode'])
                    || $cancel['schemaVersion'] !== 'public-core-gateway-upload-cancel/1' || $cancel['binding'] !== $packet->binding()
                    || !in_array($cancel['reasonCode'], GatewayModelResponse::REASON_CODES, true) || $cancel['reasonCode'] === 'none') {
                    return null;
                }
                $event = $this->readGatewayLifecycle($authority, $packet);
                if ($event !== null && $event['event'] !== 'pending') {
                    return $event;
                }
                if ($event === null || !$this->gatewayTransfer['authorized'] || $this->gatewayTransfer['cancelSent']) {
                    return null;
                }
                $this->gatewayTransfer['cancelSent'] = true;
                $channel = $this->gatewayTransfer['channel'];
                $channel->beginCleanup($packet, $packet->expiresAt);
                $this->gatewayTransfer['cleanupDeadline'] = min(hrtime(true) + 250000000,
                    $this->gatewayTransfer['genuineDeadline'] + 250000000);
                $channel->send('abort', $packet->requestRef, $packet->attemptRef, $cancel, $packet->expiresAt);
                while (hrtime(true) < $this->gatewayTransfer['cleanupDeadline']) {
                    $frame = $channel->poll();
                    if ($frame === null) {
                        usleep(1000);
                        continue;
                    }
                    $this->gatewayTransfer['frame'] = $frame;
                    if ($frame['command'] !== 'abort' || $frame['payload']['reasonCode'] !== $cancel['reasonCode']) {
                        return null;
                    }
                    return $this->readGatewayLifecycle($authority, $packet);
                }
                return null;
            }
            return $this->readGatewayLifecycle($authority, $packet);
        } catch (Throwable) {
            return null;
        }
    }

    public function gatewayCompletionRef(PublicCoreDispatchAuthority $authority, GatewayModelRequest $packet, array $event): ?string
    {
        if (!$this->ownsGatewayTransfer($authority, $packet) || $this->gatewayTransfer['completionIssued']
            || !in_array($this->gatewayTransfer['event'], ['uploaded', 'stopped'], true)
            || $this->readGatewayLifecycle($authority, $packet) !== $event) {
            return null;
        }
        $this->gatewayTransfer['completionIssued'] = true;
        $this->gatewayTransfer['completionRef'] = 'ref_' . bin2hex(random_bytes(16));
        return $this->gatewayTransfer['completionRef'];
    }

    private function normalPublicationBounds(array $request, Closure $callback): ?array
    {
        $scope = $this->normalOperation;
        $profile = $this->readiness->qualifiedProfile();
        if ($scope === null || $scope['phase'] !== 'NORMAL_RUNNING' || $this->normalViolated
            || !in_array($scope['frame']['command'], ['execute_owned', 'lookup_owned'], true)
            || $scope['frame']['requestRef'] !== $request['requestRef'] || $callback !== $this->finalPublication
            || $profile?->isActualProfile() !== true || time() >= $scope['frame']['expiresAt']
            || $scope['frame']['expiresAt'] > $request['expiresAt']) { return null; }
        return ['qualification' => 'actual-publication-guard', 'profileFingerprint' => $profile->fingerprint(),
            'guardEvidenceRef' => $scope['frame']['attemptRef'],
            'maxDurationMs' => min(30000, ($scope['frame']['expiresAt'] - time()) * 1000),
            'genuineExpiresAt' => $request['expiresAt']];
    }

    private function publishNormalResult(array $input, object $runtime, Closure $prepare): ?array
    {
        $scope = $this->normalOperation;
        $binding = $input['resultBinding'] ?? null;
        if ($scope === null || $this->normalViolated || $scope['phase'] !== 'NORMAL_RUNNING' || !is_array($binding)
            || !in_array($scope['frame']['command'], ['execute_owned', 'lookup_owned'], true)
            || ($binding['requestRef'] ?? null) !== $scope['frame']['requestRef']
            || ($input['viewerBinding'] ?? null) !== ['viewerTicketRef' => $scope['frame']['payload']['input']['viewer_ticket_ref']]
            || ($binding['processRef'] ?? null) !== $this->processRef
            || ($this->runtimes[$binding['sessionRef'] ?? ''] ?? null) !== $runtime
            || ($this->runtimeInstances[$binding['sessionRef'] ?? '']['instanceRef'] ?? null) !== ($binding['runtimeInstanceRef'] ?? null)) { return null; }
        $candidate = $prepare();
        if ($candidate === null || $this->normalViolated || time() >= $scope['frame']['expiresAt']) { return null; }
        $this->normalOperation['phase'] = 'PUBLICATION';
        try {
            $scope = $this->normalOperation;
            $scope['channel']->send('result', $scope['frame']['requestRef'], $scope['frame']['attemptRef'],
                ['schemaVersion' => 'public-core-app-publication-stage/1', 'operation' => $input, 'candidate' => $candidate], $scope['frame']['expiresAt']);
            $reply = $scope['channel']->receive();
            if ($scope['channel']->peer() !== $scope['peer'] || $reply['channelRef'] !== $scope['channel']->channelRef()
                || $reply['sequence'] !== $scope['receivedSequence'] + 1 || $reply['command'] !== 'result'
                || $reply['requestRef'] !== $scope['frame']['requestRef'] || $reply['attemptRef'] !== $scope['frame']['attemptRef']
                || $reply['expiresAt'] !== $scope['frame']['expiresAt']) { throw new \LogicException('receipt_changed'); }
            $this->normalOperation['receivedSequence'] = $reply['sequence'];
            return $reply['payload'];
        } catch (Throwable) {
            $this->normalViolated = true;
            return null;
        } finally { $this->normalOperation['phase'] = 'NORMAL_RUNNING'; }
    }

    public function gatewayCustody(PublicCoreDispatchAuthority $authority, GatewayModelRequest $packet,
        array $event, ?string $completionRef = null): ?array
    {
        if (!$this->ownsGatewayTransfer($authority, $packet) || $this->gatewayTransfer['qualification'] !== 'actual-native'
            || $this->readGatewayLifecycle($authority, $packet) !== $event
            || ($completionRef === null ? $event['event'] !== 'pending'
                : (!in_array($event['event'], ['uploaded', 'stopped'], true)
                    || !$this->gatewayTransfer['completionIssued'] || ($this->gatewayTransfer['completionRef'] ?? null) !== $completionRef))) {
            return null;
        }
        return ['schemaVersion' => 'public-core-native-upload-custody/1', 'qualification' => 'actual-native',
            'channelRef' => $event['channelRef'], 'gatewayPeer' => $this->gatewayTransfer['channel']->peer(),
            'transferRef' => $event['transferRef'], 'requestRef' => $packet->requestRef, 'attemptRef' => $packet->attemptRef,
            'projectionDigest' => $packet->projectionDigest, 'event' => $event['event'],
            'eventSequence' => $this->gatewayTransfer['eventSequence'], 'completionRef' => $completionRef];
    }

    private function normalOwnsPacket(GatewayModelRequest $packet): bool
    {
        $scope = $this->normalOperation;
        return $scope !== null && !$this->normalViolated && $scope['phase'] === 'NORMAL_RUNNING'
            && $scope['frame']['command'] === 'execute_owned' && $scope['frame']['requestRef'] === $packet->requestRef
            && time() < $scope['frame']['expiresAt'] && $packet->expiresAt <= $scope['frame']['expiresAt'];
    }

    public function normalGatewayPins(AuthenticatedPublicCoreChannel $gateway): ?array
    {
        $scope = $this->normalOperation;
        if ($scope === null || $this->normalViolated || $scope['phase'] !== 'NORMAL_RUNNING'
            || $scope['frame']['command'] !== 'execute_owned' || time() >= $scope['frame']['expiresAt']
            || $scope['channel']->peer() !== $scope['peer'] || $scope['channel']->channelRef() === $gateway->channelRef()
            || $scope['peer'] === $gateway->peer()) { return null; }
        return ['appPeer' => $scope['peer'], 'appChannelRef' => $scope['channel']->channelRef(),
            'gatewayPeer' => $gateway->peer(), 'gatewayChannelRef' => $gateway->channelRef()];
    }

    public function normalDispatchExpiry(): ?int
    {
        return $this->normalOperation !== null && !$this->normalViolated
            && $this->normalOperation['phase'] === 'NORMAL_RUNNING' && $this->normalOperation['frame']['command'] === 'execute_owned'
            ? $this->normalOperation['frame']['expiresAt'] : null;
    }

    public function normalControlSequence(PublicCoreDispatchAuthority $authority, GatewayModelRequest $packet): ?int
    {
        return $this->normalOwnsPacket($packet) && $this->ownsGatewayTransfer($authority, $packet)
            ? $this->normalOperation['receivedSequence'] : null;
    }

    public function exchangeNormalControl(string $command, array $payload, ?GatewayModelRequest $packet, int $expiresAt): array
    {
        $transfer = $this->gatewayTransfer;
        if ($packet === null || $transfer === null || !$this->normalOwnsPacket($packet)
            || !$this->ownsGatewayTransfer($transfer['authority'], $packet) || $expiresAt !== $packet->expiresAt
            || !in_array($command, ['check_binding', 'authorize_write', 'upload_complete'], true)) {
            throw new \LogicException('receipt_changed');
        }
        $scope = $this->normalOperation;
        $this->normalOperation['phase'] = 'UPLOAD_CONTROL';
        try {
            $scope['channel']->send($command, $packet->requestRef, $packet->attemptRef, $payload, $expiresAt);
            $frame = $scope['channel']->receive();
            if ($scope['channel']->peer() !== $scope['peer'] || $frame['channelRef'] !== $scope['channel']->channelRef()
                || $frame['sequence'] !== $scope['receivedSequence'] + 1 || $frame['expiresAt'] !== $expiresAt
                || $frame['requestRef'] !== $packet->requestRef || $frame['attemptRef'] !== $packet->attemptRef
                || $frame['command'] !== match ($command) {
                    'check_binding' => 'binding', 'authorize_write' => 'write_authorized', 'upload_complete' => 'uploaded',
                }) { throw new \LogicException('receipt_changed'); }
            $this->normalOperation['receivedSequence'] = $frame['sequence'];
            return ['frame' => $frame, 'peer' => $scope['peer']];
        } catch (Throwable $error) {
            $this->normalViolated = true;
            throw $error;
        } finally {
            $this->normalOperation['phase'] = 'NORMAL_RUNNING';
        }
    }

    public function acceptsGatewayCleanupFrame(PublicCoreDispatchAuthority $authority, GatewayModelRequest $packet, array $frame): bool
    {
        return $this->ownsGatewayTransfer($authority, $packet) && $this->gatewayTransfer['channel']->isCleanupOnly()
            && $this->gatewayTransfer['cleanupDeadline'] !== null && hrtime(true) < $this->gatewayTransfer['cleanupDeadline']
            && $frame === $this->gatewayTransfer['frame'] && $frame['command'] === 'abort'
            && $this->gatewayTransfer['event'] === 'stopped' && $frame['sequence'] === $this->gatewayTransfer['eventSequence'];
    }

    private function ownsGatewayTransfer(PublicCoreDispatchAuthority $authority, GatewayModelRequest $packet): bool
    {
        return $this->gatewayTransfer !== null && $this->gatewayTransfer['authority'] === $authority
            && $this->gatewayTransfer['packet'] === $packet && $this->gatewayTransfer['binding'] === $packet->binding()
            && $authority->matchesGatewayChannel($this->gatewayTransfer['channel']);
    }

    private function readGatewayLifecycle(PublicCoreDispatchAuthority $authority, GatewayModelRequest $packet): ?array
    {
        if (!$this->ownsGatewayTransfer($authority, $packet)) {
            return null;
        }
        $channel = $this->gatewayTransfer['channel'];
        if ($channel->isCleanupOnly() && (!$this->gatewayTransfer['cancelSent']
            || $this->gatewayTransfer['cleanupDeadline'] === null || hrtime(true) >= $this->gatewayTransfer['cleanupDeadline'])) {
            return null;
        }
        $state = $channel->gatewayLifecycle();
        if (!GatewayModelRequest::hasExactKeys($state, ['schemaVersion', 'channelRef', 'binding', 'state', 'eventSequence', 'reasonCode', 'bodyLength'])
            || $state['schemaVersion'] !== 'public-core-processor-gateway-lifecycle/1' || $state['channelRef'] !== $channel->channelRef()
            || GatewayModelRequest::canonicalJson($state['binding']) !== GatewayModelRequest::canonicalJson($packet->binding())
            || $state['bodyLength'] !== strlen($packet->bodyBytes) || $channel->gatewayOuterExpiry() !== $packet->expiresAt
            || $channel->gatewayDeadline() !== $this->gatewayTransfer['genuineDeadline']
            || !in_array($state['state'], ['pending', 'uploaded', 'stopped'], true)) {
            return null;
        }
        $frame = $this->gatewayTransfer['frame'];
        if ($state['state'] === 'pending') {
            if ($state['eventSequence'] !== null || $state['reasonCode'] !== 'none' || $this->gatewayTransfer['event'] !== 'pending') {
                return null;
            }
        } else {
            if (!is_int($state['eventSequence']) || $state['eventSequence'] < 2 || $frame === null
                || $state['eventSequence'] !== $frame['sequence'] || $frame['channelRef'] !== $channel->channelRef()
                || $frame['requestRef'] !== $packet->requestRef || $frame['attemptRef'] !== $packet->attemptRef
                || $frame['expiresAt'] !== $packet->expiresAt
                || $frame['command'] !== ($state['state'] === 'uploaded' ? 'upload_complete' : 'abort')
                || ($state['state'] === 'uploaded' && $state['reasonCode'] !== 'none')
                || ($state['state'] === 'stopped' && (!in_array($state['reasonCode'], GatewayModelResponse::REASON_CODES, true) || $state['reasonCode'] === 'none'))
                || ($this->gatewayTransfer['event'] !== 'pending' && ($this->gatewayTransfer['event'] !== $state['state']
                    || $this->gatewayTransfer['eventSequence'] !== $state['eventSequence']))) {
                return null;
            }
            $this->gatewayTransfer['event'] = $state['state'];
            $this->gatewayTransfer['eventSequence'] = $state['eventSequence'];
        }
        return ['qualification' => $this->gatewayTransfer['qualification'], 'channelRef' => $channel->channelRef(),
            'transferRef' => $this->gatewayTransfer['transferRef'], 'requestRef' => $packet->requestRef,
            'attemptRef' => $packet->attemptRef, 'projectionDigest' => $packet->projectionDigest, 'event' => $state['state']];
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
            || !GatewayModelRequest::hasExactKeys($result, ['status', 'reasonCode', 'request_ref', 'reply', 'trace', 'transportAllowed', 'actual_model', 'tools', 'sources'])
            || array_keys($result) !== ['status', 'reasonCode', 'request_ref', 'reply', 'trace', 'transportAllowed', 'actual_model', 'tools', 'sources']
            || $result['status'] !== 'completed' || $result['reasonCode'] !== 'none' || $result['request_ref'] !== $request['requestRef']
            || ($profile->isActualProfile() && $result['actual_model'] === null)
            || (!$profile->isActualProfile() && $result['actual_model'] !== null)
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
            $binding = ['schemaVersion' => 'public-core-result-binding/1', 'requestRef' => $request['requestRef'],
                'sessionRef' => $request['sessionRef'], 'processRef' => $this->processRef, 'ownerDigest' => $session['ownerDigest'],
                'profileFingerprint' => $profile->fingerprint(), 'registryDigest' => $source['registryDigest'],
                'manifestGenerationRef' => $source['manifestGenerationRef'], 'runtimeGenerationRef' => $source['runtimeGenerationRef'],
                'runtimeInstanceRef' => $instance['instanceRef'], 'resultDigest' => hash('sha256', RegisteredPublicFixtureRegistry::canonical($result))];
            PublicCoreRuntimeResource::completedEvidence($result);

            return $binding;
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
