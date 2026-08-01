<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting\SavedViews;

use App\BusinessModules\Core\Reporting\Application\SavedViews\ReportSavedViewVersionHasher;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\DTO\CreateReportSavedViewVersionData;
use App\BusinessModules\Core\Reporting\Domain\DTO\PublishedReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportFilterSet;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSavedViewVersionPresentation;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWindowSort;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSortDirection;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\EloquentReportSavedViewVersionStore;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\Reporting\ReportDefinitionBuilder;
use Tests\TestCase;

#[Group('postgresql')]
final class ReportSavedViewVersionPostgresTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        if (getenv('REPORT_SAVED_VIEW_VERSION_POSTGRES_TESTS') !== '1') {
            $this->markTestSkipped(
                'Set REPORT_SAVED_VIEW_VERSION_POSTGRES_TESTS=1 to run isolated saved-view version tests.',
            );
        }

        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('Requires an explicitly configured isolated PostgreSQL database.');
        }

        $database = config('database.connections.pgsql.database');
        if (! is_string($database) || preg_match('/_(?:test|testing)$/D', $database) !== 1) {
            $this->markTestSkipped('PostgreSQL database name must end with _test or _testing.');
        }

        self::assertSame('pgsql', DB::connection()->getDriverName());
    }

    public function test_update_and_delete_are_rejected(): void
    {
        $savedViewId = $this->insertHead(10, 20);
        $version = $this->store()->append($this->data($savedViewId, 10, 20, 1, 'A'));

        $update = $this->captureQueryException(static function () use ($version): void {
            DB::table('report_saved_view_versions')->where('id', $version->id)->update([
                'content_hash' => str_repeat('f', 64),
            ]);
        });
        $delete = $this->captureQueryException(static function () use ($version): void {
            DB::table('report_saved_view_versions')->where('id', $version->id)->delete();
        });

        self::assertSame('55000', $update->errorInfo[0] ?? null);
        self::assertSame('55000', $delete->errorInfo[0] ?? null);
        self::assertSame(1, DB::table('report_saved_view_versions')->where('id', $version->id)->count());
    }

    public function test_version_tenant_must_match_its_head(): void
    {
        $savedViewId = $this->insertHead(10, 20);

        $exception = $this->captureQueryException(function () use ($savedViewId): void {
            $this->store()->append($this->data($savedViewId, 11, 20, 1, 'A'));
        });

        self::assertSame('23503', $exception->errorInfo[0] ?? null);
        self::assertSame(0, DB::table('report_saved_view_versions')->where('saved_view_id', $savedViewId)->count());
    }

    public function test_version_owner_must_match_its_head(): void
    {
        $savedViewId = $this->insertHead(10, 20);

        $exception = $this->captureQueryException(function () use ($savedViewId): void {
            $this->store()->append($this->data($savedViewId, 10, 21, 1, 'A'));
        });

        self::assertSame('23503', $exception->errorInfo[0] ?? null);
        self::assertSame(0, DB::table('report_saved_view_versions')->where('saved_view_id', $savedViewId)->count());
    }

    public function test_find_is_scoped_to_the_head_organization(): void
    {
        $savedViewId = $this->insertHead(10, 20);
        $this->store()->append($this->data($savedViewId, 10, 20, 1, 'A'));

        self::assertNotNull($this->store()->find(10, $savedViewId, 1));
        self::assertNull($this->store()->find(11, $savedViewId, 1));
    }

    public function test_restore_can_append_a_previously_seen_content_hash(): void
    {
        $savedViewId = $this->insertHead(10, 20);
        $first = $this->store()->append($this->data($savedViewId, 10, 20, 1, 'A'));
        $this->store()->append($this->data($savedViewId, 10, 20, 2, 'B'));
        $restored = $this->store()->append($this->data($savedViewId, 10, 20, 3, 'A'));

        self::assertSame($first->contentHash->value, $restored->contentHash->value);
        self::assertSame(3, DB::table('report_saved_view_versions')->where('saved_view_id', $savedViewId)->count());
        self::assertSame([1, 2, 3], DB::table('report_saved_view_versions')->where('saved_view_id', $savedViewId)->orderBy('revision')->pluck('revision')->all());
    }

    public function test_same_revision_remains_the_idempotency_fence(): void
    {
        $savedViewId = $this->insertHead(10, 20);
        $this->store()->append($this->data($savedViewId, 10, 20, 1, 'A'));

        $exception = $this->captureQueryException(function () use ($savedViewId): void {
            $this->store()->append($this->data($savedViewId, 10, 20, 1, 'A'));
        });

        self::assertSame('23505', $exception->errorInfo[0] ?? null);
        self::assertSame(1, DB::table('report_saved_view_versions')->where('saved_view_id', $savedViewId)->count());
    }

    public function test_append_and_find_preserve_microseconds(): void
    {
        $savedViewId = $this->insertHead(10, 20);
        $appended = $this->store()->append($this->data($savedViewId, 10, 20, 1, 'A'));
        $loaded = $this->store()->find(10, $savedViewId, 1);

        self::assertNotNull($loaded);
        self::assertSame(
            $appended->createdAt->format('Y-m-d H:i:s.uP'),
            $loaded->createdAt->format('Y-m-d H:i:s.uP'),
        );
    }

    #[DataProvider('invalidContentBindings')]
    public function test_invalid_content_binding_is_rejected_before_it_can_be_frozen(string $contentJson): void
    {
        $savedViewId = $this->insertHead(10, 20);

        $exception = $this->captureQueryException(static function () use ($savedViewId, $contentJson): void {
            DB::table('report_saved_view_versions')->insert([
                'id' => (string) Str::ulid(),
                'saved_view_id' => $savedViewId,
                'organization_id' => 10,
                'owner_id' => 20,
                'revision' => 1,
                'report_code' => 'procurement_cycle',
                'contract_version' => 'v7',
                'presentation_schema_version' => 1,
                'content_json' => $contentJson,
                'content_hash' => str_repeat('a', 64),
                'report_definition_hash' => str_repeat('b', 64),
                'created_at' => now('UTC'),
            ]);
        });

        self::assertSame('23514', $exception->errorInfo[0] ?? null);
        self::assertSame(
            0,
            DB::table('report_saved_view_versions')->where('saved_view_id', $savedViewId)->count(),
        );
    }

    public static function invalidContentBindings(): iterable
    {
        $validTail = '"name":"A","visibility":"private","filters":{},"comparison":{},'
            .'"sort":{"field":"request_number","direction":"asc"},"columns":["request_number"]';

        yield 'JSON null' => ['null'];
        yield 'missing schema version' => ['{"report_code":"procurement_cycle","contract_version":"v7",'.$validTail.'}'];
        yield 'null schema version' => ['{"schema_version":null,"report_code":"procurement_cycle","contract_version":"v7",'.$validTail.'}'];
        yield 'string schema version' => ['{"schema_version":"1","report_code":"procurement_cycle","contract_version":"v7",'.$validTail.'}'];
        yield 'mismatched schema version' => ['{"schema_version":2,"report_code":"procurement_cycle","contract_version":"v7",'.$validTail.'}'];
        yield 'missing report code' => ['{"schema_version":1,"contract_version":"v7",'.$validTail.'}'];
        yield 'null report code' => ['{"schema_version":1,"report_code":null,"contract_version":"v7",'.$validTail.'}'];
        yield 'non-string report code' => ['{"schema_version":1,"report_code":7,"contract_version":"v7",'.$validTail.'}'];
        yield 'mismatched report code' => ['{"schema_version":1,"report_code":"other_report","contract_version":"v7",'.$validTail.'}'];
        yield 'missing contract version' => ['{"schema_version":1,"report_code":"procurement_cycle",'.$validTail.'}'];
        yield 'null contract version' => ['{"schema_version":1,"report_code":"procurement_cycle","contract_version":null,'.$validTail.'}'];
        yield 'non-string contract version' => ['{"schema_version":1,"report_code":"procurement_cycle","contract_version":7,'.$validTail.'}'];
        yield 'mismatched contract version' => ['{"schema_version":1,"report_code":"procurement_cycle","contract_version":"v8",'.$validTail.'}'];
    }

    private function insertHead(int $organizationId, int $ownerId): string
    {
        $id = (string) Str::ulid();
        $now = now('UTC');
        DB::table('report_saved_views')->insert([
            'id' => $id,
            'organization_id' => $organizationId,
            'owner_id' => $ownerId,
            'report_code' => 'procurement_cycle',
            'contract_version' => 'v7',
            'name' => 'Head',
            'visibility' => 'private',
            'filters_json' => '{}',
            'comparison_json' => '{}',
            'sort_json' => '{"field":"request_number","direction":"asc"}',
            'columns_json' => '["request_number"]',
            'status' => 'active',
            'is_default' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $id;
    }

    private function data(
        string $savedViewId,
        int $organizationId,
        int $ownerId,
        int $revision,
        string $name,
    ): CreateReportSavedViewVersionData {
        return (new ReportSavedViewVersionHasher($this->registry()))->hash(
            $savedViewId,
            $organizationId,
            $ownerId,
            $revision,
            'procurement_cycle',
            new ReportSavedViewVersionPresentation(
                $name,
                'private',
                new ReportFilterSet(['project_id' => 7]),
                [],
                new ReportWindowSort('request_number', ReportSortDirection::ASC),
                ['request_number'],
            ),
        );
    }

    private function registry(): ReportDefinitionRegistry
    {
        $definition = (new ReportDefinitionBuilder)
            ->code('procurement_cycle')
            ->contractVersion('v7')
            ->definitionHash(new Sha256Hash(str_repeat('a', 64)))
            ->published();

        return new class($definition) implements ReportDefinitionRegistry
        {
            public function __construct(private readonly PublishedReportDefinition $definition) {}

            public function published(string $code): PublishedReportDefinition
            {
                if ($code !== $this->definition->code) {
                    throw new \InvalidArgumentException('report_definition_not_found');
                }

                return $this->definition;
            }

            public function publishedCodes(): array
            {
                return [$this->definition->code];
            }

            public function manifestSha256(): Sha256Hash
            {
                return new Sha256Hash(str_repeat('f', 64));
            }
        };
    }

    private function store(): EloquentReportSavedViewVersionStore
    {
        return new EloquentReportSavedViewVersionStore;
    }

    private function captureQueryException(callable $operation): QueryException
    {
        try {
            DB::transaction($operation);
        } catch (QueryException $exception) {
            return $exception;
        }

        self::fail('The PostgreSQL contract was expected to reject the operation.');
    }
}
