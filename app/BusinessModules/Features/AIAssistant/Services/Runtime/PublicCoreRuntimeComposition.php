<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Runtime;

use App\BusinessModules\Features\AIAssistant\Http\Resources\PublicCoreRuntimeResource;
use App\BusinessModules\Features\AIAssistant\Services\Context\AssistantContextSourceBinding;
use App\BusinessModules\Features\AIAssistant\Services\Context\AssistantModelContextProfile;
use App\BusinessModules\Features\AIAssistant\Services\Context\AssistantSafeContextSegment;
use App\BusinessModules\Features\AIAssistant\Services\Loop\AssistantContextReceipt;
use App\BusinessModules\Features\AIAssistant\Services\Loop\AssistantLoopLimits;
use App\BusinessModules\Features\AIAssistant\Services\Loop\AssistantToolResult;
use App\BusinessModules\Features\AIAssistant\Services\Rag\MaterialSearch\MaterialSearchQuery;
use App\BusinessModules\Features\AIAssistant\Services\Rag\MaterialSearch\MaterialSearchResult;
use App\BusinessModules\Features\AIAssistant\Services\Rag\MaterialSearch\MaterialSearchService;
use App\BusinessModules\Features\AIAssistant\Services\Rag\MaterialSearch\SyntheticMaterialSearchCorpus;
use App\Services\Privacy\Contracts\AuthenticatedPrivateContext;
use App\Services\Privacy\Gateway\Contracts\GatewayModelProfile;
use App\Services\Privacy\Gateway\Contracts\GatewayModelRequest;
use App\Services\Privacy\PublicCore\PublicCoreReceiptStore;
use App\Services\Privacy\PublicCore\PublicCoreRuntimeReadiness;
use App\Services\Privacy\PublicCore\PublicCoreSessionAuthority;
use App\Services\Privacy\PublicCore\RegisteredPublicFixtureRegistry;
use Closure;
use App\Services\Privacy\PublicCore\PublicCoreProcessor;
use App\Services\Privacy\PublicCore\PublicCoreDispatchAuthority;
use App\Services\Privacy\PublicCore\Transport\AuthenticatedPublicCoreChannel;
use LogicException;

final class PublicCoreRuntimeComposition
{
    private array $request = [];
    private array $viewerBinding = [];
    private array $viewer = [];
    private array $snapshot = [];
    private array $artifacts = [];
    private array $labels = [];
    private array $aliases = [];
    private array $sources = [];
    private array $receipts = [];
    private string $generation;
    private ?SyntheticMaterialSearchCorpus $corpus = null;
    private ?PublicCoreSessionAuthority $sessions = null;
    private ?PublicCoreRuntimeReadiness $readiness = null;
    private ?PublicCoreReceiptStore $publisher = null;
    private ?GatewayModelProfile $profile = null;

    public function __construct(private readonly Closure $driverFactory, private readonly Closure $semanticValidator)
    {
        $this->generation = 'ref_'.bin2hex(random_bytes(16));
    }

    public static function nativeProcessor(PublicCoreReceiptStore $store, PublicCoreRuntimeReadiness $readiness,
        Closure $peerSource, Closure $gatewayChannelFactory, Closure $uploadBounds, Closure $semanticValidator): PublicCoreProcessor
    {
        $registry = RegisteredPublicFixtureRegistry::compiled();
        $processor = null;
        $viewerSource = static function (array $binding) use (&$processor): ?array { return $processor?->currentNormalViewer($binding); };
        $sessions = new PublicCoreSessionAuthority($registry, $store, $viewerSource);
        $factory = static function (GatewayModelProfile $profile, PublicCoreReceiptStore $publisher, array $request,
            array $viewer, Closure $sourceState, Closure $privateBinding) use (&$processor, $sessions, $readiness,
            $gatewayChannelFactory, $uploadBounds): PublicCoreGatewayModelDriver {
            if ($processor === null || !$profile->isActualProfile()) { throw new LogicException('runtime_not_activated'); }
            $attemptFactory = static function (GatewayModelProfile $profile) use (&$processor, $publisher, $request, $viewer,
                $sourceState, $sessions, $readiness, $gatewayChannelFactory, $uploadBounds): array {
                $expiry = $processor->normalDispatchExpiry();
                if ($expiry === null || $expiry <= time() || $readiness->currentProfileFingerprint() !== $profile->fingerprint()) {
                    throw new LogicException('profile_changed');
                }
                $channel = $gatewayChannelFactory($profile, $request, $expiry);
                if (!$channel instanceof AuthenticatedPublicCoreChannel) { throw new LogicException('gateway_identity_unavailable'); }
                try {
                    $pins = $processor->normalGatewayPins($channel);
                    if ($pins === null || $expiry > $channel->deadlineExpiresAt()) { throw new LogicException('gateway_identity_unavailable'); }
                    $authority = new PublicCoreDispatchAuthority($publisher, $readiness, $sessions, $viewer, $request['requestRef'],
                        $sourceState, $processor->exchangeNormalControl(...), null, null, null, $uploadBounds, $pins,
                        $processor->normalDispatchExpiry(...), $processor->normalDispatchExpiry(...));
                    return ['dispatch' => $authority, 'channel' => $channel];
                } catch (\Throwable $failure) { $channel->close(); throw $failure; }
            };
            return new PublicCoreGatewayModelDriver($profile, null, null, $privateBinding, $processor, null, $attemptFactory);
        };
        $composition = new self($factory, $semanticValidator);
        $processor = new PublicCoreProcessor($registry, $store, $sessions, $readiness, $peerSource,
            static fn (string $ticket, array $peer): array => ['viewerTicketRef' => $ticket],
            $composition(...), $composition->currentRuntime(...));
        return $processor;
    }

