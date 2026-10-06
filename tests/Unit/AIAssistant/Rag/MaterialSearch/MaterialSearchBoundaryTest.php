<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\Rag\MaterialSearch;

use App\BusinessModules\Features\AIAssistant\Services\Rag\MaterialSearch\MaterialSearchCorpus;
use App\BusinessModules\Features\AIAssistant\Services\Rag\MaterialSearch\MaterialSearchQuery;
use App\BusinessModules\Features\AIAssistant\Services\Rag\MaterialSearch\MaterialSearchRecord;
use App\BusinessModules\Features\AIAssistant\Services\Rag\MaterialSearch\MaterialSearchResult;
use App\BusinessModules\Features\AIAssistant\Services\Rag\MaterialSearch\MaterialSearchService;
use App\BusinessModules\Features\AIAssistant\Services\Rag\MaterialSearch\SyntheticMaterialSearchCorpus;
use App\Services\Privacy\Contracts\AuthenticatedPrivateContext;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MaterialSearchBoundaryTest extends TestCase
{
    public function testDefaultServiceIsBlockedForSearchAndRead(): void
    {
        $corpus = SyntheticMaterialSearchCorpus::named('material-search-v1');
        $service = new MaterialSearchService();
        $results = [$service->search($corpus->context(), new MaterialSearchQuery('бетон')),
            $service->readSelected($corpus->context(), $corpus->records()[0]->ref)];

        foreach ($results as $result) {
            self::assertSame('blocked', $result->localEnvelope()['status']);
            self::assertSame('privacy_not_ready', $result->localEnvelope()['reason']);
            self::assertSame([], $result->localEnvelope()['facts']);
            self::assertSame([], $result->localEnvelope()['nextSafeRefs']);
        }
    }

    public function testArbitraryCorpusWithAllowFlagCannotActivateProviderOrPrivateData(): void
    {
        $corpus = new class implements MaterialSearchCorpus {
            public int $calls = 0;
            public bool $isSafe = true;
            public function guard(AuthenticatedPrivateContext $context): ?string
            {
                $this->calls++;
                return null;
            }

            public function records(): array
            {
                $this->calls++;
                return [];
            }

            public function recordAllowed(AuthenticatedPrivateContext $context, string $ref): bool
            {
                $this->calls++;
                return true;
            }

            public function priceAllowed(AuthenticatedPrivateContext $context): bool
            {
                $this->calls++;
                return true;
            }

            public function scope(string $kind, array $unitRefs): array
            {
                $this->calls++;
                return [];
            }

            public function profileRef(): string
            {
                $this->calls++;
                return 'private';
            }

            public function profileVersion(): string
            {
                $this->calls++;
                return 'actual-model';
            }
        };
        $context = SyntheticMaterialSearchCorpus::named('material-search-v1')->context();
        $service = new MaterialSearchService($corpus);
        self::assertSame('blocked', $service->search($context, new MaterialSearchQuery('бетон'))->localEnvelope()['status']);
        self::assertSame('blocked', $service->readSelected($context, 'ref_' . str_repeat('a', 32))->localEnvelope()['status']);
        self::assertSame(0, $corpus->calls);
        self::assertFalse(class_exists('Illuminate\Foundation\Application', false));
    }

    public function testUnknownFixtureCannotLoadUserSuppliedCorpus(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SyntheticMaterialSearchCorpus::named('https://example.test/private-corpus?is_safe=true');
    }

    #[DataProvider('foreignContexts')]
    public function testPrincipalScopePolicyAndEpochAreRechecked(
        int $actor,
        int $org,
        ?int $project,
        string $policy,
        string $epoch,
        string $purpose,
    ): void {
        $corpus = SyntheticMaterialSearchCorpus::named('material-search-v1');
        $context = new AuthenticatedPrivateContext($actor, $org, $project, $policy, $epoch, $purpose);
        $service = new MaterialSearchService($corpus);
        $result = $service->search($context, new MaterialSearchQuery('бетон'))->localEnvelope();
        self::assertSame('blocked', $result['status']);
        self::assertSame('access_denied', $result['reason']);
        self::assertSame([], $result['facts']);
        self::assertSame('blocked', $service->readSelected($context, $corpus->records()[0]->ref)->localEnvelope()['status']);
    }

    public static function foreignContexts(): array
    {
        $policy = 'most-ai-v1-purpose-policy/0.2-product-approved-20261005';
        $epoch = 'material-search-fixture-acl/1';

        return [[8, 11, 13, $policy, $epoch, 'assistant_chat'], [7, 12, 13, $policy, $epoch, 'assistant_chat'],
            [7, 11, 14, $policy, $epoch, 'assistant_chat'], [7, 11, null, $policy, $epoch, 'assistant_chat'],
            [7, 11, 13, 'unknown/1', $epoch, 'assistant_chat'], [7, 11, 13, $policy, 'stale/1', 'assistant_chat'],
            [7, 11, 13, $policy, $epoch, 'other_purpose']];
    }

    #[DataProvider('invalidRefs')]
    public function testGuessedIdsAndUrlsCannotResolve(string $ref): void
    {
        $corpus = SyntheticMaterialSearchCorpus::named('material-search-v1');
        $result = (new MaterialSearchService($corpus))->readSelected($corpus->context(), $ref)->localEnvelope();
        self::assertSame('blocked', $result['status']);
        self::assertSame([], $result['facts']);
    }

    public static function invalidRefs(): array
    {
        return [['123'], ['ref_' . str_repeat('0', 32)], ['https://example.test/entity'], ['../private'], ['']];
    }

    public function testForeignGenerationAndRevokedRefAreDenied(): void
    {
        $first = SyntheticMaterialSearchCorpus::named('material-search-v1');
        $second = SyntheticMaterialSearchCorpus::named('material-search-v1');
        $service = new MaterialSearchService($second);
        self::assertSame('blocked', $service->readSelected($second->context(), $first->records()[0]->ref)->localEnvelope()['status']);
        $ref = $second->records()[0]->ref;
        $second->revokeRecord($ref);
        self::assertSame('blocked', $service->readSelected($second->context(), $ref)->localEnvelope()['status']);
        $search = $service->search($second->context(), new MaterialSearchQuery('бетон'))->localEnvelope();
        self::assertNotContains($ref, $search['nextSafeRefs']);
    }

    #[DataProvider('lateRevocations')]
    public function testLateRevocationDropsAlreadyAssembledFacts(int $check, string $action, string $reason): void
    {
        $corpus = SyntheticMaterialSearchCorpus::named('material-search-v1');
        $corpus->invalidateAtCheck($check, $action);
        $result = (new MaterialSearchService($corpus))->search($corpus->context(), new MaterialSearchQuery('бетон'))->localEnvelope();
        self::assertSame('blocked', $result['status']);
        self::assertSame($reason, $result['reason']);
        self::assertSame([], $result['facts']);
        self::assertSame([], $result['nextSafeRefs']);
    }

    public static function lateRevocations(): array
    {
        return [[2, 'scope', 'access_denied'], [3, 'scope', 'access_denied'], [4, 'scope', 'access_denied'],
            [2, 'source', 'source_stale'], [3, 'source', 'source_stale'], [4, 'source', 'source_stale']];
    }

    public function testScopeAndSourceRecheckedWhenReadingPreviousSearchRef(): void
    {
        $corpus = SyntheticMaterialSearchCorpus::named('material-search-v1');
        $service = new MaterialSearchService($corpus);
        $ref = $service->search($corpus->context(), new MaterialSearchQuery('бетон'))->localEnvelope()['nextSafeRefs'][0];
        $corpus->invalidateSource();
        self::assertSame('source_stale', $service->readSelected($corpus->context(), $ref)->localEnvelope()['reason']);
        $corpus = SyntheticMaterialSearchCorpus::named('material-search-v1');
        $service = new MaterialSearchService($corpus);
        $ref = $service->search($corpus->context(), new MaterialSearchQuery('бетон'))->localEnvelope()['nextSafeRefs'][0];
        $corpus->revokeScope();
        self::assertSame('access_denied', $service->readSelected($corpus->context(), $ref)->localEnvelope()['reason']);
    }

    public function testUnsealedResultCannotBeSerializedAsSafeObject(): void
    {
        $corpus = SyntheticMaterialSearchCorpus::named('material-search-v1');
        $result = (new MaterialSearchService($corpus))->search($corpus->context(), new MaterialSearchQuery('бетон'));
        $this->expectException(LogicException::class);
        serialize($result);
    }

    public function testCallerCannotAttachArbitraryEvidenceToNamedCorpus(): void
    {
        $corpus = SyntheticMaterialSearchCorpus::named('material-search-v1');
        $generation = $corpus->scope('search_subset', [])['sourceGenerationRef'];
        $injected = new MaterialSearchRecord('Arbitrary caller text', ['бетон'], 'm3', '1.00', 'RUB', 'm3', null, $generation);
        $this->expectException(LogicException::class);
        MaterialSearchResult::fromRecords('search', $corpus, [$injected], [], $corpus->context());
    }

    public function testUnknownToolVariantCannotEnterClosedEnvelope(): void
    {
        $this->expectException(LogicException::class);
        MaterialSearchResult::blocked('arbitrary-caller-tool', 'privacy_not_ready');
    }

    public function testResultBuilderCannotOverrideRevokedFinancePermission(): void
    {
        $corpus = SyntheticMaterialSearchCorpus::named('material-search-v1');
        $corpus->revokePrice();
        $result = MaterialSearchResult::fromRecords('read_selected', $corpus, [$corpus->records()[0]], [], $corpus->context())->localEnvelope();
        self::assertSame([], array_values(array_filter($result['facts'], static fn (array $fact): bool => $fact['kind'] === 'price')));
    }
}
