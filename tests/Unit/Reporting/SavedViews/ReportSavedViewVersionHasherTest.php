<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\SavedViews;

use App\BusinessModules\Core\Reporting\Application\SavedViews\ReportSavedViewVersionHasher;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportFilterSet;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSavedViewVersionContent;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWindowSort;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSortDirection;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ReportSavedViewVersionHasherTest extends TestCase
{
    private const SAVED_VIEW_ID = '01J00000000000000000000001';

    private const EXPECTED_CANONICAL_BYTES = '{"columns":["request_number","duration_seconds"],"comparison":{"mode":"previous_period","options":{"include_empty":false,"timezone":"Europe/Moscow"}},"contract_version":"v1","filters":{"period":{"from":"2026-01-01","to":"2026-01-31"},"project_id":7},"name":"Цикл закупки","report_code":"procurement_cycle","sort":{"direction":"desc","field":"duration_seconds"},"visibility":"private"}';

    private const EXPECTED_CONTENT_HASH = '448907a8bbe4ae855d4bbd01f140aae80a7b1a086231253ef95f6112486090cc';

    public function test_semantically_equal_map_order_has_one_canonical_hash(): void
    {
        $first = $this->content();
        $second = new ReportSavedViewVersionContent(
            'procurement_cycle',
            'v1',
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
        );

        $firstData = $this->hash($first, str_repeat('a', 64));
        $secondData = $this->hash($second, str_repeat('a', 64));

        self::assertSame(self::EXPECTED_CANONICAL_BYTES, $first->canonicalBytes());
        self::assertSame(self::EXPECTED_CANONICAL_BYTES, $second->canonicalBytes());
        self::assertSame(self::EXPECTED_CONTENT_HASH, $firstData->contentHash->value);
        self::assertSame($firstData->contentHash->value, $secondData->contentHash->value);
    }

    public function test_each_presentation_change_changes_content_hash(): void
    {
        $base = $this->hash($this->content(), str_repeat('a', 64))->contentHash->value;
        $variants = [
            new ReportSavedViewVersionContent(
                'procurement_cycle',
                'v1',
                'Другой заголовок',
                'private',
                $this->filters(),
                $this->comparison(),
                $this->sort(),
                $this->columns(),
            ),
            new ReportSavedViewVersionContent(
                'procurement_cycle',
                'v1',
                'Цикл закупки',
                'private',
                new ReportFilterSet(['project_id' => 8]),
                $this->comparison(),
                $this->sort(),
                $this->columns(),
            ),
            new ReportSavedViewVersionContent(
                'procurement_cycle',
                'v1',
                'Цикл закупки',
                'private',
                $this->filters(),
                $this->comparison(),
                new ReportWindowSort('request_number', ReportSortDirection::ASC),
                $this->columns(),
            ),
            new ReportSavedViewVersionContent(
                'procurement_cycle',
                'v1',
                'Цикл закупки',
                'private',
                $this->filters(),
                $this->comparison(),
                $this->sort(),
                ['duration_seconds', 'request_number'],
            ),
        ];

        foreach ($variants as $variant) {
            self::assertNotSame($base, $this->hash($variant, str_repeat('a', 64))->contentHash->value);
        }
    }

    public function test_definition_hash_is_stored_separately_from_content_hash(): void
    {
        $content = $this->content();

        $first = $this->hash($content, str_repeat('a', 64));
        $second = $this->hash($content, str_repeat('b', 64));

        self::assertSame($first->contentHash->value, $second->contentHash->value);
        self::assertSame(str_repeat('a', 64), $first->reportDefinitionHash->value);
        self::assertSame(str_repeat('b', 64), $second->reportDefinitionHash->value);
    }

    public function test_persisted_content_rejects_unknown_key(): void
    {
        $content = $this->content()->toArray();
        $content['is_default'] = true;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('report_saved_view_version_content_invalid');

        ReportSavedViewVersionContent::fromArray($content);
    }

    private function hash(
        ReportSavedViewVersionContent $content,
        string $definitionHash,
    ): \App\BusinessModules\Core\Reporting\Domain\DTO\CreateReportSavedViewVersionData {
        return (new ReportSavedViewVersionHasher)->hash(
            self::SAVED_VIEW_ID,
            10,
            20,
            1,
            $content,
            new Sha256Hash($definitionHash),
        );
    }

    private function content(): ReportSavedViewVersionContent
    {
        return new ReportSavedViewVersionContent(
            'procurement_cycle',
            'v1',
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