    public function __invoke(array $request, array $viewer, PublicCoreReceiptStore $store,
        PublicCoreSessionAuthority $sessions, PublicCoreRuntimeReadiness $readiness, ?object $previous = null): array
    {
        $runtime = $previous ?? new self($this->driverFactory, $this->semanticValidator);
        if (!$runtime instanceof self || $runtime->driverFactory !== $this->driverFactory
            || $runtime->semanticValidator !== $this->semanticValidator) {
            throw new LogicException('source_changed');
        }

        return $runtime->compose($request, $viewer, $sessions, $readiness);
    }

    public function sourceState(): array
    {
        $this->assertCurrent();

        return ['registryDigest' => RegisteredPublicFixtureRegistry::compiled()->manifestDigest(),
            'manifestGenerationRef' => $this->request['registered']['source_generation_ref'],
            'runtimeGenerationRef' => $this->generation];
    }

    public function currentRuntime(object $runtime, array $request, GatewayModelProfile $profile): ?array
    {
        if (!$runtime instanceof self || $runtime->request['requestRef'] !== $request['requestRef']
            || $runtime->request['sessionRef'] !== $request['sessionRef']
            || $runtime->profile?->fingerprint() !== $profile->fingerprint()) {
            return null;
        }

        return $runtime->sourceState();
    }

