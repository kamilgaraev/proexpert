<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Runtime;

use App\BusinessModules\Features\AIAssistant\Http\Resources\PublicCoreRuntimeResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use App\Services\Privacy\PublicCore\RegisteredPublicFixtureRegistry;
use App\Services\Privacy\PublicCore\Transport\AuthenticatedPublicCoreChannel;
use Closure;
use LogicException;

class PublicCoreAssistantRuntime
{
    private const REGISTRY_DIGEST = 'f6bfc3c523c792ab9a80dbe2c3a950aebec1dfad65456243bbaadfcdb50a85fe';

    private readonly RegisteredPublicFixtureRegistry $registry;
    private array $sourceDeliveries = [];
    private array $sourcePublications = [];
    private bool $sourcePreparing = false;
    private bool $sourceReentered = false;

    public function __construct(private readonly ?PublicCoreBackendAuthorityFence $sourceFence = null)
    {
        $this->registry = RegisteredPublicFixtureRegistry::compiled();
    }

    public function serveAppProcessorBootstrap(AuthenticatedPublicCoreChannel $channel, string $identityFile): void
    {
        $port = PublicCoreContextBindings::authenticatedAppControlPort($channel, $identityFile);
        $check = $port->receiveAppBootstrap();
        try {
            $binding = $this->sourceFence?->viewerTicketBinding($check['payload'], $check['expiresAt'])
                ?? throw new LogicException('authorization_changed');
        } catch (\Throwable $error) {
            $binding = ['schemaVersion' => 'public-core-app-viewer-ticket-denial/1',
                'viewerTicketRef' => $check['payload']['viewerTicketRef'],
                'reasonCode' => $error->getMessage() === 'expired' ? 'expired' : 'authorization_changed'];
        }
        $port->replyAppBootstrap($binding);
    }

    public function callNormalSource(PublicCoreContextBindings $port, string $command, array $input,
        int $rpcExpiresAt, ?int $requestOriginalExpiresAt = null): array
    {
        if ($this->sourceFence === null) { throw new LogicException('runtime_not_activated'); }
        return $port->callNormalSource($command, $input, $rpcExpiresAt, $this->sourceFence, $requestOriginalExpiresAt);
    }

    public function openSourceDelivery(array $context): string
    {
        $keys = ['viewerTicketRef', 'requestRef', 'sessionRef', 'channelRef', 'sequence', 'genuineExpiresAt'];
        if ($this->sourceFence === null || count($context) !== 6 || array_diff(array_keys($context), $keys) !== []
            || !is_int($context['sequence'] ?? null) || $context['sequence'] < 2
            || !is_int($context['genuineExpiresAt'] ?? null) || $context['genuineExpiresAt'] <= time()) {
            throw new LogicException('receipt_unavailable');
        }
        foreach (['viewerTicketRef', 'requestRef', 'sessionRef', 'channelRef'] as $key) {
            if (!PublicCoreRuntimeResource::opaqueRef($context[$key])) { throw new LogicException('receipt_unavailable'); }
        }
        $viewer = $this->sourceFence->viewerTicketBinding(['schemaVersion' => 'public-core-app-viewer-ticket-check/1',
            'viewerTicketRef' => $context['viewerTicketRef']], $context['genuineExpiresAt']);
        $reference = 'delivery_'.bin2hex(random_bytes(24));
        $this->sourceDeliveries[$reference] = ['context' => $context, 'viewer' => $viewer['currentViewer'],
            'operationRef' => null, 'publicationRef' => null, 'delivered' => false];

        return $reference;
    }

    public function sourcePublicationCallback(string $deliveryRef, ?PublicCoreContextBindings $port): Closure
    {
        return fn (array $guardInput, object $retainedRuntime, Closure $prepare): ?array =>
            $this->publishSourceEnvelope($deliveryRef, $port, $guardInput, $retainedRuntime, $prepare);
    }

