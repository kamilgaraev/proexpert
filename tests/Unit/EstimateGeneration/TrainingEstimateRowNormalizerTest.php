<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Services\Training\TrainingEstimateRowNormalizer;
use PHPUnit\Framework\TestCase;

final class TrainingEstimateRowNormalizerTest extends TestCase
{
    public function test_accepts_work_row_with_normative_code(): void
    {
        $row = (new TrainingEstimateRowNormalizer())->normalize([
            'row_number' => 17,
            'item_name' => 'Бетонирование фундаментной ленты B22.5',
            'unit' => 'м3',
            'quantity' => 13.8,
            'code' => 'ФСНБ 01-01-006-01',
            'section_path' => '1. Фундамент',
            'item_type' => 'work',
        ]);

        self::assertSame('accepted', $row['status']);
        self::assertSame('01-01-006-01', $row['norm_code']);
        self::assertSame('м3', $row['work_unit']);
        self::assertContains('valid_training_row', $row['quality_flags']);
        self::assertSame(0.9, $row['quality_score']);
    }

    public function test_skips_sections_and_resource_children(): void
    {
        $normalizer = new TrainingEstimateRowNormalizer();

        $section = $normalizer->normalize([
            'item_name' => 'Фундамент',
            'is_section' => true,
            'code' => '',
        ]);
        $resource = $normalizer->normalize([
            'item_name' => 'Бетон B22.5',
            'is_sub_item' => true,
            'item_type' => 'material',
            'code' => '',
        ]);

        self::assertSame('skipped', $section['status']);
        self::assertContains('section_row', $section['quality_flags']);
        self::assertSame('skipped', $resource['status']);
        self::assertContains('resource_child_row', $resource['quality_flags']);
    }

    public function test_marks_missing_normative_code_as_skipped(): void
    {
        $row = (new TrainingEstimateRowNormalizer())->normalize([
            'item_name' => 'Монтаж оконных блоков',
            'unit' => 'м2',
            'quantity' => 24,
            'code' => '',
        ]);

        self::assertSame('skipped', $row['status']);
        self::assertContains('missing_norm_code', $row['quality_flags']);
    }
}