    private function compose(array $request, array $viewer, PublicCoreSessionAuthority $sessions,
        PublicCoreRuntimeReadiness $readiness): array
    {
        $profile = $readiness->qualifiedProfile();
        $registered = RegisteredPublicFixtureRegistry::compiled()->resolve($request['selection']['fixture_id'] ?? '',
            $request['selection']['fixture_version'] ?? '', $request['selection']['input_id'] ?? '');
        $current = $sessions->lookup($viewer, $request['requestRef'] ?? '');
        $authorization = $sessions->currentViewer($viewer);
        if ($profile === null || $current === null || $authorization === null
            || array_intersect_key($current, array_flip(['requestRef', 'sessionRef', 'selection', 'registered', 'conversationRef', 'issuedAt', 'expiresAt']))
                !== array_intersect_key($request, array_flip(['requestRef', 'sessionRef', 'selection', 'registered', 'conversationRef', 'issuedAt', 'expiresAt'])) || $registered === null
            || $request['registered'] !== $registered
            || ($this->request !== [] && ($this->request['sessionRef'] !== $request['sessionRef']
                || $this->viewer !== $authorization || $this->profile?->fingerprint() !== $profile->fingerprint()))) {
            throw new LogicException('authorization_changed');
        }
        $history = $this->snapshot['conversation']['historyRefs'] ?? [];
        if ($this->request !== []) {
            $previous = $sessions->lookup($this->viewerBinding, $this->request['requestRef']);
            if (($previous['execution']['status'] ?? null) !== 'completed'
                || !is_string($previous['execution']['result']['reply'] ?? null)) { throw new LogicException('source_changed'); }
            $history[] = $this->snapshot['conversation']['currentRef'];
            $history[] = $this->addArtifact('assistant', $previous['execution']['result']['reply'], 'Предыдущий публичный ответ');
        }
        if (count($history) > 48) { throw new LogicException('budget_exceeded'); }
        $this->request = $request;
        $this->viewerBinding = $viewer;
        $this->viewer = $authorization;
        $this->sessions = $sessions;
        $this->readiness = $readiness;
        $this->profile = $profile;
        $this->aliases = [];
        $this->sources = [];
        $this->receipts = [];
        $this->corpus ??= $registered['fixture_id'] === 'material-search-v1'
            ? SyntheticMaterialSearchCorpus::registered($registered['fixture_id'], $registered['fixture_version'], $registered['input_id'])
            : SyntheticMaterialSearchCorpus::registered('material-search-v1', 'public-material/1', 'price-b25');
        $principal = $this->corpus->context();
        $scope = ['actor' => 'PUBLIC_ACTOR/7', 'tenant' => 'PUBLIC_TENANT/11', 'project' => 'PUBLIC_PROJECT/13',
            'acl' => 'material-search-fixture-acl/1', 'consent' => 'registered-public-only/1', 'policy' => $principal->policyVersion()];
        $this->snapshot = ['authorized' => true, 'adapterRevision' => $profile->values()['adapterRevision'], 'scope' => $scope,
            'conversation' => ['ref' => $request['conversationRef'], 'currentRef' => '', 'historyRefs' => $history,
                'systemRefs' => [], 'mediaRefs' => []], 'sources' => $this->snapshot['sources'] ?? []];
        foreach ($this->snapshot['sources'] as &$source) { $source['conversationRef'] = $request['conversationRef']; }
        unset($source);
        $system = $this->addArtifact('system', 'Работай только с зарегистрированным публичным каталогом. Не используй приватные данные. '
            .'Для цен вызывай material.search, проверяй claims и текущие sourceRefs. Отвечай по-русски.', 'Публичные правила');
        $this->snapshot['conversation']['systemRefs'] = [$system];
        if ($registered['fixture_id'] === 'public-photo-metadata-v1') {
            $records = RegisteredPublicFixtureRegistry::compiled()->records($registered['fixture_id'], $registered['fixture_version']);
            if (!is_array($records) || count($records) !== 1 || !is_string($records[0]['text'] ?? null)) { throw new LogicException('source_unavailable'); }
            $this->snapshot['conversation']['historyRefs'][] = $this->addArtifact('user', $records[0]['text'], 'Публичная учебная расшифровка');
            $history = $this->snapshot['conversation']['historyRefs'];
        }
        $this->snapshot['conversation']['currentRef'] = $this->addArtifact('user', $registered['display_text'], 'Зарегистрированный публичный запрос');
        $this->publisher = $sessions->publisher($viewer, $request['requestRef'], fn (): array => $this->coreBinding());
        $tokenizer = $readiness->count(...);
        $context = PublicCoreContextBindings::processorContext($profile, fn (): array => $this->currentSnapshot(),
            fn (string $ref): ?array => $this->artifacts[$ref] ?? null, $tokenizer,
            function (string $event, array $data, array $expected = []): array {
                if ($event === 'stage') {
                    foreach ($data['sources'] as $source) {
                        if (($this->snapshot['sources'][$source['sourceRef']] ?? null) !== $source['source']) { return []; }
                    }
                    foreach ($data['aliases'] as $alias) {
                        $segment = AssistantSafeContextSegment::project($alias['artifactRef'], $this->currentSnapshot(),
                            fn (string $ref): ?array => $this->artifacts[$ref] ?? null);
                        if ($segment->kind() !== $alias['kind'] || $segment->fields() !== $alias['fields']
                            || $segment->metadata() !== $alias['metadata']) { return []; }
                    }
                    $this->aliases = $data['aliases'];
                    $this->sources = $data['sources'];
                }
                $ack = $this->publisher->publish($event, $data, $expected);
                if ($event === 'commit' && ($ack['status'] ?? null) === 'committed') {
                    $this->receipts[$data['contextRef']] = ['status' => 'committed', 'receipt' => $data, 'digest' => $expected['receiptDigest']];
                }
                if ($event === 'abort') { unset($this->receipts[$data['contextRef']]); }
                return $ack;
            });
        $authority = fn (?string $ref): array => $this->authority($ref);
        $privateBinding = function (string $ref) use ($authority): array {
            $state = $authority($ref);
            if ($state['stored'] === null) { throw new LogicException('receipt_unavailable'); }
            return $state + ['receipt' => $state['stored']['receipt']];
        };
        $driver = ($this->driverFactory)($profile, $this->publisher, $request, $viewer,
            $this->sourceState(...), $privateBinding);
        if (!$driver instanceof PublicCoreGatewayModelDriver) { throw new LogicException('gateway_not_configured'); }
        $search = new MaterialSearchService($this->corpus);
        $tools = PublicCoreContextBindings::processorTools(
            function (string $tool, array $arguments, AuthenticatedPrivateContext $principal) use ($search): MaterialSearchResult {
                $this->assertCurrent();
                return match ($tool) {
                    'material.search' => $search->search($principal, new MaterialSearchQuery($arguments['query'], $arguments['limit'])),
                    'material.read_selected' => $search->readSelected($principal, $arguments['ref']),
                    default => throw new LogicException('source_unavailable'),
                };
            },
            function (array $envelope): array {
                $this->assertCurrent();
                $ref = $this->addArtifact('tool', GatewayModelRequest::canonicalJson([
                    'facts' => $envelope['facts'], 'coverage' => $envelope['coverage'],
                ]), 'Публичный каталог материалов');
                $this->snapshot['conversation']['historyRefs'][] = $ref;
                $map = [];
                foreach ($envelope['nextSafeRefs'] as $selected) { $map['ref_'.bin2hex(random_bytes(16))] = $selected; }
                return ['artifactRef' => $ref, 'requestRef' => $envelope['requestRef'], 'profileRef' => $envelope['profileRef'],
                    'profileVersion' => $envelope['profileVersion'], 'generationRef' => $envelope['resultGenerationRef'], 'referenceMap' => $map];
            },
            function (array $binding, string $tool, array $arguments, ?array $evidence = null): ?AuthenticatedPrivateContext {
                $this->assertCurrent();
                return $this->request['registered']['fixture_id'] === 'material-search-v1'
                    && $binding['receipt']['scope'] === $this->snapshot['scope'] && $this->materialAllowed($evidence)
                    ? $this->corpus->context() : null;
            });
        $clock = static fn (): int => intdiv(hrtime(true), 1000000);
        $finalGuard = function (array $binding, array $conditions, array $evidence) use ($clock, $authority): ?array {
            $this->assertCurrent();
            foreach ($evidence as $result) { if (!$this->materialAllowed($result)) { return null; } }
            return ['authority' => $authority($binding['receipt']['contextRef']), 'now' => $clock(), 'privateContext' => $this->corpus->context()];
        };
        $completion = function (array $action, AssistantContextReceipt $receipt, array $results, array $trace) use ($driver): array {
            $this->assertCurrent();
            $evidence = self::completedEvidence($driver, $action, $receipt, $results, $trace, $this->labels);
            return $evidence;
        };
        $loop = PublicCoreContextBindings::processorLoop($context, $authority, $tokenizer, $driver(...), $tools,
            PublicCoreContextBindings::processorResponseValidator($this->semanticValidator), $clock, $finalGuard,
            new AssistantLoopLimits(12, 8, 2, 30000, 200000), $completion);

        return ['loop' => $loop, 'profileRef' => $profile->values()['profileRef'],
            'refs' => ['currentRef' => $this->snapshot['conversation']['currentRef'], 'systemRefs' => [$system],
                'historyRefs' => $history, 'mediaRefs' => [], 'summaryRef' => null, 'taskFrameRef' => null],
            'sourceState' => $this->sourceState(...), 'runtime' => $this];
    }

