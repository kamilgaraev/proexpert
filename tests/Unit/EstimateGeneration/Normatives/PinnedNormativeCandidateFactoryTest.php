<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Normatives;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO\WorkIntentData;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\PinnedNormativeCandidateFactory;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\ResidentialMaterialScenarioCatalog;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class PinnedNormativeCandidateFactoryTest extends TestCase
{
    public function test_signed_intent_code_filters_pinned_candidates_when_work_item_has_no_code(): void
    {
        $scenario = (new ResidentialMaterialScenarioCatalog)->issue('walls.lintels', 'residential');
        self::assertIsArray($scenario);

        $candidates = [
            $this->candidate('foreign', '07-01-019-01', 'Монтаж железобетонных элементов'),
            $this->candidate('lintel', '07-01-021-01', 'Укладка перемычек при наибольшей массе монтажных элементов в здании: до 5 т, масса перемычки до 0,7 т'),
        ];

        $intent = new WorkIntentData(
            1, 89, 58, 'walls-lintels', (string) $scenario['normative_search_text'], 'шт', '', '',
            'general_work', 'walls', '07', 'residential', 'fsnb-2026.1', 'active', null,
            new DateTimeImmutable('2026-07-21'), ['doc:1'], ['07'], '07-01-021-01', '', '', [], $scenario,
        );

        $selected = (new PinnedNormativeCandidateFactory)->forWorkItem(
            $candidates,
            ['name' => 'Устройство перемычек', 'unit' => 'шт'],
            ['07'],
            $intent,
        );

        self::assertSame(['lintel'], array_map(static fn ($candidate): string => $candidate->id, $selected));
    }

    public function test_work_item_candidate_map_strictly_limits_the_pinned_catalog(): void
    {
        $candidates = [
            $this->candidate('roof-rate', '12-01-001-01', 'Монтаж покрытия', '12-01', 'м2'),
            $this->candidate('roof-rate-alternative', '12-01-001-02', 'Монтаж покрытия', '12-01', 'м2'),
        ];

        $selected = (new PinnedNormativeCandidateFactory)->forWorkItem(
            $candidates,
            ['key' => 'roof-norm-intent-1', 'name' => 'Монтаж покрытия', 'unit' => 'м2'],
            ['12'],
            null,
            ['roof-norm-intent-1' => ['roof-rate']],
        );

        self::assertSame(['roof-rate'], array_map(static fn ($item): string => $item->id, $selected));
    }

    public function test_new_candidate_map_without_the_current_work_item_has_no_candidates(): void
    {
        $selected = (new PinnedNormativeCandidateFactory)->forWorkItem(
            [$this->candidate('roof-rate', '12-01-001-01', 'Монтаж покрытия', '12-01', 'м2')],
            ['key' => 'roof-norm-intent-1', 'name' => 'Монтаж покрытия', 'unit' => 'м2'],
            ['12'],
            null,
            ['other-work-item' => ['roof-rate']],
        );

        self::assertSame([], $selected);
    }

    /** @return array<string, mixed> */
    private function candidate(
        string $id,
        string $code,
        string $name,
        string $sectionCode = '07-01',
        string $unit = 'шт',
    ): array
    {
        return [
            'candidate_id' => $id,
            'normative_id' => 1,
            'dataset_id' => 1,
            'dataset_version' => 'fsnb-2026.1',
            'dataset_status' => 'active',
            'code' => $code,
            'name' => $name,
            'unit' => $unit,
            'section' => ['code' => $sectionCode, 'name' => 'Конструкции сборные железобетонные'],
            'retrieval_metadata' => [],
            'work_composition' => [],
        ];
    }
}
