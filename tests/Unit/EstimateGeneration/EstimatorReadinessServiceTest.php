<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationPackage;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationPackageItem;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Services\Quality\EstimatorReadinessService;
use Illuminate\Database\Eloquent\Collection;
use PHPUnit\Framework\TestCase;

class EstimatorReadinessServiceTest extends TestCase
{
    public function test_requires_project_documents_before_generation(): void
    {
        $readiness = $this->service()->evaluate($this->session([]));

        self::assertSame('needs_documents', $readiness['status']);
        self::assertFalse($readiness['can_generate']);
        self::assertFalse($readiness['can_apply']);
        self::assertSame('no_documents', $readiness['blockers'][0]['code']);
    }

    public function test_waits_for_pending_documents(): void
    {
        $readiness = $this->service()->evaluate($this->session([
            $this->document('processing'),
            $this->document('ready'),
        ]));

        self::assertSame('documents_processing', $readiness['status']);
        self::assertFalse($readiness['can_generate']);
        self::assertSame(1, $readiness['metrics']['documents_pending']);
    }

    public function test_allows_generation_after_ready_documents(): void
    {
        $readiness = $this->service()->evaluate($this->session([
            $this->document('ready', facts: 4, drawingElements: 5, quantityTakeoffs: 2, scopeInferences: 3),
        ]));

        self::assertSame('ready_for_generation', $readiness['status']);
        self::assertTrue($readiness['can_generate']);
        self::assertFalse($readiness['can_apply']);
        self::assertSame(2, $readiness['metrics']['quantity_takeoffs']);
    }

    public function test_blocks_generation_when_ready_document_requires_understanding_review(): void
    {
        $readiness = $this->service()->evaluate($this->session([
            $this->document(
                'ready',
                facts: 4,
                quantityTakeoffs: 2,
                factsSummary: [
                    'document_understanding' => [
                        'role_for_estimation' => 'needs_review',
                        'extracted_capabilities' => [
                            'requires_manual_review' => true,
                        ],
                    ],
                ]
            ),
        ]));

        self::assertSame('documents_need_review', $readiness['status']);
        self::assertFalse($readiness['can_generate']);
        self::assertSame(1, $readiness['metrics']['documents_action_required']);
        self::assertSame('documents_require_review', $readiness['blockers'][0]['code']);
    }

    public function test_blocks_apply_when_norms_require_review(): void
    {
        $readiness = $this->service()->evaluate($this->session([
            $this->document('ready', facts: 4, quantityTakeoffs: 2),
        ], $this->draft([
            'total_work_items' => 10,
            'priced_work_items' => 8,
            'operation_work_items' => 2,
            'safe_norm_required_work_items' => 1,
            'normative_items' => ['requires_review' => 1],
        ])));

        self::assertSame('draft_needs_review', $readiness['status']);
        self::assertFalse($readiness['can_apply']);
        self::assertSame('norms_require_review', $readiness['blockers'][0]['code']);
    }

    public function test_blocks_apply_when_prices_are_not_calculated_without_normative_review_count(): void
    {
        $readiness = $this->service()->evaluate($this->session([
            $this->document('ready', facts: 4, quantityTakeoffs: 2),
        ], $this->draft([
            'status' => 'ready',
            'level' => 'passed',
            'total_work_items' => 10,
            'priced_work_items' => 9,
            'operation_work_items' => 0,
            'not_calculated_work_items' => 1,
            'safe_norm_required_work_items' => 0,
            'normative_items' => ['requires_review' => 0],
        ])));

        self::assertSame('draft_needs_review', $readiness['status']);
        self::assertFalse($readiness['can_apply']);
        self::assertContains('prices_require_review', array_column($readiness['blockers'], 'code'));
    }

    public function test_blocks_apply_when_quality_requires_review_without_price_or_norm_blocker(): void
    {
        $readiness = $this->service()->evaluate($this->session([
            $this->document('ready', facts: 4, quantityTakeoffs: 2),
        ], $this->draft([
            'status' => 'ready',
            'level' => 'passed',
            'total_work_items' => 2,
            'priced_work_items' => 2,
            'operation_work_items' => 0,
            'not_calculated_work_items' => 0,
            'safe_norm_required_work_items' => 0,
            'duplicate_work_items' => 2,
            'normative_items' => ['requires_review' => 0],
        ])));

        self::assertSame('draft_needs_review', $readiness['status']);
        self::assertFalse($readiness['can_apply']);
        self::assertSame(2, $readiness['metrics']['duplicate_work_items']);
        self::assertContains('quality_requires_review', array_column($readiness['blockers'], 'code'));
    }

    public function test_blocks_apply_when_drawing_quantities_require_confirmation(): void
    {
        $readiness = $this->service()->evaluate($this->session([
            $this->document('ready', facts: 4, quantityTakeoffs: 6),
        ], $this->draft([
            'status' => 'review_required',
            'level' => 'passed',
            'total_work_items' => 4,
            'priced_work_items' => 3,
            'operation_work_items' => 0,
            'not_calculated_work_items' => 0,
            'safe_norm_required_work_items' => 0,
            'quantity_review_work_items' => 1,
            'normative_items' => ['requires_review' => 0],
        ])));

        self::assertSame('draft_needs_review', $readiness['status']);
        self::assertFalse($readiness['can_apply']);
        self::assertSame(1, $readiness['metrics']['quantity_review_work_items']);
        self::assertContains('quantities_require_review', array_column($readiness['blockers'], 'code'));
    }

