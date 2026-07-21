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

    /** @return array<string, mixed> */
    private function candidate(string $id, string $code, string $name): array
    {
        return [
            'candidate_id' => $id,
            'normative_id' => 1,
            'dataset_id' => 1,
            'dataset_version' => 'fsnb-2026.1',
            'dataset_status' => 'active',
            'code' => $code,
            'name' => $name,
            'unit' => 'шт',
            'section' => ['code' => '07-01', 'name' => 'Конструкции сборные железобетонные'],
            'retrieval_metadata' => [],
            'work_composition' => [],
        ];
    }
}
