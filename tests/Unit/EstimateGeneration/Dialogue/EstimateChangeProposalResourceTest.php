<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Dialogue;

use App\BusinessModules\Addons\EstimateGeneration\Http\Controllers\EstimateGenerationDialogueController;
use App\BusinessModules\Addons\EstimateGeneration\Http\Resources\EstimateChangeProposalResource;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

final class EstimateChangeProposalResourceTest extends TestCase
{
    public function test_it_exposes_only_bounded_business_data(): void
    {
        $resource = new EstimateChangeProposalResource([
            'id' => 'proposal-1',
            'intent' => 'correct_fact',
            'interpretation_version' => 'v1',
            'dependency_keys' => ['quantity:room'],
            'command_excerpt' => 'Исправить площадь',
            'before_payload' => ['area' => '100.0000', 'decision_key' => 'decision:roof', 'selected_option' => 'roof:metal', 'prompt' => 'secret'],
            'after_payload' => ['area' => '110.0000', 'decision_key' => 'decision:roof', 'response' => 'roof:soft', 'exception' => 'internal'],
            'affected_payload' => ['count' => 1],
            'assumptions' => [],
            'questions' => [],
            'evidence' => [
                ['artifact_id' => 7, 'source_version' => 'source-v2', 'page' => 2, 'native_reference' => 'АР-2', 'prompt' => 'secret'],
                ['artifact_id' => 'foreign'],
            ],
            'cost_delta_known' => true,
            'cost_delta' => '1250.5000',
            'cost_state' => 'known',
            'cost_blockers' => [],
            'status' => 'proposed',
            'status_version' => 1,
            'version_fence' => ['draft_version' => 'secret'],
            'result' => ['internal' => true],
            'failure_code' => 'provider_exception',
            'simulation_input' => ['internal' => true],
            'simulation_fingerprint' => 'sha256:internal',
        ]);

        $payload = $resource->toArray(Request::create('/'));

        self::assertSame(['area' => '100.0000', 'decision_key' => 'decision:roof', 'selected_option' => 'roof:metal'], $payload['before_payload']);
        self::assertSame(['area' => '110.0000', 'decision_key' => 'decision:roof', 'response' => 'roof:soft'], $payload['after_payload']);
        self::assertSame([['artifact_id' => 7, 'source_version' => 'source-v2', 'page' => 2, 'native_reference' => 'АР-2']], $payload['evidence']);
        self::assertArrayNotHasKey('interpretation_version', $payload);
        self::assertSame(['quantity:room'], $payload['dependency_keys']);
        self::assertArrayNotHasKey('version_fence', $payload);
        self::assertArrayNotHasKey('result', $payload);
        self::assertArrayNotHasKey('failure_code', $payload);
        self::assertArrayNotHasKey('simulation_input', $payload);
        self::assertArrayNotHasKey('simulation_fingerprint', $payload);
    }

    public function test_status_shapes_have_required_and_forbidden_timestamps(): void
    {
        $base = [
            'id' => 'proposal-1', 'intent' => 'correct_fact', 'interpretation_version' => 'v1',
            'command_excerpt' => 'Исправить площадь', 'before_payload' => [], 'after_payload' => [],
            'affected_payload' => ['count' => 0, 'preview_count' => 0], 'dependency_keys' => [],
            'assumptions' => [], 'questions' => [], 'evidence' => [], 'cost_state' => 'unknown',
            'cost_blockers' => [], 'cost_delta_known' => false, 'cost_delta' => null, 'status_version' => 1,
            'created_at' => '2026-08-12T08:00:00Z', 'expires_at' => '2026-08-12T08:30:00Z',
            'applied_at' => null, 'cancelled_at' => null, 'updated_at' => '2026-08-12T08:00:00Z',
        ];

        foreach ([
            ['proposed', null, null],
            ['applying', null, null],
            ['applied', '2026-08-12T08:05:00Z', null],
            ['cancelled', null, '2026-08-12T08:05:00Z'],
            ['expired', null, null],
            ['stale', null, null],
            ['failed', null, null],
        ] as [$status, $appliedAt, $cancelledAt]) {
            $payload = (new EstimateChangeProposalResource([
                ...$base,
                'status' => $status,
                'applied_at' => $appliedAt,
                'cancelled_at' => $cancelledAt,
                'result' => $status === 'applied' ? ['reanalysis_requested' => true, 'decision_id' => 'internal'] : null,
            ]))->toArray(Request::create('/'));
            self::assertSame($status, $payload['status']);
            self::assertSame($appliedAt, $payload['applied_at']);
            self::assertSame($cancelledAt, $payload['cancelled_at']);
            self::assertSame(
                $status === 'applied' ? ['outcome' => 'applied', 'reanalysis_requested' => true] : null,
                $payload['result_summary'],
            );
            self::assertArrayNotHasKey('result', $payload);
        }

        $this->expectExceptionMessage('estimate_generation.proposal_resource_status_invalid');
        (new EstimateChangeProposalResource([...$base, 'status' => 'applied']))->toArray(Request::create('/'));
    }

    public function test_every_public_stage_seven_error_has_a_human_translation_and_bounded_code(): void
    {
        $translations = require dirname(__DIR__, 4).'/lang/ru/estimate_generation.php';
        $codes = [
            'command_intent_invalid', 'command_provider_invalid', 'command_context_review_required',
            'command_reference_invalid', 'interpretation_attempt_active', 'interpretation_attempt_lost',
            'interpretation_attempt_ambiguous', 'interpretation_attempt_expired', 'interpretation_response_invalid',
            'interpretation_response_collision', 'interpretation_completion_collision', 'proposal_not_found',
            'proposal_idempotency_collision', 'proposal_too_large', 'proposal_payload_invalid',
            'proposal_intent_unsupported', 'proposal_terminal', 'proposal_expired', 'proposal_stale',
            'proposal_concurrent', 'proposal_preview_unknown', 'proposal_preview_partial', 'locator_invalid',
            'proposal_undo_unavailable',
        ];
        foreach ($codes as $code) {
            self::assertArrayHasKey($code, $translations);
            self::assertIsString($translations[$code]);
            self::assertStringNotContainsString('estimate_generation.', $translations[$code]);
        }

        $controller = (new \ReflectionClass(EstimateGenerationDialogueController::class))
            ->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod($controller, 'publicError');
        $result = $method->invoke($controller, new \RuntimeException(
            'estimate_generation.command_context_review_required:facts',
        ));
        self::assertSame('command_context_review_required', $result[0]);
        self::assertSame('estimate_generation.command_context_review_required', $result[1]);
    }
}
