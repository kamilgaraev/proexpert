<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\Loop;

use App\BusinessModules\Features\AIAssistant\Services\Context\AssistantContextSourceBinding;
use App\BusinessModules\Features\AIAssistant\Services\Loop\AssistantLocalLoop;
use App\BusinessModules\Features\AIAssistant\Services\Loop\AssistantLoopLimits;
use App\BusinessModules\Features\AIAssistant\Services\Loop\AssistantLoopResponseValidator;
use App\BusinessModules\Features\AIAssistant\Services\Loop\AssistantToolResultAdapter;
use App\BusinessModules\Features\AIAssistant\Services\Rag\MaterialSearch\MaterialSearchQuery;
use App\BusinessModules\Features\AIAssistant\Services\Rag\MaterialSearch\MaterialSearchResult;
use App\BusinessModules\Features\AIAssistant\Services\Rag\MaterialSearch\MaterialSearchService;
use App\BusinessModules\Features\AIAssistant\Services\Rag\MaterialSearch\SyntheticMaterialSearchCorpus;
use App\Services\Privacy\Contracts\AuthenticatedPrivateContext;
use Tests\Unit\AIAssistant\Context\OfflineContextFixtures;

final class OfflineLoopFixtures
{
    public OfflineContextFixtures $context;
    public SyntheticMaterialSearchCorpus $corpus;
    public MaterialSearchService $search;
    public array $actions = [];
    public array $driverInputs = [];
    public array $executed = [];
    public array $evidence = [];
    public array $countedPayloads = [];
    public array $validatedActions = [];
    public int $driverCalls = 0;
    public int $projected = 0;
    public int $now = 1000;
    public ?\Closure $onDriver = null;
    public ?\Closure $onAuthority = null;
    public ?\Closure $onExecute = null;
    public ?\Closure $onProject = null;
    public ?\Closure $onValidate = null;
    public ?\Closure $onClock = null;
    public ?\Closure $onTokenize = null;
    public ?\Closure $onFinalGuard = null;
    public bool $gateAllowed = true;
    public bool $projectionAvailable = true;
    public bool $validatorAvailable = true;
    public array $acceptedTexts = ['Бетон В25 стоит 7800.00 RUB за м³.', 'По выбранной фотографии поясняю второй пункт.',
        'Новая тема: выбранная запись склада.', 'В выбранном наборе найдена одна подходящая позиция.'];

    public function __construct()
    {
        $this->context = new OfflineContextFixtures(8);
        $this->corpus = SyntheticMaterialSearchCorpus::named('material-search-v1');
        $this->search = new MaterialSearchService($this->corpus);
        $scope = ['actor' => 'PRIVATE_ACTOR/7', 'tenant' => 'PRIVATE_TENANT/11',
            'project' => 'PRIVATE_PROJECT/13', 'acl' => 'material-search-fixture-acl/1',
            'consent' => 'consent/1', 'policy' => $this->corpus->context()->policyVersion()];
        $this->context->snapshot['scope'] = $scope;
        foreach ($this->context->snapshot['sources'] as &$source) {
            $source['scope'] = $scope;
        }
        unset($source);
    }

    public function authority(?string $contextRef): array
    {
        if ($this->onAuthority !== null) {
            ($this->onAuthority)($this, $contextRef);
        }
        if ($this->corpus->guard($this->corpus->context()) !== null) {
            $this->context->snapshot['authorized'] = false;
        }

        return [
            'snapshot' => $this->context->snapshot, 'profile' => $this->context->profile,
            'lineage' => $this->context->lineage,
            'stored' => $contextRef !== null ? ($this->context->receipts[$contextRef] ?? null) : null,
            'artifacts' => $this->context->artifacts,
        ];
    }

