<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationStatus;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\DocumentGenerationReadinessService;
use App\BusinessModules\Addons\EstimateGeneration\Settings\EffectiveEstimateGenerationSettings;
use App\BusinessModules\Addons\EstimateGeneration\Settings\EffectiveSettingsOperationStore;
use App\BusinessModules\Addons\EstimateGeneration\Settings\EffectiveSettingsPair;
use App\BusinessModules\Addons\EstimateGeneration\Settings\EffectiveSettingsResolver;
use App\BusinessModules\Addons\EstimateGeneration\Settings\SettingsSnapshotHash;
use DomainException;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

final class DocumentGenerationReadinessServiceTest extends TestCase
{
    public function test_empty_session_does_not_pin_ai_settings_before_documents_are_uploaded(): void
    {
        $store = new class implements EffectiveSettingsOperationStore
        {
            public function pin(string $correlationId, int $organizationId, int $sessionId): EffectiveSettingsPair
            {
                throw new DomainException('AI settings must not be pinned for an empty session.');
            }
        };
        $session = new EstimateGenerationSession;
        $session->forceFill([
            'id' => 41,
            'organization_id' => 7,
            'state_version' => 0,
        ]);
        $session->exists = true;
        $session->setRelation('documents', collect());

        $result = (new DocumentGenerationReadinessService(new EffectiveSettingsResolver($store)))
            ->evaluate($session);

        self::assertFalse($result['summary']['has_documents']);
        self::assertSame(0, $result['summary']['total']);
        self::assertFalse($result['can_analyze']);
        self::assertFalse($result['can_generate']);
        self::assertFalse($result['summary']['can_analyze']);
        self::assertFalse($result['summary']['can_generate']);
        self::assertSame('{}', json_encode($result['summary']['statuses'], JSON_THROW_ON_ERROR));
    }

    public function test_threshold_and_toggle_change_final_document_readiness_decision(): void
    {
        $document = $this->qualitySignalDocument([
            'classification' => ['confidence' => 0.69],
            'geometry' => ['confidence' => 0.81],
        ]);
        $service = new DocumentGenerationReadinessService;

        $strict = $service->summary(new Collection([$document]), $this->settings(true, '0.7000'));
        $relaxed = $service->summary(new Collection([$document]), $this->settings(true, '0.6800'));
        $disabled = $service->summary(new Collection([$document]), $this->settings(false, '0.7000'));

        self::assertFalse($strict['can_generate']);
        self::assertSame(['classification_low_confidence'], $strict['items'][0]['quality_review_reasons']);
        self::assertTrue($relaxed['can_generate']);
        self::assertTrue($disabled['can_generate']);
    }

    public function test_acknowledged_input_allows_generation_with_reviewable_ready_document(): void
    {
        $settings = $this->settings(true, '0.7000');
        $store = new class($settings) implements EffectiveSettingsOperationStore
        {
            public function __construct(private readonly EffectiveEstimateGenerationSettings $settings) {}

            public function pin(string $correlationId, int $organizationId, int $sessionId): EffectiveSettingsPair
            {
                return new EffectiveSettingsPair($this->settings, $this->settings);
            }
        };
        foreach ([EstimateGenerationStatus::ReadyToGenerate, EstimateGenerationStatus::Applied] as $status) {
            $session = new EstimateGenerationSession;
            $session->forceFill([
                'id' => 55,
                'organization_id' => 7,
                'state_version' => 3,
                'status' => $status,
            ]);
            $session->exists = true;
            $session->setRelation('documents', collect([
                $this->qualitySignalDocument([
                    'classification' => ['confidence' => 0.69],
                    'geometry' => ['confidence' => 0.81],
                ]),
            ]));

            $result = (new DocumentGenerationReadinessService(new EffectiveSettingsResolver($store)))
                ->evaluate($session);

            self::assertSame(1, $result['summary']['quality_review_count']);
            self::assertTrue($result['summary']['review_acknowledged']);
            self::assertTrue($result['summary']['can_generate']);
            self::assertTrue($result['can_generate']);
        }
    }