    private function publishSourceEnvelope(string $deliveryRef, ?PublicCoreContextBindings $port, array $guardInput,
        object $retainedRuntime, Closure $prepare): ?array
    {
        $keys = ['schemaVersion', 'operationRef', 'viewerBinding', 'resultBinding', 'genuineExpiresAt', 'maxDurationMs'];
        $delivery = $this->sourceDeliveries[$deliveryRef] ?? null;
        if ($this->sourcePreparing) { $this->sourceReentered = true; return null; }
        if ($port === null || !$port->sourceOnly() || $delivery === null || $delivery['delivered']
            || $delivery['operationRef'] !== null || count($guardInput) !== 6 || array_diff(array_keys($guardInput), $keys) !== []
            || ($guardInput['schemaVersion'] ?? null) !== 'public-core-publication-operation/1'
            || !PublicCoreRuntimeResource::opaqueRef($guardInput['operationRef'] ?? null)
            || ($guardInput['viewerBinding'] ?? null) !== ['viewerTicketRef' => $delivery['context']['viewerTicketRef']]
            || ($guardInput['genuineExpiresAt'] ?? null) !== $delivery['context']['genuineExpiresAt']
            || $guardInput['genuineExpiresAt'] <= time() || !is_int($guardInput['maxDurationMs'] ?? null)
            || $guardInput['maxDurationMs'] <= 0
            || $guardInput['maxDurationMs'] > intdiv(PHP_INT_MAX - hrtime(true), 1000000)
            || !is_array($guardInput['resultBinding'] ?? null) || $port->sourceChannel() !== $delivery['context']['channelRef']
            || !$port->sourcePublicationMatches($guardInput, $retainedRuntime, 1)) {
            return null;
        }
        $binding = $guardInput['resultBinding'];
        if (($binding['requestRef'] ?? null) !== $delivery['context']['requestRef']
            || ($binding['sessionRef'] ?? null) !== $delivery['context']['sessionRef']) { return null; }
        $this->sourceDeliveries[$deliveryRef]['operationRef'] = $guardInput['operationRef'];
        $this->sourcePreparing = true;
        $this->sourceReentered = false;
        $deadline = hrtime(true) + $guardInput['maxDurationMs'] * 1000000;
        try {
            $candidate = $prepare();
            if ($this->sourceReentryObserved(1) || !is_array($candidate) || count($candidate) !== 3
                || array_diff(array_keys($candidate), ['binding', 'resultBytes', 'resultDigest']) !== []
                || $candidate['binding'] !== $binding || !is_string($candidate['resultBytes'] ?? null)
                || ($candidate['resultDigest'] ?? null) !== ($binding['resultDigest'] ?? null)) { return null; }
            $stage = PublicCoreRuntimeResource::stageCoreCompletedEnvelope($candidate['resultBytes'], $binding);
            $reference = 'publication_'.bin2hex(random_bytes(24));
            $record = ['binding' => $binding, 'viewerTicketRef' => $delivery['context']['viewerTicketRef'],
                'requestRef' => $delivery['context']['requestRef'], 'sessionRef' => $delivery['context']['sessionRef'],
                'operationRef' => $guardInput['operationRef'], 'deliveryRef' => $deliveryRef, 'publicationRef' => $reference,
                'resultDigest' => $stage['resultDigest'], 'envelopeDigest' => $stage['envelopeDigest'],
                'bodyBytes' => $stage['bodyBytes'], 'genuineExpiresAt' => $guardInput['genuineExpiresAt']];
            $viewer = $this->sourceFence->viewerTicketBinding(['schemaVersion' => 'public-core-app-viewer-ticket-check/1',
                'viewerTicketRef' => $delivery['context']['viewerTicketRef']], $delivery['context']['genuineExpiresAt']);
            if ($this->sourceReentryObserved(2) || $viewer['currentViewer'] !== $delivery['viewer']
                || !$port->sourcePublicationMatches($guardInput, $retainedRuntime, 2)
                || hrtime(true) >= $deadline || $guardInput['genuineExpiresAt'] <= time()) { return null; }
            $this->sourcePublications[$reference] = $record;
            $this->sourceDeliveries[$deliveryRef]['publicationRef'] = $reference;

            return ['schemaVersion' => 'public-core-result-publication/1', 'binding' => $binding, 'publicationRef' => $reference];
        } catch (\Throwable) {
            return null;
        } finally {
            $this->sourcePreparing = false;
        }
    }