    public function test_allows_apply_for_priced_traceable_draft(): void
    {
        $readiness = $this->service()->evaluate($this->session([
            $this->document('ready', facts: 4, drawingElements: 5, quantityTakeoffs: 2, scopeInferences: 3),
        ], $this->draft([
            'status' => 'ready',
            'level' => 'passed',
            'total_work_items' => 10,
            'priced_work_items' => 8,
            'operation_work_items' => 2,
            'safe_norm_required_work_items' => 0,
            'normative_items' => ['requires_review' => 0],
        ])));

        self::assertSame('ready_to_apply', $readiness['status']);
        self::assertTrue($readiness['can_apply']);
        self::assertSame([], $readiness['blockers']);
    }

    public function test_blocks_apply_when_review_queue_has_blocking_item(): void
    {
        $session = $this->session([
            $this->document('ready', facts: 4, drawingElements: 5, quantityTakeoffs: 2, scopeInferences: 3),
        ], $this->draft([
            'status' => 'ready',
            'level' => 'passed',
            'total_work_items' => 1,
            'priced_work_items' => 1,
            'operation_work_items' => 0,
            'not_calculated_work_items' => 0,
            'safe_norm_required_work_items' => 0,
            'normative_items' => ['requires_review' => 0],
        ]));
        $package = new EstimateGenerationPackage([
            'key' => 'local-1',
            'title' => 'Local estimate',
            'scope_type' => 'site',
        ]);
        $package->setRelation('items', new Collection([
            new EstimateGenerationPackageItem([
                'key' => 'package-only-blocker',
                'item_type' => 'priced_work',
                'name' => 'Package only blocker',
                'unit' => 'm',
                'quantity' => 1,
                'total_cost' => 0,
                'flags' => ['pricing_not_calculated'],
                'metadata' => [
                    'pricing_status' => 'not_calculated',
                    'pricing_blocker' => 'normative_required',
                    'normative_match' => ['status' => 'not_found'],
                ],
            ]),
        ]));
        $session->setRelation('packages', new Collection([$package]));

        $readiness = $this->service()->evaluate($session);

        self::assertSame('draft_needs_review', $readiness['status']);
        self::assertFalse($readiness['can_apply']);
        self::assertSame(1, $readiness['metrics']['review_items_blocking']);
        self::assertContains('review_items_require_action', array_column($readiness['blockers'], 'code'));
    }

    public function test_blocks_apply_when_calculated_priced_item_has_zero_total_cost(): void
    {
        $draft = $this->draft([
            'status' => 'ready',
            'level' => 'passed',
            'total_work_items' => 1,
            'priced_work_items' => 1,
            'operation_work_items' => 0,
            'not_calculated_work_items' => 0,
            'safe_norm_required_work_items' => 0,
            'normative_items' => ['requires_review' => 0],
        ]);
        $draft['local_estimates'][0]['sections'] = [[
            'key' => 'section-1',
            'work_items' => [[
                'key' => 'work-1',
                'item_type' => 'priced_work',
                'pricing_status' => 'calculated',
                'total_cost' => 0,
            ]],
        ]];

        $readiness = $this->service()->evaluate($this->session([
            $this->document('ready', facts: 4, quantityTakeoffs: 2),
        ], $draft));

        self::assertSame('draft_needs_review', $readiness['status']);
        self::assertFalse($readiness['can_apply']);
        self::assertSame(0, $readiness['metrics']['priced_work_items']);
        self::assertSame(1, $readiness['metrics']['zero_total_calculated_work_items']);
        self::assertContains('prices_require_review', array_column($readiness['blockers'], 'code'));
    }

    /**
     * @param  array<int, EstimateGenerationDocument>  $documents
     * @param  array<string, mixed>  $draft
     */
    private function session(array $documents, array $draft = []): EstimateGenerationSession
    {
        $session = new EstimateGenerationSession;
        $session->forceFill([
            'status' => $draft === [] ? 'draft' : 'ready_to_apply',
            'problem_flags' => [],
            'draft_payload' => $draft,
        ]);
        $session->setRelation('documents', new Collection($documents));

        return $session;
    }

    /**
     * @param  array<string, mixed>  $quality
     * @return array<string, mixed>
     */
    private function draft(array $quality): array
    {
        return [
            'local_estimates' => [
                [
                    'key' => 'local-1',
                    'sections' => [['work_items' => [[
                        'item_type' => 'priced_work',
                        'quantity' => ['source' => 'evidenced', 'evidence_ids' => [1]],
                        'normative_match' => ['status' => 'matched', 'decision' => ['status' => 'accepted']],
                        'price_snapshot' => ['version_id' => 1],
                        'pricing_finalized_at' => '2026-07-12T00:00:00Z',
                    ]]]],
                ],
            ],
            'building_model' => [
                'scale_status' => 'confirmed',
                'evidence_ids' => [1],
                'metrics' => ['complete' => true],
                'cad_status' => 'completed',
            ],
            'problem_flags' => [],
            'quality_summary' => $quality,
        ];
    }

    private function document(
        string $status,
        int $facts = 0,
        int $drawingElements = 0,
        int $quantityTakeoffs = 0,
        int $scopeInferences = 0,
        array $factsSummary = []
    ): EstimateGenerationDocument {
        $document = new EstimateGenerationDocument;
        $document->forceFill([
            'status' => $status,
            'facts_summary' => $factsSummary,
        ]);
        $document->setAttribute('facts_count', $facts);
        $document->setAttribute('drawing_elements_count', $drawingElements);
        $document->setAttribute('quantity_takeoffs_count', $quantityTakeoffs);
        $document->setAttribute('scope_inferences_count', $scopeInferences);

        return $document;
    }

    private function service(): EstimatorReadinessService
    {
        return new EstimatorReadinessService;
    }
}