    public function test_cancelled_session_keeps_document_review_acknowledgement_after_generation_started(): void
    {
        $settings = $this->settings(true, '0.7000');
        $store = new class($settings) implements EffectiveSettingsOperationStore
        {
            public function __construct(private readonly EffectiveEstimateGenerationSettings $settings) {}

            public function pin(string $correlationId, int $organizationId, int $sessionId): EffectiveSettingsPair
            {
                return new EffectiveSettingsPair($this->settings, $this->settings);
            }
        };
        $session = new EstimateGenerationSession;
        $session->forceFill([
            'id' => 56,
            'organization_id' => 7,
            'state_version' => 4,
            'status' => EstimateGenerationStatus::Cancelled,
            'input_payload' => ['generation_attempt_id' => 'attempt-56'],
        ]);
        $session->exists = true;
        $session->setRelation('documents', collect([
            $this->qualitySignalDocument([
                'classification' => ['confidence' => 0.69],
                'geometry' => ['confidence' => 0.81],
            ]),
        ]));

        $result = (new DocumentGenerationReadinessService(new EffectiveSettingsResolver($store)))
            ->evaluate($session);

        self::assertTrue($result['summary']['review_acknowledged']);
        self::assertTrue($result['can_generate']);
    }

    public function test_cancelled_session_without_generation_attempt_still_requires_document_review(): void
    {
        $settings = $this->settings(true, '0.7000');
        $store = new class($settings) implements EffectiveSettingsOperationStore
        {
            public function __construct(private readonly EffectiveEstimateGenerationSettings $settings) {}

            public function pin(string $correlationId, int $organizationId, int $sessionId): EffectiveSettingsPair
            {
                return new EffectiveSettingsPair($this->settings, $this->settings);
            }
        };
        $session = new EstimateGenerationSession;
        $session->forceFill([
            'id' => 57,
            'organization_id' => 7,
            'state_version' => 1,
            'status' => EstimateGenerationStatus::Cancelled,
            'input_payload' => [],
        ]);
        $session->exists = true;
        $session->setRelation('documents', collect([
            $this->qualitySignalDocument([
                'classification' => ['confidence' => 0.69],
                'geometry' => ['confidence' => 0.81],
            ]),
        ]));

        $result = (new DocumentGenerationReadinessService(new EffectiveSettingsResolver($store)))
            ->evaluate($session);

        self::assertFalse($result['summary']['review_acknowledged']);
        self::assertFalse($result['can_generate']);
    }

    public function test_geometry_hard_blocker_cannot_be_disabled(): void
    {
        $document = $this->qualitySignalDocument([
            'geometry' => ['confidence' => 0.99, 'hard_blockers' => ['scale_conflict']],
        ]);

        $summary = (new DocumentGenerationReadinessService)->summary(
            new Collection([$document]),
            $this->settings(false, '0.1000'),
        );

        self::assertFalse($summary['can_generate']);
        self::assertSame(['geometry_scale_conflict'], $summary['items'][0]['quality_review_reasons']);
    }

    public function test_ready_document_without_understanding_role_blocks_generation(): void
    {
        $document = new EstimateGenerationDocument;
        $document->forceFill([
            'id' => 6,
            'filename' => 'unknown-upload.pdf',
            'status' => 'ready',
            'processing_stage' => 'completed',
            'progress_percent' => 100,
            'quality_level' => 'good',
            'quality_score' => 0.91,
            'quality_flags' => [],
            'facts_summary' => [
                'total_area_m2' => 128.0,
            ],
        ]);

        $summary = (new DocumentGenerationReadinessService)->summary(new Collection([$document]));

        self::assertSame(1, $summary['missing_understanding_count']);
        self::assertSame(1, $summary['action_required_count']);
        self::assertFalse($summary['can_generate']);
        self::assertContains('document_understanding_missing', $summary['problem_flags']);
        self::assertTrue($summary['items'][0]['missing_document_understanding']);
        self::assertTrue($summary['items'][0]['is_action_required']);
    }

