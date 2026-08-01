<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Input;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Application\Input\CreateReportDownloadLinkData;
use App\BusinessModules\Core\Reporting\Application\Input\CreateReportExportData;
use App\BusinessModules\Core\Reporting\Application\Input\CreateReportRunData;
use App\BusinessModules\Core\Reporting\Application\Input\ReportDrillDownNormalizer;
use App\BusinessModules\Core\Reporting\Application\Input\ReportExportNormalizer;
use App\BusinessModules\Core\Reporting\Application\Input\ReportFilterNormalizer;
use App\BusinessModules\Core\Reporting\Application\Input\ReportFilterReferenceResolver;
use App\BusinessModules\Core\Reporting\Application\Input\ReportRowsWindowNormalizer;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportFilterSet;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPermissionPolicy;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWindowSort;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportPublicationReadiness;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSortDirection;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\Reporting\ReportExecutionContextBuilder;

final class ReportInputNormalizerTest extends TestCase
{
    #[Test]
    public function filters_normalize_all_supported_scalar_types_and_operators(): void
    {
        $result = $this->filterNormalizer()->normalize(
            (new ReportExecutionContextBuilder)->build(),
            $this->definition(),
            [
                'name' => ['value' => '  Мост  ', 'operator' => 'contains'],
                'count' => ['operator' => 'gte', 'value' => '12'],
                'amount' => ['operator' => 'between', 'value' => ['001.2300', '2']],
                'active' => ['operator' => 'eq', 'value' => true],
                'day' => ['operator' => 'eq', 'value' => '2026-07-26'],
                'moment' => ['operator' => 'lt', 'value' => '2026-07-26T12:30:00+03:00'],
            ],
        );

        self::assertSame('Мост', $result->values['name']['value']);
        self::assertSame(12, $result->values['count']['value']);
        self::assertSame(['1.23', '2'], $result->values['amount']['value']);
        self::assertTrue($result->values['active']['value']);
        self::assertSame('2026-07-26', $result->values['day']['value']);
        self::assertSame('2026-07-26T09:30:00+00:00', $result->values['moment']['value']);
    }

    #[Test]
    public function list_operators_require_non_empty_lists_and_between_requires_two_values(): void
    {
        foreach ([
            ['tags' => ['operator' => 'in', 'value' => []]],
            ['amount' => ['operator' => 'between', 'value' => ['1']]],
        ] as $input) {
            $this->assertError(
                ReportErrorCode::REPORT_FILTER_RANGE_INVALID,
                fn () => $this->filterNormalizer()->normalize(
                    (new ReportExecutionContextBuilder)->build(),
                    $this->definition(),
                    $input,
                ),
            );
        }
    }

    #[Test]
    public function unknown_filter_operator_and_declared_type_are_unsupported(): void
    {
        foreach ([
            [$this->definition(), ['missing' => ['operator' => 'eq', 'value' => 1]]],
            [$this->definition(), ['count' => ['operator' => 'contains', 'value' => 1]]],
            [$this->definition(filters: [['id' => 'broken', 'type' => 'json', 'operators' => ['eq']]]), ['broken' => ['operator' => 'eq', 'value' => 1]]],
            [
                $this->definition(filters: [
                    ['id' => 'name', 'type' => 'string', 'operators' => ['eq']],
                    ['id' => 'broken', 'type' => 'json', 'operators' => ['eq']],
                ]),
                ['name' => ['operator' => 'eq', 'value' => 'МОСТ']],
            ],
        ] as [$definition, $input]) {
            $this->assertError(
                ReportErrorCode::REPORT_FILTER_UNSUPPORTED,
                fn () => $this->filterNormalizer()->normalize(
                    (new ReportExecutionContextBuilder)->build(),
                    $definition,
                    $input,
                ),
            );
        }
    }

    #[Test]
    public function malformed_filter_payload_is_rejected(): void
    {
        foreach ([
            ['count' => ['operator' => 'eq', 'value' => 1, 'extra' => true]],
            ['count' => 1],
            ['count' => ['operator' => 'eq']],
        ] as $input) {
            $this->assertError(
                ReportErrorCode::REPORT_REQUEST_INVALID,
                fn () => $this->filterNormalizer()->normalize(
                    (new ReportExecutionContextBuilder)->build(),
                    $this->definition(),
                    $input,
                ),
            );
        }
    }