    public function adapter(): AssistantToolResultAdapter
    {
        return new AssistantToolResultAdapter(
            function (string $tool, array $arguments, AuthenticatedPrivateContext $principal): MaterialSearchResult {
                $this->executed[] = ['tool' => $tool, 'arguments' => $arguments];
                if ($this->onExecute !== null) {
                    ($this->onExecute)($this, $tool, $arguments);
                }

                return match ($tool) {
                    'material.search' => $this->search->search($principal, new MaterialSearchQuery($arguments['query'], $arguments['limit'])),
                    'material.read_selected' => $this->search->readSelected($principal, $arguments['ref']),
                    default => throw new \LogicException('unsupported_fixture_tool'),
                };
            },
            function (array $envelope, array $callBinding, array $privateBinding): ?array {
                if (!$this->projectionAvailable) {
                    return null;
                }
                $this->evidence[] = $envelope;
                $this->projected++;
                $artifactRef = 'tool-result-'.$this->projected;
                $safeFacts = ['fixtureUniverse' => 'material-search-v1', 'facts' => $envelope['facts'],
                    'coverage' => $envelope['coverage']];
                $text = json_encode($safeFacts, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $this->context->addArtifact($artifactRef, 'tool', $text);
                $this->context->snapshot['conversation']['historyRefs'][] = $artifactRef;
                $map = [];
                foreach ($envelope['nextSafeRefs'] as $ref) {
                    $map['ref_'.bin2hex(random_bytes(16))] = $ref;
                }
                $projection = ['artifactRef' => $artifactRef, 'requestRef' => $envelope['requestRef'],
                    'profileRef' => $envelope['profileRef'], 'profileVersion' => $envelope['profileVersion'],
                    'generationRef' => $envelope['resultGenerationRef'], 'referenceMap' => $map];
                if ($this->onProject !== null) {
                    return ($this->onProject)($this, $envelope, $projection);
                }

                return $projection;
            },
            function (array $privateBinding, string $tool, array $arguments, ?array $evidence = null): ?AuthenticatedPrivateContext {
                if (!$this->gateAllowed || $this->corpus->guard($this->corpus->context()) !== null
                    || $privateBinding['receipt']['scope'] !== $this->context->snapshot['scope']) {
                    return null;
                }
                if ($evidence !== null && !$this->materialEvidenceAllowed($evidence)) {
                    return null;
                }

                return $this->corpus->context();
            },
        );
    }

    public function validator(): AssistantLoopResponseValidator
    {
        return new AssistantLoopResponseValidator($this->validatorAvailable
            ? function (array $action, array $context, array $evidence): array {
                $this->validatedActions[] = $action;
                if ($this->onValidate !== null) {
                    return ($this->onValidate)($this, $action, $context, $evidence);
                }
                if (str_contains($action['text'], 'PRIVATE') || str_contains($action['text'], '/private/')) {
                    return ['status' => 'blocked', 'reason' => 'pii'];
                }
                if ($action['text'] === 'В выбранном scope подходящих позиций нет.') {
                    $latest = $evidence[array_key_last($evidence)] ?? null;

                    return ($latest['envelope']['status'] ?? null) === 'no_data'
                        && ($latest['envelope']['facts'] ?? null) === []
                        ? ['status' => 'valid', 'reason' => 'none']
                        : ['status' => 'repair', 'reason' => 'claims_invalid'];
                }

                return in_array($action['text'], $this->acceptedTexts, true)
                    ? ['status' => 'valid', 'reason' => 'none']
                    : ['status' => 'repair', 'reason' => 'claims_invalid'];
            } : null);
    }

    public function loop(?AssistantLoopLimits $limits = null): AssistantLocalLoop
    {
        return new AssistantLocalLoop(
            $this->context->service(),
            fn (?string $contextRef): array => $this->authority($contextRef),
            function (string $json, array $identity): array {
                $this->countedPayloads[] = $json;
                $count = $identity + ['tokens' => strlen($json)];

                return $this->onTokenize !== null ? ($this->onTokenize)($this, $json, $count) : $count;
            },
            function (array $input): array {
                $this->driverInputs[] = $input;
                $this->driverCalls++;
                if ($this->onDriver !== null) {
                    ($this->onDriver)($this, $input);
                }
                $action = $this->actions[$this->driverCalls - 1] ?? ['type' => 'plan', 'plan' => 'Continue local investigation.'];

                return $action instanceof \Closure ? $action($input, $this) : $action;
            },
            $this->adapter(), $this->validator(), $limits ?? new AssistantLoopLimits(),
            function (): int {
                if ($this->onClock !== null) {
                    ($this->onClock)($this);
                }

                return $this->now;
            },
            function (array $privateBinding, array $conditions, array $evidence): ?array {
                if ($this->onFinalGuard !== null) {
                    ($this->onFinalGuard)($this, $privateBinding, $conditions, $evidence);
                }
                foreach ($evidence as $result) {
                    if (!$this->materialEvidenceAllowed($result)) {
                        return null;
                    }
                }
                if (!$this->gateAllowed || $this->corpus->guard($this->corpus->context()) !== null) {
                    return null;
                }

                return ['authority' => $this->authority($privateBinding['receipt']['contextRef']),
                    'now' => $this->now, 'privateContext' => $this->corpus->context()];
            },
        );
    }

    public static function searchAction(int $limit = 1, string $query = 'бетон за м3'): array
    {
        return ['type' => 'tool', 'tool' => 'material.search', 'arguments' => ['query' => $query, 'limit' => $limit]];
    }

    public static function priceAnswer(array $input): array
    {
        $sourceRefs = [];
        foreach ($input['context']['messages'] as $message) {
            if ($message['role'] === 'tool') {
                $sourceRefs = $message['sourceRefs'];
            }
        }

        return ['type' => 'final', 'text' => 'Бетон В25 стоит 7800.00 RUB за м³.',
            'claims' => [['value' => '7800.00', 'unit' => 'm3', 'currency' => 'RUB', 'sourceRefs' => $sourceRefs]],
            'sourceRefs' => $sourceRefs, 'claimScope' => $input['toolReferences']['claimScope']];
    }

    private function materialEvidenceAllowed(array $evidence): bool
    {
        foreach ($evidence['envelope']['facts'] as $fact) {
            if ($fact['kind'] === 'price' && !$this->corpus->priceAllowed($this->corpus->context())) {
                return false;
            }
        }
        foreach ($evidence['envelope']['nextSafeRefs'] as $ref) {
            if (!$this->corpus->recordAllowed($this->corpus->context(), $ref)) {
                return false;
            }
        }

        return true;
    }

    public static function contextAnswer(array $input, string $text): array
    {
        $sources = [];
        foreach ($input['context']['messages'] as $message) {
            if ($message['ref'] === $input['context']['currentRef']
                || str_contains($message['content'], 'Синтетическое фото:')) {
                $sources = array_values(array_unique([...$sources, ...$message['sourceRefs']]));
            }
        }

        return ['type' => 'final', 'text' => $text, 'claims' => [],
            'sourceRefs' => $sources, 'claimScope' => $input['contextScope']];
    }
}
