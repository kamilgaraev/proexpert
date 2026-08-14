<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Analysis;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\AiRoleRunRepository;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO\AiRoleRunClaim;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO\AiRoleRunFailure;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO\AiRoleRunInput;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO\AiRoleRunResult;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Synthesis\ProjectSynthesisInput;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Synthesis\ProjectSynthesisModel;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Synthesis\ProjectSynthesisValidator;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Synthesis\RunProjectSynthesis;
use PHPUnit\Framework\TestCase;

final class ProjectSynthesisTest extends TestCase
{
    public function test_input_fingerprint_covers_every_source_role_decision_and_contract_version(): void
    {
        $base = $this->input();

        self::assertNotSame($base->fingerprint(), $this->input(
            sourceVersions: [$this->version('a'), $this->version('c')],
        )->fingerprint());
        self::assertNotSame($base->fingerprint(), $this->input(
            roleFingerprints: ['arbiter' => [$this->hash('changed')], 'geometry_expert' => [$this->hash('geometry')]],
        )->fingerprint());
        self::assertNotSame($base->fingerprint(), $this->input(
            decisions: [['id' => 'decision:roof', 'version' => 2]],
        )->fingerprint());
        self::assertNotSame($base->fingerprint(), $this->input(contractVersion: 'project-synthesis:v2')->fingerprint());
    }

    public function test_validator_combines_roof_evidence_and_keeps_conditional_foundation_as_concrete_question(): void
    {
        $validator = new ProjectSynthesisValidator;
        $result = $validator->validate([
            'links' => [[
                'id' => 'link:roof',
                'fact_ids' => ['fact:roof-material', 'fact:roof-area', 'fact:roof-visual'],
            ]],
            'questions' => [[
                'conflict_id' => 'conflict:foundation',
                'fact_ids' => ['fact:foundation-condition'],
                'reason_code' => 'foundation_condition_missing',
                'source_locator' => ['document_id' => 12, 'page' => 3],
            ]],
        ], $this->input());

        self::assertSame(
            ['fact:roof-area', 'fact:roof-material', 'fact:roof-visual'],
            $result['links'][0]['fact_ids'],
        );
        self::assertSame('foundation_condition_missing', $result['questions'][0]['reason_code']);
        self::assertSame(['document_id' => 12, 'page' => 3], $result['questions'][0]['source_locator']);
    }

    public function test_validator_rejects_repeated_openings_and_replaced_source_version(): void
    {
        $validator = new ProjectSynthesisValidator;

        $this->expectException(\InvalidArgumentException::class);
        $validator->validate([
            'links' => [[
                'id' => 'link:openings',
                'fact_ids' => ['fact:opening-a', 'fact:opening-a'],
            ]],
            'questions' => [],
        ], $this->input());
    }

    public function test_project_engineer_role_is_exactly_replayed_without_second_model_call(): void
    {
        $runs = new SynthesisRoleRunMemoryRepository;
        $model = new RecordedProjectSynthesisModel([
            'accepted_link_ids' => ['link:roof'],
            'question_conflict_ids' => ['conflict:foundation'],
        ]);
        $runner = new RunProjectSynthesis($runs, $model, 'openai/gpt-5-mini');
        $links = [[
            'id' => 'link:roof',
            'status' => 'confirmed',
            'fact_ids' => ['fact:roof-area', 'fact:roof-material'],
        ]];
        $questions = [[
            'conflict_id' => 'conflict:foundation',
            'fact_ids' => ['fact:foundation-condition'],
            'reason_code' => 'foundation_condition_missing',
            'source_locator' => ['document_id' => 12, 'page' => 3],
        ]];

        $first = $runner->run($this->input(), $links, $questions);
        $second = $runner->run($this->input(), $links, $questions);

        self::assertSame($first->toArray(), $second->toArray());
        self::assertSame(1, $model->calls);
        self::assertSame('project_engineer', $runs->inputs[0]->role->value);
        self::assertSame($this->input()->fingerprint(), $runs->inputs[0]->inputFingerprint);
    }

    private function input(
        ?array $sourceVersions = null,
        ?array $roleFingerprints = null,
        ?array $decisions = null,
        string $contractVersion = 'project-synthesis:v1',
    ): ProjectSynthesisInput {
        return new ProjectSynthesisInput(
            organizationId: 1,
            projectId: 2,
            sessionId: 3,
            sourceVersions: $sourceVersions ?? [$this->version('a'), $this->version('b')],
            facts: [
                ['id' => 'fact:roof-material', 'source_version' => $this->version('a'), 'current' => true],
                ['id' => 'fact:roof-area', 'source_version' => $this->version('b'), 'current' => true],
                ['id' => 'fact:roof-visual', 'source_version' => $this->version('b'), 'current' => true],
                ['id' => 'fact:foundation-condition', 'source_version' => $this->version('a'), 'current' => true],
                ['id' => 'fact:opening-a', 'source_version' => $this->version('b'), 'current' => true],
            ],
            derivedQuantities: [],
            decisions: $decisions ?? [['id' => 'decision:roof', 'version' => 1]],
            roleFingerprints: $roleFingerprints ?? [
                'arbiter' => [$this->hash('arbiter')],
                'geometry_expert' => [$this->hash('geometry')],
            ],
            contractVersion: $contractVersion,
        );
    }

    private function version(string $seed): string
    {
        return 'sha256:'.$this->hash($seed);
    }

    private function hash(string $seed): string
    {
        return hash('sha256', $seed);
    }
}

final class RecordedProjectSynthesisModel implements ProjectSynthesisModel
{
    public int $calls = 0;

    public function __construct(private readonly array $result) {}

    public function synthesize(
        ProjectSynthesisInput $input,
        array $candidateLinks,
        array $candidateQuestions,
        callable $onPhysicalAttemptReserved,
    ): array {
        $this->calls++;
        $onPhysicalAttemptReserved('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');

        return $this->result;
    }
}

final class SynthesisRoleRunMemoryRepository implements AiRoleRunRepository
{
    public array $inputs = [];

    private ?AiRoleRunResult $result = null;

    public function claim(AiRoleRunInput $input, string $ownerUuid): AiRoleRunClaim
    {
        $this->inputs[] = $input;

        return $this->result === null
            ? new AiRoleRunClaim(1, 'owned', $ownerUuid)
            : new AiRoleRunClaim(1, 'replay', result: $this->result);
    }

    public function startPhysicalAttempt(int $runId, string $ownerUuid, string $physicalAttemptId): void {}

    public function complete(int $runId, string $ownerUuid, AiRoleRunResult $result): void
    {
        $this->result = $result;
    }

    public function fail(int $runId, string $ownerUuid, AiRoleRunFailure $failure): void {}

    public function loadCurrent(AiRoleRunInput $input): ?AiRoleRunClaim
    {
        return null;
    }

    public function completedFingerprints(int $organizationId, int $projectId, int $sessionId, array $roles, array $sourceVersions): array
    {
        return [];
    }
}
