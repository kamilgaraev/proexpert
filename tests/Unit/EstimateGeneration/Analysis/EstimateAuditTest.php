<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Analysis;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\ApplyComposerCorrectionCycle;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\EstimateAuditInput;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\EstimateAuditInputFactory;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\EstimateAuditModel;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\RunEstimateAudit;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\TimewebEstimateAuditModel;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiOperationContext;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiPriceSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiPriceSnapshotResolver;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiUsageData;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiUsageStore;
use App\BusinessModules\Addons\EstimateGeneration\Observability\RerankWireClient;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\EstimateGeneration\InMemoryAiRoleRunRepository;
use Tests\Support\EstimateGeneration\InMemoryProjectModelRepository;

final class EstimateAuditTest extends TestCase
{
    public function test_accepted_audit_replays_without_second_model_call(): void
    {
        $runs = new InMemoryAiRoleRunRepository;
        $model = new RecordedEstimateAuditModel([['accepted' => true, 'findings' => []]]);
        $auditor = new RunEstimateAudit($runs, $model, 'openai/gpt-5-mini');
        $input = $this->input($this->draft([$this->item('work:foundation')]));

        $first = $auditor->run($input);
        $second = $auditor->run($input);

        self::assertSame($first, $second);
        self::assertTrue($first['accepted']);
        self::assertSame(1, $model->calls);
        self::assertSame('estimate_auditor', $runs->inputs[0]->role->value);
        self::assertSame('audit-cycle:0:'.str_repeat('a', 64), $runs->inputs[0]->subjectVersion);
    }

    #[DataProvider('invalidFindingProvider')]
    public function test_auditor_rejects_untyped_or_untraceable_findings(callable $mutate, string $message): void
    {
        $input = $this->input($this->draft([$this->item('work:foundation')]));
        $result = ['accepted' => false, 'findings' => [$this->finding('finding:1', 'coverage_gap')]];
        $result = $mutate($result);
        $auditor = new RunEstimateAudit(
            new InMemoryAiRoleRunRepository,
            new RecordedEstimateAuditModel([$result]),
            'openai/gpt-5-mini',
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        $auditor->run($input);
    }

    public static function invalidFindingProvider(): iterable
    {
        yield 'accepted with findings' => [
            static fn (array $result): array => ['accepted' => true, 'findings' => $result['findings']],
            'estimate_audit_acceptance_invalid',
        ];
        yield 'unknown type' => [
            static function (array $result): array {
                $result['findings'][0]['type'] = 'generic_problem';

                return $result;
            },
            'estimate_audit_finding_type_invalid',
        ];
        yield 'invented fact' => [
            static function (array $result): array {
                $result['findings'][0]['source_fact_ids'] = ['fact:invented'];

                return $result;
            },
            'estimate_audit_source_fact_invalid',
        ];
        yield 'provider confidence' => [
            static function (array $result): array {
                $result['findings'][0]['confidence'] = 87;

                return $result;
            },
            'estimate_audit_finding_shape_invalid',
        ];
        yield 'invented locator' => [
            static function (array $result): array {
                $result['findings'][0]['source_locator']['page'] = 99;

                return $result;
            },
            'estimate_audit_source_locator_invalid',
        ];
        yield 'raw english user text' => [
            static function (array $result): array {
                $result['findings'][0]['reason'] = 'The estimate contains an unsupported assumption.';

                return $result;
            },
            'estimate_audit_finding_invalid',
        ];
    }

    public function test_exact_duplicate_is_removed_and_rebuilt_before_second_audit(): void
    {
        $retained = $this->item('work:foundation');
        $duplicate = $this->item('work:foundation-copy');
        $draft = $this->draft([$retained, $duplicate]);
        $finding = $this->finding('finding:duplicate', 'duplicate', [
            'operation' => 'remove_exact_duplicate',
            'target_item_key' => 'work:foundation-copy',
            'retained_item_key' => 'work:foundation',
            'expected_target_fingerprint' => ApplyComposerCorrectionCycle::itemFingerprint($duplicate),
            'expected_retained_fingerprint' => ApplyComposerCorrectionCycle::itemFingerprint($retained),
        ]);
        $model = new RecordedEstimateAuditModel([
            ['accepted' => false, 'findings' => [$finding]],
            ['accepted' => true, 'findings' => []],
        ]);
        $cycles = new ApplyComposerCorrectionCycle(
            new RunEstimateAudit(new InMemoryAiRoleRunRepository, $model, 'openai/gpt-5-mini'),
        );

        $result = $cycles->apply($this->input($draft));

        self::assertSame(['work:foundation'], array_column(
            $result['draft']['local_estimates'][0]['sections'][0]['work_items'],
            'key',
        ));
        self::assertSame('accepted', $result['audit']['status']);
        self::assertSame(1, $result['audit']['correction_cycles']);
        self::assertSame(2, $model->calls);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['draft']['artifact_hash']);
    }