    #[Test]
    public function reference_values_are_resolved_with_server_scope_and_preserve_list_order(): void
    {
        $calls = [];
        $normalizer = new ReportFilterNormalizer(
            new class($calls) implements ReportFilterReferenceResolver
            {
                public function __construct(private array &$calls) {}

                public function resolve(ReportScope $scope, string $filter, int|string $value): int|string
                {
                    $this->calls[] = [$scope->organizationId, $filter, $value];

                    return is_int($value) ? $value + 100 : strtoupper($value);
                }
            },
        );

        $result = $normalizer->normalize(
            (new ReportExecutionContextBuilder)->build(),
            $this->definition(),
            ['projects' => ['operator' => 'in', 'value' => [4, 'external']]],
        );

        self::assertSame([104, 'EXTERNAL'], $result->values['projects']['value']);
        self::assertSame([[1, 'projects', 4], [1, 'projects', 'external']], $calls);
    }

    #[Test]
    public function missing_and_foreign_reference_values_are_indistinguishable(): void
    {
        $normalizer = new ReportFilterNormalizer(
            new class implements ReportFilterReferenceResolver
            {
                public function resolve(ReportScope $scope, string $filter, int|string $value): int|string
                {
                    throw ReportContractException::fromCode(
                        ReportErrorCode::REPORT_FILTER_VALUE_NOT_FOUND,
                        ['fields' => $value === 999 ? 'columns' : 'format'],
                    );
                }
            },
        );

        $exceptions = [];
        foreach ([999, 1000] as $value) {
            try {
                $normalizer->normalize(
                    (new ReportExecutionContextBuilder)->build(),
                    $this->definition(),
                    ['projects' => ['operator' => 'eq', 'value' => $value]],
                );
                self::fail('Неизвестное ссылочное значение было принято.');
            } catch (ReportContractException $exception) {
                $exceptions[] = $exception;
            }
        }

        self::assertSame(ReportErrorCode::REPORT_FILTER_VALUE_NOT_FOUND, $exceptions[0]->errorCode);
        self::assertSame(ReportErrorCode::REPORT_FILTER_VALUE_NOT_FOUND, $exceptions[1]->errorCode);
        self::assertSame([], $exceptions[0]->safeFields);
        self::assertSame([], $exceptions[1]->safeFields);
    }

    #[Test]
    public function reference_filter_rejects_non_scalar_values(): void
    {
        $this->assertError(
            ReportErrorCode::REPORT_REQUEST_INVALID,
            fn () => $this->filterNormalizer()->normalize(
                (new ReportExecutionContextBuilder)->build(),
                $this->definition(),
                ['projects' => ['operator' => 'eq', 'value' => ['id' => 1]]],
            ),
        );
    }

    #[Test]
    public function rows_window_accepts_only_definition_backed_sort(): void
    {
        $window = (new ReportRowsWindowNormalizer)->normalize(
            $this->definition(),
            ['cursor' => 'cursor', 'limit' => 100, 'sort_by' => 'created_at', 'sort_dir' => 'desc'],
        );

        self::assertSame('cursor', $window->cursor);
        self::assertSame(100, $window->limit);
        self::assertSame('created_at', $window->sort->field);
        self::assertSame(ReportSortDirection::DESC, $window->sort->direction);
    }

    #[Test]
    public function rows_window_rejects_sealed_filters_and_unknown_keys(): void
    {
        foreach (['filters', 'as_of', 'comparison'] as $sealed) {
            $this->assertError(
                ReportErrorCode::REPORT_REQUEST_INVALID,
                fn () => (new ReportRowsWindowNormalizer)->normalize(
                    $this->definition(),
                    ['cursor' => null, 'limit' => 10, 'sort_by' => 'created_at', 'sort_dir' => 'asc', $sealed => []],
                ),
            );
        }
    }

    #[Test]
    public function rows_window_rejects_invalid_limit_direction_and_unknown_sort(): void
    {
        $cases = [
            [['cursor' => null, 'limit' => 101, 'sort_by' => 'created_at', 'sort_dir' => 'asc'], ReportErrorCode::REPORT_REQUEST_INVALID],
            [['cursor' => null, 'limit' => 10, 'sort_by' => 'created_at', 'sort_dir' => 'sideways'], ReportErrorCode::REPORT_REQUEST_INVALID],
            [['cursor' => null, 'limit' => 10, 'sort_by' => 'raw_sql', 'sort_dir' => 'asc'], ReportErrorCode::REPORT_SORT_UNSUPPORTED],
        ];

        foreach ($cases as [$input, $code]) {
            $this->assertError($code, fn () => (new ReportRowsWindowNormalizer)->normalize($this->definition(), $input));
        }
    }

