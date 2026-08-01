<?php

declare(strict_types=1);

namespace Tests\Unit\Budgeting\Reporting;

use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\Budgeting\Reporting\BudgetPlanFactCandidateContract;
use App\BusinessModules\Features\Budgeting\Reporting\BudgetPlanFactReleaseCandidateLayout;
use App\BusinessModules\Features\Budgeting\Reporting\BudgetPlanFactReleaseCandidateResolver;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tests\Support\Budgeting\BudgetPlanFactCandidateFixture;

final class BudgetPlanFactReleaseCandidateResolverTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'most-budget-plan-fact-candidate-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700, true);
        $this->writeDocuments();
    }

    protected function tearDown(): void
    {
        foreach (scandir($this->directory) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                unlink($this->directory.DIRECTORY_SEPARATOR.$entry);
            }
        }
        rmdir($this->directory);
        parent::tearDown();
    }

    public function test_resolves_only_the_canonical_budget_plan_fact_layout(): void
    {
        $documents = (new BudgetPlanFactReleaseCandidateResolver)->resolve(
            $this->directory,
            $this->commitSha(),
        );

        self::assertSame(BudgetPlanFactCandidateContract::CODE, $documents[BudgetPlanFactReleaseCandidateLayout::CANDIDATE_MANIFEST]['code']);
        self::assertSame(BudgetPlanFactCandidateContract::FORMULA_HASH, $documents[BudgetPlanFactReleaseCandidateLayout::CONFORMANCE_EVIDENCE]['formula_sha256']);
    }

    /** @dataProvider driftedDocumentProvider */
    public function test_rejects_candidate_identity_drift(string $document, string $key, mixed $value): void
    {
        $payload = $this->document($document);
        $payload[$key] = $value;
        $this->write($document, $payload);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('budget_plan_fact_release_candidate_untrusted');

        (new BudgetPlanFactReleaseCandidateResolver)->resolve(
            $this->directory,
            $this->commitSha(),
        );
    }

    public static function driftedDocumentProvider(): array
    {
        return [
            'formula hash' => [BudgetPlanFactReleaseCandidateLayout::CONFORMANCE_EVIDENCE, 'formula_sha256', str_repeat('0', 64)],
            'source hash' => [BudgetPlanFactReleaseCandidateLayout::CONFORMANCE_EVIDENCE, 'source_sha256', str_repeat('0', 64)],
            'close identity' => [BudgetPlanFactReleaseCandidateLayout::CANDIDATE_MANIFEST, 'source_close_identity_sha256', str_repeat('0', 64)],
            'candidate hash' => [BudgetPlanFactReleaseCandidateLayout::PROOF_TEMPLATE, 'candidate_manifest_sha256', str_repeat('0', 64)],
        ];
    }

    public function test_rejects_a_self_consistent_but_malformed_close_identity(): void
    {
        $candidate = $this->document(BudgetPlanFactReleaseCandidateLayout::CANDIDATE_MANIFEST);
        $candidate['source_close_identity']['organization_id'] = 0;
        $candidate['source_close_identity_sha256'] = hash('sha256', CanonicalJson::encode($candidate['source_close_identity']));
        $this->write(BudgetPlanFactReleaseCandidateLayout::CANDIDATE_MANIFEST, $candidate);

        $conformance = $this->document(BudgetPlanFactReleaseCandidateLayout::CONFORMANCE_EVIDENCE);
        $conformance['source_close_identity_sha256'] = $candidate['source_close_identity_sha256'];
        $this->write(BudgetPlanFactReleaseCandidateLayout::CONFORMANCE_EVIDENCE, $conformance);

        $proof = $this->document(BudgetPlanFactReleaseCandidateLayout::PROOF_TEMPLATE);
        $proof['candidate_manifest_sha256'] = hash('sha256', CanonicalJson::encode($candidate));
        $proof['conformance_evidence_sha256'] = hash('sha256', CanonicalJson::encode($conformance));
        $proof['source_close_identity_sha256'] = $candidate['source_close_identity_sha256'];
        $this->write(BudgetPlanFactReleaseCandidateLayout::PROOF_TEMPLATE, $proof);
        $this->write(
            BudgetPlanFactReleaseCandidateLayout::REQUEST_FILE,
            BudgetPlanFactReleaseCandidateLayout::request($this->commitSha(), hash('sha256', CanonicalJson::encode($proof))),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('budget_plan_fact_release_candidate_untrusted');

        (new BudgetPlanFactReleaseCandidateResolver)->resolve($this->directory, $this->commitSha());
    }

    private function writeDocuments(): void
    {
        $commit = $this->commitSha();
        $definition = $this->definition();
        $close = [
            'budget_version_uuid' => 'budget-1',
            'organization_id' => 1,
            'period_end' => '2026-01-31',
            'period_start' => '2026-01-01',
            'scenario_uuid' => 'scenario-1',
        ];
        $candidate = [
            'candidate_definition' => $definition,
            'candidate_definition_sha256' => hash('sha256', CanonicalJson::encode($definition)),
            'code' => BudgetPlanFactCandidateContract::CODE,
            'formula_sha256' => BudgetPlanFactCandidateContract::FORMULA_HASH,
            'formula_version' => BudgetPlanFactCandidateContract::FORMULA_VERSION,
            'generated_from_commit' => $commit,
            'publication_status' => 'candidate',
            'source_close_identity' => $close,
            'source_close_identity_sha256' => hash('sha256', CanonicalJson::encode($close)),
            'source_schema_version' => BudgetPlanFactCandidateFixture::contract()->sourceSchemaVersion,
            'source_sha256' => BudgetPlanFactCandidateContract::SOURCE_HASH,
        ];
        $conformance = [
            'code' => BudgetPlanFactCandidateContract::CODE,
            'commit_sha' => $commit,
            'definition_sha256' => $candidate['candidate_definition_sha256'],
            'formula_sha256' => BudgetPlanFactCandidateContract::FORMULA_HASH,
            'source_close_identity_sha256' => $candidate['source_close_identity_sha256'],
            'source_sha256' => BudgetPlanFactCandidateContract::SOURCE_HASH,
            'status' => 'passed',
        ];
        $proof = [
            'candidate_definition_sha256' => $candidate['candidate_definition_sha256'],
            'candidate_manifest_sha256' => hash('sha256', CanonicalJson::encode($candidate)),
            'code' => BudgetPlanFactCandidateContract::CODE,
            'conformance_evidence_sha256' => hash('sha256', CanonicalJson::encode($conformance)),
            'formula_sha256' => BudgetPlanFactCandidateContract::FORMULA_HASH,
            'release_commit_sha' => $commit,
            'source_close_identity_sha256' => $candidate['source_close_identity_sha256'],
            'source_sha256' => BudgetPlanFactCandidateContract::SOURCE_HASH,
        ];
        $request = BudgetPlanFactReleaseCandidateLayout::request($commit, hash('sha256', CanonicalJson::encode($proof)));

        $this->write(BudgetPlanFactReleaseCandidateLayout::CANDIDATE_MANIFEST, $candidate);
        $this->write(BudgetPlanFactReleaseCandidateLayout::CONFORMANCE_EVIDENCE, $conformance);
        $this->write(BudgetPlanFactReleaseCandidateLayout::PROOF_TEMPLATE, $proof);
        $this->write(BudgetPlanFactReleaseCandidateLayout::REQUEST_FILE, $request);
    }

    private function definition(): array
    {
        $contract = BudgetPlanFactCandidateFixture::contract();

        return [
            'capabilities' => ['supports_subscriptions' => false],
            'code' => BudgetPlanFactCandidateContract::CODE,
            'columns' => $this->canonicalItems($contract->columns()),
            'filters' => $this->canonicalItems($contract->filters()),
            'formats' => $contract->formats(),
            'permissions' => [
                'audit' => [],
                'export' => ['budgeting.plan_fact.export'],
                'sensitive' => [],
                'view' => ['budgeting.plan_fact.view'],
            ],
            'readiness' => ['delivery' => 'verified', 'formula' => 'verified', 'publication' => 'candidate', 'source' => 'verified'],
            'sorts' => $this->canonicalItems($contract->sorts()),
            'versions' => [
                'contract' => '1.0.0',
                'formula' => BudgetPlanFactCandidateContract::FORMULA_VERSION,
                'renderer' => '1.0.0',
                'source_schema' => $contract->sourceSchemaVersion,
            ],
        ];
    }

    private function commitSha(): string
    {
        return str_repeat('a', 40);
    }

    private function document(string $name): array
    {
        return json_decode((string) file_get_contents($this->directory.DIRECTORY_SEPARATOR.$name), true, 64, JSON_THROW_ON_ERROR);
    }

    private function write(string $name, array $document): void
    {
        file_put_contents($this->directory.DIRECTORY_SEPARATOR.$name, CanonicalJson::encode($document));
    }

    private function canonicalItems(array $items): array
    {
        return array_map(
            static fn (array $item): array => json_decode(CanonicalJson::encode($item), true, 64, JSON_THROW_ON_ERROR),
            $items,
        );
    }
}
