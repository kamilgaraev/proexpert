<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\SavedViews;

use App\BusinessModules\Core\Reporting\Application\SavedViews\ReportSavedViewVersionHasher;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSavedViewVersionStore;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportFilterSet;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSavedViewVersion;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSavedViewVersionContent;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWindowSort;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSortDirection;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\EloquentReportSavedViewVersionStore;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\Models\ReportSavedViewVersionRecord;
use App\BusinessModules\Core\Reporting\ReportingCatalogServiceProvider;
use DateTimeImmutable;
use Illuminate\Foundation\Application;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ReportSavedViewVersionTest extends TestCase
{
    private const VERSION_ID = '01J00000000000000000000002';

    private const SAVED_VIEW_ID = '01J00000000000000000000001';

    public function test_version_preserves_persisted_identity_and_hashes(): void
    {
        $content = $this->content();
        $version = new ReportSavedViewVersion(
            self::VERSION_ID,
            self::SAVED_VIEW_ID,
            10,
            20,
            7,
            $content,
            new Sha256Hash(hash('sha256', $content->canonicalBytes())),
            new Sha256Hash(str_repeat('b', 64)),
            new DateTimeImmutable('2026-08-01T10:00:00.000000Z'),
        );

        self::assertSame(7, $version->revision);
        self::assertSame($content, $version->content);
        self::assertSame(str_repeat('b', 64), $version->reportDefinitionHash->value);
    }

    public function test_revision_zero_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('report_saved_view_version_invalid');

        $this->version(revision: 0);
    }

    public function test_invalid_ulid_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('report_saved_view_version_invalid');

        $this->version(id: 'invalid');
    }

    public function test_mismatched_content_hash_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('report_saved_view_version_content_hash_mismatch');

        $this->version(contentHash: str_repeat('c', 64));
    }

    public function test_duplicate_columns_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('report_saved_view_version_content_invalid');

        new ReportSavedViewVersionContent(
            'procurement_cycle',
            'v1',
            'Цикл закупки',
            'private',
            new ReportFilterSet([]),
            [],
            new ReportWindowSort('request_number', ReportSortDirection::ASC),
            ['request_number', 'request_number'],
        );
    }

    public function test_noncanonical_content_value_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ReportSavedViewVersionContent::fromArray([
            'report_code' => 'procurement_cycle',
            'contract_version' => 'v1',
            'name' => 'Цикл закупки',
            'visibility' => 'private',
            'filters' => [],
            'comparison' => ['ratio' => NAN],
            'sort' => ['field' => 'request_number', 'direction' => 'asc'],
            'columns' => ['request_number'],
        ]);
    }

    public function test_version_store_contract_exposes_only_append_and_exact_find(): void
    {
        $methods = (new ReflectionClass(ReportSavedViewVersionStore::class))->getMethods();
        $signatures = [];
        foreach ($methods as $method) {
            $signatures[$method->getName()] = array_map(
                static fn ($parameter): string => $parameter->getName(),
                $method->getParameters(),
            );
        }

        self::assertSame([
            'append' => ['data'],
            'find' => ['savedViewId', 'revision'],
        ], $signatures);
    }

    public function test_version_record_and_container_use_immutable_version_contract(): void
    {
        $record = new ReportSavedViewVersionRecord;
        $app = new Application(dirname(__DIR__, 4));
        (new ReportingCatalogServiceProvider($app))->register();

        self::assertSame('report_saved_view_versions', $record->getTable());
        self::assertFalse($record->usesTimestamps());
        self::assertSame([], $record->getGuarded());
        self::assertSame('array', $record->getCasts()['content_json']);
        self::assertSame('immutable_datetime', $record->getCasts()['created_at']);
        self::assertTrue($app->bound(ReportSavedViewVersionStore::class));
        self::assertTrue($app->isShared(ReportSavedViewVersionStore::class));
        self::assertInstanceOf(
            EloquentReportSavedViewVersionStore::class,
            $app->make(ReportSavedViewVersionStore::class),
        );
        self::assertSame(
            $app->make(ReportSavedViewVersionHasher::class),
            $app->make(ReportSavedViewVersionHasher::class),
        );
    }

    private function version(
        string $id = self::VERSION_ID,
        int $revision = 1,
        ?string $contentHash = null,
    ): ReportSavedViewVersion {
        $content = $this->content();

        return new ReportSavedViewVersion(
            $id,
            self::SAVED_VIEW_ID,
            10,
            20,
            $revision,
            $content,
            new Sha256Hash($contentHash ?? hash('sha256', $content->canonicalBytes())),
            new Sha256Hash(str_repeat('b', 64)),
            new DateTimeImmutable('2026-08-01T10:00:00.000000Z'),
        );
    }

    private function content(): ReportSavedViewVersionContent
    {
        return new ReportSavedViewVersionContent(
            'procurement_cycle',
            'v1',
            'Цикл закупки',
            'private',
            new ReportFilterSet(['project_id' => 7]),
            [],
            new ReportWindowSort('request_number', ReportSortDirection::ASC),
            ['request_number'],
        );
    }
}