    public function test_two_corrections_are_the_hard_limit_and_material_remainder_becomes_review_item(): void
    {
        $a = $this->item('work:a');
        $b = $this->item('work:b');
        $c = $this->item('work:c');
        $first = $this->finding('finding:first', 'duplicate', $this->duplicateCorrection($b, $a));
        $second = $this->finding('finding:second', 'duplicate', $this->duplicateCorrection($c, $a));
        $remaining = $this->finding('finding:remaining', 'quantity_mismatch');
        $model = new RecordedEstimateAuditModel([
            ['accepted' => false, 'findings' => [$first]],
            ['accepted' => false, 'findings' => [$second]],
            ['accepted' => false, 'findings' => [$remaining]],
        ]);
        $cycles = new ApplyComposerCorrectionCycle(
            new RunEstimateAudit(new InMemoryAiRoleRunRepository, $model, 'openai/gpt-5-mini'),
        );

        $result = $cycles->apply($this->input($this->draft([$a, $b, $c])));

        self::assertSame(3, $model->calls);
        self::assertSame(2, $result['audit']['correction_cycles']);
        self::assertSame('review_required', $result['audit']['status']);
        self::assertSame('finding:remaining', $result['draft']['audit_review_items'][0]['finding_id']);
        self::assertCount(1, $result['draft']['local_estimates'][0]['sections'][0]['work_items']);
    }

    public function test_non_deterministic_correction_is_not_applied_and_is_routed_to_operator(): void
    {
        $finding = $this->finding('finding:omission', 'omission');
        $model = new RecordedEstimateAuditModel([['accepted' => false, 'findings' => [$finding]]]);
        $cycles = new ApplyComposerCorrectionCycle(
            new RunEstimateAudit(new InMemoryAiRoleRunRepository, $model, 'openai/gpt-5-mini'),
        );

        $result = $cycles->apply($this->input($this->draft([$this->item('work:a')])));

        self::assertSame(1, $model->calls);
        self::assertSame(0, $result['audit']['correction_cycles']);
        self::assertSame('review_required', $result['audit']['status']);
        self::assertSame('finding:omission', $result['draft']['audit_review_items'][0]['finding_id']);
    }

    public function test_factory_captures_exact_project_snapshot_and_source_navigation(): void
    {
        $models = new InMemoryProjectModelRepository;
        $source = 'sha256:'.str_repeat('c', 64);
        $entity = new Entity('entity:foundation', 10, 20, 30, $source, 'quantity', 'foundation');
        $fact = new Fact('fact:foundation', 10, 20, 30, $source, $entity->id, 'foundation_volume', '10.2500', 'm3', 1.0, 'user_assumption', 'confirmed', []);
        $models->saveSourceModel([$entity], [$fact], []);
        $draft = $this->draft([$this->item('work:foundation')]);
        $draft['local_estimates'][0]['sections'][0]['work_items'][0]['source_refs'][0] = [
            'fact_id' => 'fact:foundation', 'document_id' => 7, 'page' => 2,
        ];

        $input = (new EstimateAuditInputFactory($models, 10000))->capture(10, 20, 30, $draft);

        self::assertSame($models->snapshotForPlanning(10, 20, 30, 10001)['token'], $input->snapshotToken);
        self::assertSame('10.2500', $input->facts[0]['value']);
        self::assertSame(['document_id' => 7, 'page' => 2], $input->evidence[0]['locator']);
    }