    public function resolveSourcePublication(string $deliveryRef, array $receipt, array $frameContext): ?JsonResponse
    {
        $delivery = $this->sourceDeliveries[$deliveryRef] ?? null;
        if ($delivery === null || $delivery['delivered'] || $frameContext !== $delivery['context']
            || $frameContext['genuineExpiresAt'] <= time() || count($receipt) !== 3
            || array_diff(array_keys($receipt), ['schemaVersion', 'binding', 'publicationRef']) !== []
            || ($receipt['schemaVersion'] ?? null) !== 'public-core-result-publication/1'
            || ($receipt['publicationRef'] ?? null) !== $delivery['publicationRef']) { return null; }
        $record = $this->sourcePublications[$receipt['publicationRef']] ?? null;
        if ($record === null || ($receipt['binding'] ?? null) !== $record['binding'] || $record['deliveryRef'] !== $deliveryRef
            || $record['operationRef'] !== $delivery['operationRef'] || $record['genuineExpiresAt'] !== $frameContext['genuineExpiresAt']) { return null; }
        try {
            $viewer = $this->sourceFence->viewerTicketBinding(['schemaVersion' => 'public-core-app-viewer-ticket-check/1',
                'viewerTicketRef' => $record['viewerTicketRef']], $record['genuineExpiresAt']);
            if ($viewer['currentViewer'] !== $delivery['viewer']) { return null; }
            $response = PublicCoreRuntimeResource::committedEnvelopeResponse($record['bodyBytes'], $record['envelopeDigest']);
            $this->sourceDeliveries[$deliveryRef]['delivered'] = true;

            return $response;
        } catch (\Throwable) {
            return null;
        }
    }

    private function sourceReentryObserved(int $phase): bool
    {
        return in_array($phase, [1, 2], true) && $this->sourceReentered;
    }

    public function readiness(User $viewer, int $organizationId): array
    {
        $state = PublicCoreRuntimeResource::unavailable();
        if (!$this->registryAccepted()) {
            return PublicCoreRuntimeResource::unavailable('source_unavailable');
        }
        $fixtures = [];
        foreach ($this->registry->catalog() as $row) {
            if ($row['input_id'] !== $row['scenario_step']) {
                return PublicCoreRuntimeResource::unavailable('source_unavailable');
            }
            $id = $row['fixture_id'];
            $fixtures[$id] ??= ['fixture_id' => $id, 'fixture_version' => $row['fixture_version'],
                'label' => $row['display_text'], 'inputs' => []];
            $fixtures[$id]['inputs'][] = ['input_id' => $row['input_id'], 'label' => $row['display_text']];
        }
        $state['fixtures'] = array_values($fixtures);

        return $state;
    }

    public function submit(User $viewer, int $organizationId, array $command): array
    {
        if (!$this->registryAccepted() || !is_string($command['fixture_id'] ?? null)
            || !is_string($command['fixture_version'] ?? null) || !is_string($command['input_id'] ?? null)
            || $this->registry->resolve($command['fixture_id'], $command['fixture_version'], $command['input_id']) === null) {
            return PublicCoreRuntimeResource::blocked('source_unavailable');
        }

        return PublicCoreRuntimeResource::blocked();
    }

    public function poll(User $viewer, int $organizationId, string $requestRef): array|JsonResponse
    {
        return PublicCoreRuntimeResource::blocked();
    }

    public function dispatchOwnedRequest(string $requestRef): void
    {
        throw new LogicException('runtime_not_activated');
    }

    private function registryAccepted(): bool
    {
        return hash_equals(self::REGISTRY_DIGEST, $this->registry->manifestDigest());
    }
}
