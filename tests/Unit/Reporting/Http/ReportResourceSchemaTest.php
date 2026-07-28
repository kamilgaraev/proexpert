<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Http;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCatalogView;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDownloadLink;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportFilterSet;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPage;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPermissionPolicy;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportResourceLink;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSavedView;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWindowSort;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportFreshnessStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportPublicationReadiness;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSortDirection;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Http\Admin\Resources\ReportCatalogResource;
use App\BusinessModules\Core\Reporting\Http\Admin\Resources\ReportDownloadLinkResource;
use App\BusinessModules\Core\Reporting\Http\Admin\Resources\ReportDrillDownResource;
use App\BusinessModules\Core\Reporting\Http\Admin\Resources\ReportExportResource;
use App\BusinessModules\Core\Reporting\Http\Admin\Resources\ReportRowsResource;
use App\BusinessModules\Core\Reporting\Http\Admin\Resources\ReportRunResource;
use App\BusinessModules\Core\Reporting\Http\Admin\Resources\ReportSavedViewResource;
use DateTimeImmutable;
use InvalidArgumentException;
use Illuminate\Http\Request;
use Opis\JsonSchema\CompliantValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\Reporting\ReportExportBuilder;
use Tests\Support\Reporting\ReportRunBuilder;

final class ReportResourceSchemaTest extends TestCase
{
    #[DataProvider('resourceBranches')]
    public function test_resource_serializes_exact_fixture_data(string $branch): void
    {
        self::assertFileExists($this->fixturePath());
        $fixture = $this->fixtures()[$branch];
        $resource = $this->resource($branch);

        self::assertSame($fixture['data'], $resource->toArray(Request::create('/')));
        if ($branch === 'rows') {
            self::assertSame($fixture['meta'], $resource->additional);
        }
    }

    public static function resourceBranches(): array
    {
        return array_map(static fn (string $branch): array => [$branch], [
            'catalog',
            'run',
            'rows',
            'drill_down',
            'export',
            'download_link',
            'saved_view',
        ]);
    }

    public function test_all_seven_envelopes_match_draft_2020_12_schema(): void
    {
        self::assertFileExists($this->schemaPath());
        self::assertFileExists($this->fixturePath());
        $schema = json_decode(file_get_contents($this->schemaPath()), false, 512, JSON_THROW_ON_ERROR);
        $fixtures = json_decode(file_get_contents($this->fixturePath()), false, 512, JSON_THROW_ON_ERROR);
        $validator = new CompliantValidator();

        foreach (['catalog', 'run', 'rows', 'drill_down', 'export', 'download_link', 'saved_view'] as $branch) {
            self::assertTrue($validator->validate($fixtures->{$branch}, $schema)->isValid(), $branch);
        }
    }

    public function test_unknown_field_and_wrong_enum_fail_schema(): void
    {
        $unknown = $this->fixtureClone('download_link');
        $unknown->data->unexpected = true;
        self::assertFalse($this->validator()->validate($unknown, $this->schema())->isValid());

        $wrongStatus = $this->fixtureClone('run');
        $wrongStatus->data->status = 'readyish';
        self::assertFalse($this->validator()->validate($wrongStatus, $this->schema())->isValid());
    }

    #[DataProvider('invalidCatalogs')]
    public function test_catalog_rejects_invalid_contracts(callable $factory): void
    {
        $this->expectException(InvalidArgumentException::class);
        $factory();
    }

    public static function invalidCatalogs(): array
    {
        return [
            'contract version' => [static fn () => new ReportCatalogView('2.0.0', new Sha256Hash(str_repeat('a', 64)), [])],
            'empty definitions' => [static fn () => new ReportCatalogView('1.0.0', new Sha256Hash(str_repeat('a', 64)), [])],
            'wrong definition type' => [static fn () => new ReportCatalogView('1.0.0', new Sha256Hash(str_repeat('a', 64)), ['bad'])],
            'duplicate definition code' => [static fn () => new ReportCatalogView('1.0.0', new Sha256Hash(str_repeat('a', 64)), [self::definition(), self::definition()])],
        ];
    }

    #[DataProvider('invalidSavedViews')]
    public function test_saved_view_rejects_invalid_contracts(callable $factory): void
    {
        $this->expectException(InvalidArgumentException::class);
        $factory();
    }