    public function test_timeweb_auditor_uses_only_pinned_model_and_independent_bounded_contract(): void
    {
        $wire = new class implements RerankWireClient
        {
            public string $model = '';
            public array $messages = [];
            public array $options = [];

            public function provider(): string
            {
                return 'timeweb';
            }

            public function call(string $model, array $messages, array $options): array
            {
                $this->model = $model;
                $this->messages = $messages;
                $this->options = $options;

                return [
                    'content' => '{"accepted":true,"findings":[]}',
                    'model' => $model,
                    'usage_available' => true,
                    'input_tokens' => 50,
                    'output_tokens' => 10,
                ];
            }
        };
        $usage = new class implements AiUsageStore
        {
            public array $records = [];

            public function record(AiUsageData $data): void
            {
                $this->records[] = $data;
            }
        };
        $prices = new class implements AiPriceSnapshotResolver
        {
            public ?AiOperationContext $context = null;

            public function resolve(AiOperationContext $context, string $provider, string $model): AiPriceSnapshot
            {
                $this->context = $context;

                return AiPriceSnapshot::fromArray([]);
            }
        };
        $model = new TimewebEstimateAuditModel($wire, $usage, $prices, 'openai/gpt-5-mini', 100000, 4000, 60);

        $result = $model->audit($this->input($this->draft([$this->item('work:a')])), static function (string $attemptId): void {
            self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $attemptId);
        });

        self::assertSame(['accepted' => true, 'findings' => []], $result);
        self::assertSame('openai/gpt-5-mini', $wire->model);
        self::assertSame('json', $wire->options['profile']);
        self::assertArrayNotHasKey('fallback_models', $wire->options);
        self::assertStringContainsString('независимый аудитор', $wire->messages[0]['content']);
        self::assertStringContainsString('10.2500', $wire->messages[1]['content']);
        self::assertSame('estimate_audit', $prices->context?->operation);
        self::assertCount(1, $usage->records);
    }

    private function input(array $draft, int $cycle = 0): EstimateAuditInput
    {
        return new EstimateAuditInput(
            organizationId: 10,
            projectId: 20,
            sessionId: 30,
            snapshotToken: str_repeat('a', 64),
            cycle: $cycle,
            facts: [['id' => 'fact:foundation', 'status' => 'confirmed']],
            draft: $draft,
            evidence: [['fact_id' => 'fact:foundation', 'locator' => ['document_id' => 7, 'page' => 2]]],
            contractVersion: RunEstimateAudit::PROMPT_CONTRACT,
        );
    }

    private function draft(array $items): array
    {
        return [
            'local_estimates' => [[
                'key' => 'estimate:house',
                'sections' => [['key' => 'section:works', 'work_items' => $items]],
            ]],
            'stage6_review_items' => [],
            'catalog_identity' => ['status' => 'current'],
            'technology_identity' => ['status' => 'current'],
            'rule_identity' => ['status' => 'current'],
        ];
    }

    private function item(string $key): array
    {
        return [
            'key' => $key,
            'name' => 'Устройство фундамента',
            'unit' => 'м3',
            'quantity' => '10.2500',
            'normative_match' => ['norm_id' => 101, 'code' => 'ГЭСН-01'],
            'price_snapshot' => ['final_amount' => '1234.56'],
            'source_refs' => [['fact_id' => 'fact:foundation']],
        ];
    }

    private function finding(string $id, string $type, ?array $correction = null): array
    {
        return [
            'finding_id' => $id,
            'type' => $type,
            'severity' => 'material',
            'item_key' => null,
            'source_fact_ids' => ['fact:foundation'],
            'source_locator' => ['document_id' => 7, 'page' => 2],
            'reason' => 'Проверка выявила расхождение с исходными данными.',
            'impact' => 'Итог и состав работ могут быть искажены.',
            'recommendation' => 'Проверить позицию по указанному источнику.',
            'correction' => $correction ?? ['operation' => 'operator_review'],
        ];
    }

    private function duplicateCorrection(array $target, array $retained): array
    {
        return [
            'operation' => 'remove_exact_duplicate',
            'target_item_key' => $target['key'],
            'retained_item_key' => $retained['key'],
            'expected_target_fingerprint' => ApplyComposerCorrectionCycle::itemFingerprint($target),
            'expected_retained_fingerprint' => ApplyComposerCorrectionCycle::itemFingerprint($retained),
        ];
    }
}

final class RecordedEstimateAuditModel implements EstimateAuditModel
{
    public int $calls = 0;

    public function __construct(private readonly array $results) {}

    public function audit(EstimateAuditInput $input, callable $onAttemptStarted): array
    {
        $onAttemptStarted('00000000-0000-4000-8000-00000000000'.($this->calls + 1));

        return $this->results[$this->calls++];
    }
}
