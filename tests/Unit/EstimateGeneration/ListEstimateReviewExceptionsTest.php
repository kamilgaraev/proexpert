<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Review\EstimateReviewExceptionSource;
use App\BusinessModules\Addons\EstimateGeneration\Application\Review\ListEstimateReviewExceptions;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use Illuminate\Database\Eloquent\Collection;
use PHPUnit\Framework\TestCase;

final class ListEstimateReviewExceptionsTest extends TestCase
{
    public function test_default_view_filters_minor_items_and_sorts_by_blocking_cost_severity_and_identity(): void
    {
        $service = new ListEstimateReviewExceptions($this->source([
            $this->item('optional', 'technology_recommendation', false, '100.00', 'warning', '0.70'),
            $this->item('blocking-low', 'conflict', true, '10.00', 'warning', '0.80'),
            $this->item('blocking-high', 'missing_required_data', true, '200.50', 'blocking', '0.90'),
            $this->item('minor', 'informational', false, null, 'optional', '1.00'),
        ]));

        $result = $service->handle($this->session(), []);

        self::assertSame(['blocking-high', 'blocking-low', 'optional'], array_column($result['items'], 'id'));
        self::assertSame(3, $result['summary']['unresolved']);
        self::assertSame(2, $result['summary']['blocking']);
        self::assertSame(1, $result['summary']['nonblocking']);
        self::assertSame(3, $result['summary']['known_cost_impact']);
        self::assertFalse($result['summary']['review_alone_blocks_export']);
    }

    public function test_combined_filters_are_applied_without_losing_machine_codes_or_provenance(): void
    {
        $matching = $this->item('match', 'low_confidence', false, null, 'warning', '0.30', [
            'floor' => '2', 'room' => '201', 'section' => 'roof', 'origin' => 'stage5',
            'unresolved_type' => 'question', 'codes' => ['technology_low_confidence'],
            'provenance' => ['source_version' => 'source-v7'],
        ]);
        $service = new ListEstimateReviewExceptions($this->source([$matching, $this->item('other', 'conflict', true, '50.00', 'blocking', '0.90')]));

        $result = $service->handle($this->session(), [
            'severity' => 'warning', 'floor' => '2', 'room' => '201', 'section' => 'roof',
            'origin' => 'stage5', 'cost_impact' => 'unknown', 'unresolved_type' => 'question',
        ]);

        self::assertSame(['match'], array_column($result['items'], 'id'));
        self::assertSame(['technology_low_confidence'], $result['items'][0]['codes']);
        self::assertSame('source-v7', $result['items'][0]['provenance']['source_version']);
    }

    public function test_cursor_is_stable_version_bound_and_limit_is_bounded(): void
    {
        $items = [];
        foreach (range(1, 105) as $index) {
            $items[] = $this->item(sprintf('item-%03d', $index), 'conflict', false, null, 'warning', '0.5');
        }
        $service = new ListEstimateReviewExceptions($this->source($items));

        $first = $service->handle($this->session(), ['limit' => 500]);
        $second = $service->handle($this->session(), ['limit' => 100, 'cursor' => $first['meta']['next_cursor']]);

        self::assertCount(100, $first['items']);
        self::assertCount(5, $second['items']);
        self::assertSame([], array_intersect(array_column($first['items'], 'id'), array_column($second['items'], 'id')));
        self::assertTrue($first['meta']['overflow']);
        self::assertNull($second['meta']['next_cursor']);

        $this->expectExceptionMessage('estimate_generation.review_cursor_stale');
        $stale = $this->session();
        $stale->state_version = 9;
        $service->handle($stale, ['cursor' => $first['meta']['next_cursor']]);
    }

    public function test_cursor_is_bound_to_scope_filters_and_origin_qualified_identity(): void
    {
        $service = new ListEstimateReviewExceptions($this->source([
            $this->item('same-id', 'conflict', true, '10.00', 'blocking', '0.80', ['origin' => 'stage4']),
            $this->item('same-id', 'conflict', true, '10.00', 'blocking', '0.80', ['origin' => 'stage5']),
            $this->item('same-id', 'conflict', true, '10.00', 'blocking', '0.80', [
                'origin' => 'stage5', 'provenance' => ['source_version' => 'v2'],
            ]),
        ]));

        $first = $service->handle($this->session(), ['limit' => 1, 'severity' => 'blocking']);
        $second = $service->handle($this->session(), [
            'limit' => 1,
            'severity' => 'blocking',
            'cursor' => $first['meta']['next_cursor'],
        ]);

        self::assertCount(1, $second['items']);
        self::assertNotSame($first['items'][0]['origin'], $second['items'][0]['origin']);
        $third = $service->handle($this->session(), [
            'limit' => 1,
            'severity' => 'blocking',
            'cursor' => $second['meta']['next_cursor'],
        ]);
        self::assertCount(1, $third['items']);
        self::assertNotSame($second['items'][0]['provenance']['source_version'], $third['items'][0]['provenance']['source_version']);

        $crossSession = $this->session();
        $crossSession->id = 10;
        try {
            $service->handle($crossSession, [
                'limit' => 1,
                'severity' => 'blocking',
                'cursor' => $first['meta']['next_cursor'],
            ]);
            self::fail('Cursor from another session must be rejected.');
        } catch (\RuntimeException $exception) {
            self::assertSame('estimate_generation.review_cursor_stale', $exception->getMessage());
        }

        $this->expectExceptionMessage('estimate_generation.review_cursor_stale');
        $service->handle($this->session(), [
            'limit' => 1,
            'severity' => 'warning',
            'cursor' => $first['meta']['next_cursor'],
        ]);
    }