    public static function completedEvidence(PublicCoreGatewayModelDriver $driver, array $action,
        AssistantContextReceipt $receipt, array $results, array $trace, array $labels): array
    {
        $tools = [];
        foreach ($results as $result) {
            if (!$result instanceof AssistantToolResult) { throw new LogicException('invalid_model_output'); }
            $call = $result->privateCall();
            $label = match ($call['tool']) {
                'material.search' => 'Поиск публичных материалов',
                'material.read_selected' => 'Чтение выбранного публичного материала',
                default => throw new LogicException('invalid_model_output'),
            };
            $tools[] = ['label' => $label, 'call_ref' => $result->evidence()['callBinding']['callRef']];
        }
        $sources = [];
        $private = $receipt->privateBinding()['receipt'];
        foreach ($action['sourceRefs'] as $ref) {
            $source = $private['sources'][$ref] ?? null;
            if ($source === null || !in_array($source['source']['class'], ['public', 'synthetic'], true)
                || !is_string($labels[$source['sourceRef']] ?? null)) { throw new LogicException('source_unavailable'); }
            $sources[] = ['ref' => $ref, 'label' => $labels[$source['sourceRef']]];
        }

        return PublicCoreRuntimeResource::completedEvidence(['status' => 'completed', 'actual_model' => $driver->actualModel(),
            'tools' => $tools, 'sources' => $sources, 'trace' => $trace]);
    }

