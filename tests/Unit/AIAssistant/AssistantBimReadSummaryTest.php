<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AssistantBimModelReader;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class AssistantBimReadSummaryTest extends TestCase
{
    use UsesAssistantUnitTranslations;

    public function test_summary_counts_full_distinct_versions_and_never_the_visible_page(): void
    {
        $models = [$this->model(15, 588), $this->model(19, 248)];
        $text = $this->summary($models);
        self::assertStringStartsWith('Всего в выбранных версиях модели: 836', $text);
        self::assertStringContainsString('588 совпадений по полному индексу', $text);
        self::assertStringContainsString('248 совпадений по полному индексу', $text);
    }

    public function test_unproven_cross_project_duplicate_and_comparison_scopes_never_receive_an_aggregate(): void
    {
        $models = [$this->model(15, 588), $this->model(19, 248)];
        foreach (['metadata', 'semantic', 'partial_scan', 'foreign_project', 'duplicate_version', 'overflow'] as $case) {
            $changed = $models;
            match ($case) {
                'metadata' => $changed[1]['index_coverage']['status'] = 'unproven',
                'semantic' => $changed[1]['semantic_matching_count'] = null,
                'partial_scan' => $changed[1]['scan_complete'] = false,
                'foreign_project' => $changed[1]['version']['project_id'] = 100,
                'duplicate_version' => $changed[1]['version']['id'] = 15,
                'overflow' => $changed[1]['semantic_matching_count'] = PHP_INT_MAX,
            };
            self::assertStringNotContainsString('Всего', $this->summary($changed), $case);
        }
        self::assertStringNotContainsString('Всего', $this->summary($models, [], true));
        self::assertStringNotContainsString('Всего', $this->summary($models, ['compare_version_id' => 1]));
    }

    private function model(int $id, int $count): array
    {
        return ['version' => ['id' => $id, 'project_id' => 52, 'title' => 'АС '.$id],
            'semantic_matching_count' => $count, 'total' => $count, 'scan_complete' => true,
            'index_coverage' => ['status' => 'complete'], 'semantic_coverage' => ['status' => 'complete'],
            'elements' => [['id' => 1]]];
    }

    private function summary(array $models, array $filters = [], bool $tooMany = false): string
    {
        $reader = (new ReflectionClass(AssistantBimModelReader::class))->newInstanceWithoutConstructor();
        return (new ReflectionMethod(AssistantBimModelReader::class, 'summary'))->invoke($reader, $models, $filters, $tooMany);
    }
}