    #[Test]
    public function drill_down_accepts_exact_payload(): void
    {
        $request = (new ReportDrillDownNormalizer)->normalize(['token' => 'token', 'cursor' => null, 'limit' => 20]);

        self::assertSame('token', $request->token);
        self::assertNull($request->cursor);
        self::assertSame(20, $request->limit);
    }

    #[Test]
    public function drill_down_rejects_unknown_keys_empty_token_and_invalid_limit(): void
    {
        foreach ([
            ['token' => 'token', 'cursor' => null, 'limit' => 10, 'filters' => []],
            ['token' => ' ', 'cursor' => null, 'limit' => 10],
            ['token' => 'token', 'cursor' => null, 'limit' => 0],
        ] as $input) {
            $this->assertError(ReportErrorCode::REPORT_REQUEST_INVALID, fn () => (new ReportDrillDownNormalizer)->normalize($input));
        }
    }

    #[Test]
    public function export_normalizes_definition_backed_capabilities(): void
    {
        $data = (new ReportExportNormalizer)->normalize(
            $this->definition(),
            [
                'format' => 'xlsx',
                'columns' => ['total', 'name'],
                'sort_by' => 'total',
                'sort_dir' => 'desc',
                'locale' => 'ru-RU',
                'timezone' => 'Europe/Moscow',
            ],
        );

        self::assertSame('xlsx', $data->format);
        self::assertSame(['total', 'name'], $data->columns);
        self::assertSame('total', $data->sort->field);
        self::assertSame('Europe/Moscow', $data->timezone->getName());
    }

    #[Test]
    public function export_rejects_sealed_filters_and_unknown_keys(): void
    {
        foreach (['filters', 'as_of', 'comparison'] as $sealed) {
            $this->assertError(
                ReportErrorCode::REPORT_REQUEST_INVALID,
                fn () => (new ReportExportNormalizer)->normalize($this->definition(), array_replace($this->exportInput(), [$sealed => []])),
            );
        }
    }

    #[Test]
    public function export_rejects_unknown_column_and_format_with_narrow_safe_fields(): void
    {
        foreach ([
            [array_replace($this->exportInput(), ['columns' => ['missing']]), 'columns'],
            [array_replace($this->exportInput(), ['format' => 'pdf']), 'format'],
        ] as [$input, $field]) {
            try {
                (new ReportExportNormalizer)->normalize($this->definition(), $input);
                self::fail('Неизвестная возможность экспорта была принята.');
            } catch (ReportContractException $exception) {
                self::assertSame(ReportErrorCode::REPORT_FILTER_UNSUPPORTED, $exception->errorCode);
                self::assertSame(['fields' => $field], $exception->safeFields);
            }
        }
    }

    #[Test]
    public function export_rejects_duplicate_columns_invalid_sort_locale_and_timezone(): void
    {
        $this->assertError(
            ReportErrorCode::REPORT_REQUEST_INVALID,
            fn () => (new ReportExportNormalizer)->normalize(
                $this->definition(),
                array_replace($this->exportInput(), ['columns' => ['total', 'total']]),
            ),
        );
        $this->assertError(
            ReportErrorCode::REPORT_SORT_UNSUPPORTED,
            fn () => (new ReportExportNormalizer)->normalize(
                $this->definition(),
                array_replace($this->exportInput(), ['sort_by' => 'missing']),
            ),
        );

        foreach (['locale' => 'russian', 'timezone' => 'Mars/Base'] as $field => $value) {
            $this->assertError(
                ReportErrorCode::REPORT_REQUEST_INVALID,
                fn () => (new ReportExportNormalizer)->normalize(
                    $this->definition(),
                    array_replace($this->exportInput(), [$field => $value]),
                ),
            );
        }
    }

    #[Test]
    public function run_data_validates_code_locale_saved_view_and_preserves_normalized_input(): void
    {
        $filters = new ReportFilterSet(['count' => ['operator' => 'eq', 'value' => 1]]);
        $asOf = new DateTimeImmutable('2026-07-26T00:00:00+00:00');
        $data = new CreateReportRunData('sales_overview', $filters, ['period' => 'previous'], $asOf, 'ru-RU', '01J00000000000000000000001');

        self::assertSame('sales_overview', $data->reportCode);
        self::assertSame($filters, $data->filters);
        self::assertSame($asOf, $data->asOf);

        foreach ([
            ['bad code', 'ru', null],
            ['sales_overview', 'russian', null],
            ['sales_overview', 'ru', 'not-ulid'],
        ] as [$code, $locale, $savedViewId]) {
            $this->assertInvalidArgument(fn () => new CreateReportRunData($code, $filters, [], $asOf, $locale, $savedViewId));
        }
    }