    private function assertCurrent(): void
    {
        $row = RegisteredPublicFixtureRegistry::compiled()->resolve($this->request['selection']['fixture_id'] ?? '',
            $this->request['selection']['fixture_version'] ?? '', $this->request['selection']['input_id'] ?? '');
        if ($this->sessions === null || $this->viewer === [] || $this->sessions->currentViewer($this->viewerBinding) !== $this->viewer
            || $this->profile === null || $this->readiness?->currentProfileFingerprint() !== $this->profile->fingerprint()
            || $row !== ($this->request['registered'] ?? null) || $this->corpus === null
            || $this->corpus->guard($this->corpus->context()) !== null) { throw new LogicException('authorization_changed'); }
    }

    private function currentSnapshot(): array
    {
        $this->assertCurrent();
        return $this->snapshot;
    }

    private function coreBinding(): array
    {
        $snapshot = $this->currentSnapshot();
        $profile = PublicCoreContextBindings::coreProfile($this->profile);
        $trusted = AssistantModelContextProfile::resolve($profile['profileRef'], static fn (): array => $profile);
        return ['scope' => $snapshot['scope'], 'snapshotHash' => AssistantContextSourceBinding::snapshotHash($snapshot),
            'profileFingerprint' => $trusted->fingerprint(), 'registryDigest' => RegisteredPublicFixtureRegistry::compiled()->manifestDigest(),
            'aliases' => $this->aliases, 'sources' => $this->sources, 'trustedModelProfile' => $profile];
    }

    private function authority(?string $ref): array
    {
        $snapshot = $this->currentSnapshot();
        $saved = $ref === null ? null : ($this->receipts[$ref] ?? null);
        return ['snapshot' => $snapshot, 'profile' => PublicCoreContextBindings::coreProfile($this->profile),
            'lineage' => ['requestRef' => $this->request['requestRef'], 'requestRevision' => $this->request['requestRevision'],
                'conversationRef' => $this->request['conversationRef'], 'issuedAt' => $this->request['issuedAt'],
                'expiresAt' => $this->request['expiresAt'], 'now' => time()],
            'stored' => $saved,
            'artifacts' => $this->artifacts];
    }

    private function materialAllowed(?array $evidence): bool
    {
        if ($evidence === null) { return true; }
        foreach ($evidence['envelope']['facts'] as $fact) {
            if ($fact['kind'] === 'price' && !$this->corpus->priceAllowed($this->corpus->context())) { return false; }
        }
        foreach ($evidence['envelope']['nextSafeRefs'] as $ref) {
            if (!$this->corpus->recordAllowed($this->corpus->context(), $ref)) { return false; }
        }
        return true;
    }

    private function addArtifact(string $kind, string $text, string $label): string
    {
        if (count($this->artifacts) >= 128) { throw new LogicException('budget_exceeded'); }
        $ref = 'ref_'.bin2hex(random_bytes(16));
        $source = 'ref_'.bin2hex(random_bytes(16));
        $field = 'ref_'.bin2hex(random_bytes(16));
        $bindings = [['sourceRef' => $source, 'fieldRefs' => [$field]]];
        $metadata = [];
        $this->artifacts[$ref] = ['artifactRef' => $ref, 'adapterRevision' => $this->profile->values()['adapterRevision'],
            'kind' => $kind, 'text' => $text, 'bindings' => $bindings, 'metadata' => $metadata];
        $this->snapshot['sources'][$source] = ['version' => $this->request['registered']['fixture_version'],
            'creator' => 'registered-public-authority/1', 'provenance' => $this->request['registered']['fixture_id'],
            'class' => 'synthetic', 'scope' => $this->snapshot['scope'], 'conversationRef' => $this->request['conversationRef'],
            'fields' => [$field => ['hash' => hash('sha256', $text), 'provenance' => 'registered-public-projection/1',
                'artifacts' => [$ref => ['contentHash' => hash('sha256', $text), 'kind' => $kind,
                    'bindingHash' => hash('sha256', AssistantContextSourceBinding::canonical($bindings)),
                    'metadataHash' => hash('sha256', AssistantContextSourceBinding::canonical($metadata))]]]]];
        $this->labels[$source] = $label;
        return $ref;
    }
}
