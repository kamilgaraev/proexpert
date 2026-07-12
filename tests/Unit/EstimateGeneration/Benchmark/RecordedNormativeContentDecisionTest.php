<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Benchmark;

use App\BusinessModules\Addons\EstimateGeneration\Benchmark\RecordedNormativeContentDecision;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO\NormativeCandidateData;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO\NormativeCandidateSetData;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RecordedNormativeContentDecisionTest extends TestCase
{
    #[Test]
    public function rename_and_permutation_resolve_the_same_normative_content_without_id_or_name_oracle(): void
    {
        $selected = $this->candidate('opaque-a', 'Renamed A', '15-01-001-01', 'tiling');
        $other = $this->candidate('opaque-b', 'Renamed B', '15-01-002-01', 'floor_covering');
        $decision = RecordedNormativeContentDecision::capture(
            $selected,
            [$selected, $other],
            ['unit_match', 'technology_match'],
            ['catalog-review:case-a'],
            0.97,
        );

        $renamed = $this->candidate('renamed-selected', 'Completely different display name', '15-01-001-01', 'tiling');
        $renamedOther = $this->candidate('renamed-other', 'Another renamed display name', '15-01-002-01', 'floor_covering');
        $resolved = $decision->resolve($this->set([$renamedOther, $renamed]));
        $pricesByCode = ['15-01-001-01' => ['snapshot_sha256' => str_repeat('a', 64), 'base_price' => '123.45']];

        self::assertSame('renamed-selected', $resolved['selected_candidate_id']);
        self::assertSame('15-01-001-01', $renamed->code);
        self::assertSame(['renamed-selected', 'renamed-other'], $resolved['ordering']);
        self::assertSame('123.45', $pricesByCode[$renamed->code]['base_price']);
        self::assertSame(str_repeat('a', 64), $pricesByCode[$renamed->code]['snapshot_sha256']);
    }

    #[Test]
    public function changed_selected_content_fingerprint_is_rejected(): void
    {
        $selected = $this->candidate('a', 'A', '15-01-001-01', 'tiling');
        $decision = RecordedNormativeContentDecision::capture($selected, [$selected], ['unit_match'], ['review:a'], 0.9);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('recorded_normative_content_mismatch');

        $decision->resolve($this->set([$this->candidate('renamed', 'Renamed', '15-01-001-01', 'painting')]));
    }

    private function candidate(string $id, string $name, string $code, string $technology): NormativeCandidateData
    {
        return new NormativeCandidateData($id, 101, 11, 'dataset-v1', 'parsed', $code, $name, 'm2', 'area',
            'concrete', $technology, 'finishing', '15', 'floor', '77', new DateTimeImmutable('2026-01-01'), null,
            0.9, 0.9, 'recorded-catalog:v1', null, ['catalog:evidence']);
    }

    private function set(array $candidates): NormativeCandidateSetData
    {
        return new NormativeCandidateSetData(1, 1, 1, 'work-1', 'dataset-v1', 'recorded-catalog:v1', null, $candidates);
    }
}