    public function test_locator_and_cost_are_bounded_and_never_expose_internal_fields(): void
    {
        $locators = array_fill(0, 25, [
            'artifact_id' => 11, 'source_version' => 'v2', 'representation_kind' => 'page', 'page' => 4,
            'region' => ['x' => 0.1, 'y' => 0.2, 'width' => 0.3, 'height' => 0.4],
            'native_reference' => null, 'prompt' => 'secret',
        ]);
        $item = $this->item('located', 'conflict', true, '1234567890.1234', 'blocking', '0.80', [
            'locators' => $locators, 'internal_exception' => 'stack trace',
        ]);

        $result = (new ListEstimateReviewExceptions($this->source([$item])))->handle($this->session(), []);

        self::assertSame('1234567890.1234', $result['items'][0]['cost_impact']['amount']);
        self::assertCount(16, $result['items'][0]['locators']);
        self::assertSame('page', $result['items'][0]['locators'][0]['representation_kind'] ?? null);
        self::assertArrayNotHasKey('prompt', $result['items'][0]['locators'][0]);
        self::assertArrayNotHasKey('internal_exception', $result['items'][0]);
    }

    public function test_locator_rejects_non_finite_negative_and_incompatible_coordinates(): void
    {
        $item = $this->item('located', 'conflict', true, null, 'blocking', '0.80', [
            'locators' => [
                ['artifact_id' => 11, 'representation_kind' => 'page', 'page' => 1, 'region' => ['x' => INF, 'y' => 0, 'width' => 1, 'height' => 1]],
                ['artifact_id' => 11, 'representation_kind' => 'page', 'page' => 1, 'region' => ['x' => -1, 'y' => 0, 'width' => 1, 'height' => 1]],
                ['artifact_id' => 11, 'representation_kind' => 'sheet', 'page' => 1],
            ],
        ]);

        $result = (new ListEstimateReviewExceptions($this->source([$item])))->handle($this->session(), []);

        self::assertSame([], $result['items'][0]['locators']);
    }

    public function test_locator_rejects_foreign_artifact_stale_version_and_missing_explicit_kind(): void
    {
        $item = $this->item('located', 'conflict', true, null, 'blocking', '0.80', [
            'locators' => [
                ['artifact_id' => 12, 'source_version' => 'v2', 'representation_kind' => 'page', 'page' => 1],
                ['artifact_id' => 11, 'source_version' => 'v1', 'representation_kind' => 'page', 'page' => 1],
                ['artifact_id' => 11, 'source_version' => 'v2', 'page' => 1],
                ['artifact_id' => 11, 'source_version' => 'v2', 'representation_kind' => 'page', 'page' => 1],
            ],
        ]);

        $result = (new ListEstimateReviewExceptions($this->source([$item])))->handle($this->session(), []);

        self::assertCount(1, $result['items'][0]['locators']);
        self::assertSame(11, $result['items'][0]['locators'][0]['artifact_id']);
        self::assertSame('v2', $result['items'][0]['locators'][0]['source_version']);
    }

    private function session(): EstimateGenerationSession
    {
        $session = new EstimateGenerationSession(['organization_id' => 7, 'project_id' => 8, 'state_version' => 4]);
        $session->id = 9;
        $document = new EstimateGenerationDocument(['session_id' => 9, 'source_version' => 'v2']);
        $document->id = 11;
        $session->setRelation('documents', new Collection([$document]));

        return $session;
    }

    private function source(array $items): EstimateReviewExceptionSource
    {
        return new class($items) implements EstimateReviewExceptionSource
        {
            public function __construct(private readonly array $items) {}

            public function current(EstimateGenerationSession $session, int $limit): array
            {
                return ['items' => array_slice($this->items, 0, $limit), 'truncated' => count($this->items) > $limit];
            }
        };
    }

    private function item(
        string $id,
        string $type,
        bool $blocking,
        ?string $cost,
        string $severity,
        string $confidence,
        array $overrides = [],
    ): array {
        return array_replace([
            'id' => $id, 'type' => $type, 'type_label' => 'Проверка', 'title' => 'Проверить позицию',
            'blocking' => $blocking, 'severity' => $severity, 'confidence' => $confidence,
            'cost_impact' => ['state' => $cost === null ? 'unknown' : 'known', 'amount' => $cost, 'currency' => 'RUB'],
            'floor' => null, 'room' => null, 'section' => null, 'origin' => 'stage4',
            'unresolved_type' => $type, 'codes' => [$type], 'provenance' => ['source_version' => 'v1'],
            'locators' => [], 'recommendation' => null,
        ], $overrides);
    }
}
