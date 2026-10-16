<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Waves23;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Input\ReportFilterNormalizer;
use App\BusinessModules\Core\Reporting\Application\Input\ReportFilterReferenceResolver;
use App\BusinessModules\Core\Reporting\Domain\DTO\AuthorizationDecisionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportFilterSet;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\Services\CompletedWork\Reporting\AcceptedProduction\AcceptedProductionBuiltinPublishedReport;
use App\Services\CompletedWork\Reporting\AcceptedProduction\AcceptedProductionCandidateContract;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Services\AcceptedProductionFilterInputNormalizer;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Services\AcceptedProductionFilterValidator;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\Reporting\ReportDefinitionBuilder;
use Tests\Support\Reporting\ReportExecutionContextBuilder;

final class AcceptedProductionFilterInputNormalizerTest extends TestCase
{
    public function test_unrelated_reports_keep_generic_operator_normalization_with_the_adapter_registered(): void
    {
        $context = (new ReportExecutionContextBuilder)->build();
        $definition = (new ReportDefinitionBuilder)->filters([['id' => 'count', 'type' => 'integer', 'operators' => ['eq']]])->payload();
        $normalizer = new ReportFilterNormalizer($this->createStub(ReportFilterReferenceResolver::class),
            new AcceptedProductionFilterInputNormalizer(new AcceptedProductionCandidateContract));

        self::assertSame(['count' => ['operator' => 'eq', 'value' => 5]],
            $normalizer->normalize($context, $definition, ['count' => ['operator' => 'eq', 'value' => '5']])->values);
        $filters = new ReportFilterSet([]);
        self::assertSame($filters, (new AcceptedProductionFilterInputNormalizer(new AcceptedProductionCandidateContract))
            ->forExecution($context, $definition, $filters));
    }

    #[DataProvider('executionCases')]
    public function test_execution_adds_only_current_single_project_context_and_rejects_substitution(array $projects, array $supplied, bool $valid): void
    {
        $contract = new AcceptedProductionCandidateContract;
        $definition = (new AcceptedProductionBuiltinPublishedReport($contract))->definition()->payload();
        $timezone = new DateTimeZone('UTC');
        $context = (new ReportExecutionContextBuilder)->scope(new ReportScope(1, [1], $projects, [], $timezone))
            ->authorization(new AuthorizationDecisionContext('queue', 1, [1], $projects, [], $timezone, 'execution-input-test', null))->build();
        $filters = new ReportFilterSet(['period_from' => '2026-10-01', 'period_to' => '2026-10-07', ...$supplied]);
        if (! $valid) {
            $this->expectException(ReportContractException::class);
        }
        $actual = (new AcceptedProductionFilterInputNormalizer($contract))->forExecution($context, $definition, $filters);
        self::assertSame(1, (int) $actual->values['organization_id']);
        self::assertSame(52, (int) $actual->values['project_id']);
        self::assertSame(52, (new AcceptedProductionFilterValidator)->validate(new ReportQuery($definition,
            $context->scope, $actual, [], new DateTimeImmutable('2026-10-08T00:00:00Z'), 'ru')));
        if ($supplied !== []) {
            self::assertSame($filters->values, $actual->values);
        }
    }

    public static function executionCases(): array
    {
        return [[[52], [], true], [[], [], false], [[52, 53], [], false],
            [[52], ['organization_id' => 2], false], [[52], ['project_id' => 53], false],
            [[52], ['organization_id' => true], false],
            [[52], ['organization_id' => '1', 'project_id' => '52'], true]];
    }

    #[DataProvider('filterCases')]
    public function test_saved_filters_keep_the_published_run_shape(array $changes, bool $valid): void
    {
        $contract = new AcceptedProductionCandidateContract;
        $definition = (new AcceptedProductionBuiltinPublishedReport($contract))->definition()->payload();
        $timezone = new DateTimeZone('Europe/Moscow');
        $context = (new ReportExecutionContextBuilder)->scope(new ReportScope(1, [1], [52], [], $timezone))
            ->authorization(new AuthorizationDecisionContext('http', 1, [1], [52], [], $timezone, 'saved-view-test', null))->build();
        $input = array_replace(['period_from' => '2026-10-01', 'period_to' => '2026-10-07',
            'work_ids' => [5], 'unit_codes' => ['m2'], 'statuses' => ['accepted']], $changes);
        $normalizer = new ReportFilterNormalizer($this->createStub(ReportFilterReferenceResolver::class),
            new AcceptedProductionFilterInputNormalizer($contract));
        if (! $valid) {
            $this->expectException(ReportContractException::class);
        }
        $filters = $normalizer->normalize($context, $definition, $input);
        self::assertSame((new ReportFilterSet($input))->values, $filters->values);
        self::assertSame(52, (new AcceptedProductionFilterValidator)->validate(new ReportQuery($definition,
            $context->scope, new ReportFilterSet([...$filters->values, 'organization_id' => 1, 'project_id' => 52]),
            [], new DateTimeImmutable('2026-10-08T00:00:00Z'), 'ru')));
    }

    public static function filterCases(): array
    {
        return [[[], true], [['period_from' => '2026-02-30'], false],
            [['period_from' => ['operator' => 'eq', 'value' => '2026-10-01']], false],
            [['period_from' => '2026-11-01'], false], [['project_id' => 52], false],
            [['organization_id' => 1], false], [['work_ids' => ['5']], false],
            [['unit_codes' => [5]], false], [['statuses' => ['draft']], false]];
    }
}