    public static function invalidSavedViews(): array
    {
        return [
            'ulid' => [static fn () => self::savedView(id: 'bad')],
            'name' => [static fn () => self::savedView(name: '')],
            'visibility' => [static fn () => self::savedView(visibility: 'public')],
            'status' => [static fn () => self::savedView(status: 'legacy')],
            'timestamps' => [static fn () => self::savedView(
                createdAt: new DateTimeImmutable('2026-01-02T00:00:00+00:00'),
                updatedAt: new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            )],
        ];
    }

    private function resource(string $branch): object
    {
        return match ($branch) {
            'catalog' => new ReportCatalogResource(new ReportCatalogView('1.0.0', new Sha256Hash(str_repeat('a', 64)), [self::definition()])),
            'run' => new ReportRunResource((new ReportRunBuilder())->ready()),
            'rows' => new ReportRowsResource(new ReportPage(
                [['row_key' => 'row_1', 'amount' => '10.50']],
                ['amount' => '10.50'],
                ReportFreshnessStatus::FRESH,
                (new ReportRunBuilder())->ready()->quality,
                null,
                50,
                false,
                new ReportWindowSort('amount', ReportSortDirection::DESC),
            )),
            'drill_down' => new ReportDrillDownResource(new ReportDrillDownResult(
                [['row_key' => 'drill_1', 'name' => 'Проект']],
                null,
                [new ReportResourceLink('project', 'project_1', 'admin.projects.show', ['project_id' => 1], 'available')],
            )),
            'export' => new ReportExportResource((new ReportExportBuilder())->ready()),
            'download_link' => new ReportDownloadLinkResource(new ReportDownloadLink(
                'https://files.example.test/report.csv',
                'version-1',
                new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
                new DateTimeImmutable('2026-01-01T00:05:00+00:00'),
            )),
            'saved_view' => new ReportSavedViewResource(self::savedView()),
        };
    }

    private static function definition(): ReportDefinition
    {
        return new ReportDefinition(
            'cash_flow',
            new Sha256Hash(str_repeat('b', 64)),
            '1.0.0',
            '1',
            '1',
            '1',
            [['id' => 'project_id', 'type' => 'reference']],
            [['id' => 'amount', 'type' => 'decimal']],
            [['id' => 'amount']],
            ['csv', 'xlsx', 'pdf'],
            new ReportPermissionPolicy(['reports.view'], ['reports.export'], [], []),
            \App\BusinessModules\Core\Reporting\Domain\Enums\ReportSnapshotClassification::OPERATIONAL,
            new \App\BusinessModules\Core\Reporting\Domain\DTO\ReportOutputClassification(\App\BusinessModules\Core\Reporting\Domain\Enums\ReportDataClassification::STANDARD, [], [], false, false, false),
            ReportPublicationReadiness::PUBLISHED,
            true,
        );
    }

    private static function savedView(
        string $id = '01J00000000000000000000002',
        string $name = 'Основной вид',
        string $visibility = 'private',
        string $status = 'active',
        ?DateTimeImmutable $createdAt = null,
        ?DateTimeImmutable $updatedAt = null,
    ): ReportSavedView {
        return new ReportSavedView(
            $id,
            'cash_flow',
            '1.0.0',
            $name,
            $visibility,
            new ReportFilterSet(['project_id' => ['operator' => 'eq', 'value' => 1]]),
            ['period' => 'previous'],
            new ReportWindowSort('amount', ReportSortDirection::DESC),
            ['amount'],
            $status,
            true,
            $createdAt ?? new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            $updatedAt ?? new DateTimeImmutable('2026-01-01T00:01:00+00:00'),
        );
    }

    private function fixtures(): array
    {
        return json_decode(file_get_contents($this->fixturePath()), true, 512, JSON_THROW_ON_ERROR);
    }

    private function fixtureClone(string $branch): object
    {
        return json_decode(json_encode($this->fixtures()[$branch], JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
    }

    private function validator(): CompliantValidator
    {
        return new CompliantValidator();
    }

    private function schema(): object
    {
        return json_decode(file_get_contents($this->schemaPath()), false, 512, JSON_THROW_ON_ERROR);
    }

    private function fixturePath(): string
    {
        return dirname(__DIR__, 4).'/tests/Fixtures/Reporting/Wire/reporting-admin-resources.v1.json';
    }

    private function schemaPath(): string
    {
        return dirname(__DIR__, 4).'/docs/reports/contracts/reporting-admin-resources.v1.schema.json';
    }
}
