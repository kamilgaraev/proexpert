<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\SavedViews;

use App\BusinessModules\Core\Reporting\Application\SavedViews\ReportSavedViewVersionHasher;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportFilterSet;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSavedViewVersionContent;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSavedViewVersionPresentation;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWindowSort;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSortDirection;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tests\Support\Reporting\ReportDefinitionBuilder;
use Tests\Support\Reporting\SavedViewVersionDefinitionRegistry;

final class ReportSavedViewVersionHasherTest extends TestCase
{
    private const SAVED_VIEW_ID = '01J00000000000000000000001';

    private const EXPECTED_CANONICAL_BYTES = '{"columns":["request_number","duration_seconds"],"comparison":{"mode":"previous_period","options":{"include_empty":false,"timezone":"Europe/Moscow"}},"contract_version":"v7","filters":{"period":{"from":"2026-01-01","to":"2026-01-31"},"project_id":7},"name":"Цикл закупки","report_code":"procurement_cycle","schema_version":1,"sort":{"direction":"desc","field":"duration_seconds"},"visibility":"private"}';

    public function test_semantically_equal_map_order_has_one_canonical_hash(): void
    {
        $registry = $this->registry();
        $first = $this->hash($registry, $this->presentation());
        $second = $this->hash($registry, new ReportSavedViewVersionPresentation(
            'Цикл закупки',
            'private',
            new ReportFilterSet([
                'period' => ['to' => '2026-01-31', 'from' => '2026-01-01'],
                'project_id' => 7,
            ]),
            [
                'options' => ['timezone' => 'Europe/Moscow', 'include_empty' => false],
                'mode' => 'previous_period',
            ],
            new ReportWindowSort('duration_seconds', ReportSortDirection::DESC),
            ['request_number', 'duration_seconds'],
        ));

        self::assertSame(self::EXPECTED_CANONICAL_BYTES, $first->content->canonicalBytes());
        self::assertSame(self::EXPECTED_CANONICAL_BYTES, $second->content->canonicalBytes());
        self::assertSame(
            '0d23dac5c001c8b6af6a7a6f1325370d0b4f5462bfaf78f39749ee6dbea13b14',
            $first->contentHash->value,
        );
        self::assertSame($first->contentHash->value, $second->contentHash->value);
    }

    public function test_published_registry_is_the_single_authority_for_definition_binding(): void
    {
        $registry = $this->registry(definitionHash: str_repeat('b', 64), contractVersion: '2026.08');

        $data = $this->hash($registry, $this->presentation());

        self::assertSame(1, $registry->publishedCalls);
        self::assertSame('procurement_cycle', $data->content->reportCode);
        self::assertSame('2026.08', $data->content->contractVersion);
        self::assertSame(str_repeat('b', 64), $data->reportDefinitionHash->value);
    }

    public function test_definition_hash_is_separate_from_the_presentation_content_hash(): void
    {
        $first = $this->hash($this->registry(definitionHash: str_repeat('a', 64)), $this->presentation());
        $second = $this->hash($this->registry(definitionHash: str_repeat('b', 64)), $this->presentation());

        self::assertSame($first->contentHash->value, $second->contentHash->value);
        self::assertSame(str_repeat('a', 64), $first->reportDefinitionHash->value);
        self::assertSame(str_repeat('b', 64), $second->reportDefinitionHash->value);
    }

    public function test_each_presentation_change_changes_content_hash(): void
    {
        $registry = $this->registry();
        $base = $this->hash($registry, $this->presentation())->contentHash->value;
        $variants = [
            new ReportSavedViewVersionPresentation('Другой заголовок', 'private', $this->filters(), $this->comparison(), $this->sort(), $this->columns()),
            new ReportSavedViewVersionPresentation('Цикл закупки', 'private', new ReportFilterSet(['project_id' => 8]), $this->comparison(), $this->sort(), $this->columns()),
            new ReportSavedViewVersionPresentation('Цикл закупки', 'private', $this->filters(), $this->comparison(), new ReportWindowSort('request_number', ReportSortDirection::ASC), $this->columns()),
            new ReportSavedViewVersionPresentation('Цикл закупки', 'private', $this->filters(), $this->comparison(), $this->sort(), ['duration_seconds', 'request_number']),
        ];

        foreach ($variants as $variant) {
            self::assertNotSame($base, $this->hash($registry, $variant)->contentHash->value);
        }
    }

    public function test_persisted_content_requires_the_known_presentation_schema(): void
    {
        $content = $this->hash($this->registry(), $this->presentation())->content->toArray();
        $content['schema_version'] = 2;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('report_saved_view_version_schema_unsupported');

        ReportSavedViewVersionContent::fromArray($content);
    }

    public function test_persisted_content_rejects_unknown_key(): void
    {
        $content = $this->hash($this->registry(), $this->presentation())->content->toArray();
        $content['is_default'] = true;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('report_saved_view_version_content_invalid');

        ReportSavedViewVersionContent::fromArray($content);
    }

    private function hash(
        SavedViewVersionDefinitionRegistry $registry,
        ReportSavedViewVersionPresentation $presentation,
        int $revision = 1,
    ): \App\BusinessModules\Core\Reporting\Domain\DTO\CreateReportSavedViewVersionData {
        return (new ReportSavedViewVersionHasher($registry))->hash(
            self::SAVED_VIEW_ID,
            10,
            20,
            $revision,
            'procurement_cycle',
            $presentation,
        );
    }

    private function registry(
        string $definitionHash = '',
        string $contractVersion = 'v7',
    ): SavedViewVersionDefinitionRegistry {
        return new SavedViewVersionDefinitionRegistry(
            (new ReportDefinitionBuilder)
                ->code('procurement_cycle')
                ->contractVersion($contractVersion)
                ->definitionHash(new Sha256Hash($definitionHash !== '' ? $definitionHash : str_repeat('a', 64)))
                ->published(),
        );
    }

    private function presentation(): ReportSavedViewVersionPresentation
    {
        return new ReportSavedViewVersionPresentation(
            'Цикл закупки',
            'private',
            $this->filters(),
            $this->comparison(),
            $this->sort(),
            $this->columns(),
        );
    }

    private function filters(): ReportFilterSet
    {
        return new ReportFilterSet([
            'project_id' => 7,
            'period' => ['from' => '2026-01-01', 'to' => '2026-01-31'],
        ]);
    }

    private function comparison(): array
    {
        return [
            'mode' => 'previous_period',
            'options' => ['include_empty' => false, 'timezone' => 'Europe/Moscow'],
        ];
    }

    private function sort(): ReportWindowSort
    {
        return new ReportWindowSort('duration_seconds', ReportSortDirection::DESC);
    }

    private function columns(): array
    {
        return ['request_number', 'duration_seconds'];
    }
}