    #[Test]
    public function export_data_validates_format_columns_locale_and_timezone(): void
    {
        $sort = new ReportWindowSort('total', ReportSortDirection::DESC);
        $data = new CreateReportExportData('csv', ['total'], $sort, 'ru', new DateTimeZone('UTC'));

        self::assertSame('csv', $data->format);
        self::assertSame(['total'], $data->columns);

        foreach ([
            ['json', ['total'], 'ru'],
            ['csv', [], 'ru'],
            ['csv', ['total', 'total'], 'ru'],
            ['csv', ['total'], 'russian'],
        ] as [$format, $columns, $locale]) {
            $this->assertInvalidArgument(fn () => new CreateReportExportData($format, $columns, $sort, $locale, new DateTimeZone('UTC')));
        }

        $this->assertInvalidArgument(
            fn () => new CreateReportExportData('csv', ['total'], $sort, 'ru', new DateTimeZone('+03:00')),
        );
    }

    #[Test]
    public function download_link_data_validates_ulid_and_ttl_boundaries(): void
    {
        $data = new CreateReportDownloadLinkData('01J00000000000000000000001', 300);

        self::assertSame(300, $data->ttlSeconds);

        foreach ([['invalid', 10], ['01J00000000000000000000001', 0], ['01J00000000000000000000001', 301]] as [$id, $ttl]) {
            $this->assertInvalidArgument(fn () => new CreateReportDownloadLinkData($id, $ttl));
        }
    }

    private function filterNormalizer(): ReportFilterNormalizer
    {
        return new ReportFilterNormalizer(
            new class implements ReportFilterReferenceResolver
            {
                public function resolve(ReportScope $scope, string $filter, int|string $value): int|string
                {
                    return $value;
                }
            },
        );
    }

    private function definition(?array $filters = null): ReportDefinition
    {
        return new ReportDefinition(
            'sales_overview',
            new Sha256Hash(str_repeat('a', 64)),
            'contract-v1',
            'formula-v1',
            'source-v1',
            'renderer-v1',
            $filters ?? [
                ['id' => 'name', 'type' => 'string', 'operators' => ['contains', 'eq']],
                ['id' => 'count', 'type' => 'integer', 'operators' => ['eq', 'gte', 'in']],
                ['id' => 'amount', 'type' => 'decimal', 'operators' => ['between', 'eq']],
                ['id' => 'active', 'type' => 'boolean', 'operators' => ['eq']],
                ['id' => 'day', 'type' => 'date', 'operators' => ['eq']],
                ['id' => 'moment', 'type' => 'datetime', 'operators' => ['lt']],
                ['id' => 'tags', 'type' => 'string', 'operators' => ['in'], 'multiple' => true],
                ['id' => 'projects', 'type' => 'reference', 'operators' => ['eq', 'in'], 'multiple' => true],
            ],
            [['id' => 'total'], ['id' => 'name']],
            [['id' => 'created_at'], ['id' => 'total']],
            ['csv', 'xlsx'],
            new ReportPermissionPolicy(['reports.view'], ['reports.export'], [], []),
            \App\BusinessModules\Core\Reporting\Domain\Enums\ReportSnapshotClassification::OPERATIONAL,
            new \App\BusinessModules\Core\Reporting\Domain\DTO\ReportOutputClassification(\App\BusinessModules\Core\Reporting\Domain\Enums\ReportDataClassification::STANDARD, [], [], false, false, false),
            ReportPublicationReadiness::PUBLISHED,
            true,
            'reports',
            \App\BusinessModules\Core\Reporting\Domain\Enums\ReportCoreAccessMode::REPORTING_WORKSPACE,
        );
    }

    private function exportInput(): array
    {
        return [
            'format' => 'csv',
            'columns' => ['total'],
            'sort_by' => 'total',
            'sort_dir' => 'asc',
            'locale' => 'ru',
            'timezone' => 'UTC',
        ];
    }

    private function assertError(ReportErrorCode $code, callable $operation): void
    {
        try {
            $operation();
            self::fail('Ожидаемая ошибка контракта не возникла.');
        } catch (ReportContractException $exception) {
            self::assertSame($code, $exception->errorCode);
        }
    }

    private function assertInvalidArgument(callable $operation): void
    {
        try {
            $operation();
            self::fail('Некорректные данные были приняты.');
        } catch (InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
    }
}