    public function test_ready_document_that_requires_understanding_review_blocks_generation(): void
    {
        $document = new EstimateGenerationDocument;
        $document->forceFill([
            'id' => 7,
            'filename' => 'Планировка.jpg',
            'status' => 'ready',
            'processing_stage' => 'completed',
            'progress_percent' => 100,
            'quality_level' => 'medium',
            'quality_score' => 0.76,
            'quality_flags' => [],
            'facts_summary' => [
                'document_understanding' => [
                    'role_for_estimation' => 'needs_review',
                    'extracted_capabilities' => [
                        'requires_manual_review' => true,
                    ],
                ],
            ],
        ]);

        $summary = (new DocumentGenerationReadinessService)->summary(new Collection([$document]));

        self::assertSame(1, $summary['understanding_review_count']);
        self::assertSame(1, $summary['action_required_count']);
        self::assertFalse($summary['can_generate']);
        self::assertContains('document_understanding_requires_review', $summary['problem_flags']);
        self::assertTrue($summary['items'][0]['requires_document_review']);
        self::assertTrue($summary['items'][0]['is_action_required']);
    }

    public function test_ready_low_quality_document_blocks_generation(): void
    {
        $document = new EstimateGenerationDocument;
        $document->forceFill([
            'id' => 8,
            'filename' => 'Размытый скан.pdf',
            'status' => 'ready',
            'processing_stage' => 'completed',
            'progress_percent' => 100,
            'quality_level' => 'unusable',
            'quality_score' => 0.18,
            'quality_flags' => ['ocr_text_too_short'],
            'facts_summary' => [],
        ]);

        $summary = (new DocumentGenerationReadinessService)->summary(new Collection([$document]));

        self::assertSame(1, $summary['low_quality_count']);
        self::assertSame(1, $summary['action_required_count']);
        self::assertFalse($summary['can_generate']);
        self::assertContains('document_low_quality', $summary['problem_flags']);
        self::assertTrue($summary['items'][0]['has_low_quality']);
        self::assertTrue($summary['items'][0]['is_action_required']);
    }

    /** @param array<string, array<string, mixed>> $signals */
    private function qualitySignalDocument(array $signals): EstimateGenerationDocument
    {
        $document = new EstimateGenerationDocument;
        $document->forceFill([
            'id' => 9,
            'filename' => 'plan.png',
            'status' => 'ready',
            'processing_stage' => 'completed',
            'progress_percent' => 100,
            'quality_level' => 'good',
            'quality_score' => 0.95,
            'quality_flags' => [],
            'facts_summary' => [
                'document_understanding' => [
                    'role_for_estimation' => 'geometry_source',
                    'extracted_capabilities' => ['requires_manual_review' => false],
                ],
                'quality_signals' => $signals,
            ],
        ]);

        return $document;
    }

    private function settings(bool $manualReview, string $classification): EffectiveEstimateGenerationSettings
    {
        $snapshot = [
            'schema_version' => 2,
            'models' => ['vision' => 'provider/vision-v2', 'classification' => 'provider/classification-v2', 'normative_matching' => 'provider/normative-v2'],
            'limits' => ['max_files' => 8, 'max_pages_per_file' => 80, 'max_total_pages' => 800],
            'timeouts' => ['vision' => 81, 'classification' => 82, 'normative_matching' => 83],
            'retries' => ['vision' => 0, 'classification' => 1, 'normative_matching' => 2],
            'confidence' => ['classification' => $classification, 'geometry' => '0.8000', 'normative_matching' => '0.9000'],
            'enabled_formats' => ['pdf', 'png'],
            'manual_review' => ['low_confidence' => $manualReview],
            'budgets' => ['daily' => '100.00', 'monthly' => '1000.00', 'currency' => 'RUB'],
        ];

        return EffectiveEstimateGenerationSettings::fromRecord([
            'snapshot_id' => 41,
            'scope' => 'organization',
            'organization_id' => 17,
            'version' => 3,
            'snapshot_hash' => SettingsSnapshotHash::calculate($snapshot),
            'snapshot' => $snapshot,
        ], 17);
    }
}
