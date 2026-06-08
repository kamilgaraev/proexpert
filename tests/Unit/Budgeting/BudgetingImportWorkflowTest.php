<?php

declare(strict_types=1);

namespace Tests\Unit\Budgeting;

use App\BusinessModules\Features\Budgeting\DTOs\BudgetImportValidationContext;
use App\BusinessModules\Features\Budgeting\Services\BudgetImportFileReader;
use App\BusinessModules\Features\Budgeting\Services\BudgetImportValidator;
use App\BusinessModules\Features\Budgeting\Services\BudgetWorkflowService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\FileLoader;
use Illuminate\Translation\Translator;
use PHPUnit\Framework\TestCase;

final class BudgetingImportWorkflowTest extends TestCase
{
    private ?string $tempFile = null;

    protected function setUp(): void
    {
        parent::setUp();

        $container = new Container();
        $loader = new FileLoader(new Filesystem(), dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'lang');
        $translator = new Translator($loader, 'ru');

        $container->instance('translator', $translator);
        $container->instance('config', new Repository([
            'app' => [
                'locale' => 'ru',
                'fallback_locale' => 'ru',
            ],
        ]));
        $container->instance('app', new class {
            public function getLocale(): string
            {
                return 'ru';
            }
        });

        Facade::setFacadeApplication($container);
    }

    protected function tearDown(): void
    {
        if ($this->tempFile !== null && is_file($this->tempFile)) {
            unlink($this->tempFile);
        }

        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);

        parent::tearDown();
    }

    public function test_csv_reader_normalizes_russian_headers(): void
    {
        $this->tempFile = tempnam(sys_get_temp_dir(), 'budget-import-');
        self::assertIsString($this->tempFile);

        file_put_contents(
            $this->tempFile,
            "статья;цфо;месяц;план;прогноз;валюта;сценарий;тип бюджета\nBDR-001;CFO-01;2026-01;1200,50;;RUB;base;bdr\n"
        );

        $result = (new BudgetImportFileReader())->readPath($this->tempFile, 'csv');

        $this->assertSame('csv', $result['format']);
        $this->assertCount(1, $result['rows']);
        $this->assertSame('BDR-001', $result['rows'][0]['article_code']);
        $this->assertSame('CFO-01', $result['rows'][0]['cfo_code']);
        $this->assertSame('2026-01', $result['rows'][0]['month']);
        $this->assertSame('1200,50', $result['rows'][0]['plan_amount']);
        $this->assertSame('bdr', $result['rows'][0]['budget_kind']);
    }

    public function test_import_validator_builds_preview_and_rejects_duplicates(): void
    {
        $validator = new BudgetImportValidator();
        $context = $this->validationContext();

        $result = $validator->validate([
            [
                'row_number' => 2,
                'article_code' => 'BDR-001',
                'cfo_code' => 'CFO-01',
                'month' => '2026-01',
                'plan_amount' => '1000',
                'currency' => 'RUB',
                'scenario_code' => 'base',
                'budget_kind' => 'bdr',
            ],
            [
                'row_number' => 3,
                'article_code' => 'BDR-001',
                'cfo_code' => 'CFO-01',
                'month' => '2026-01',
                'plan_amount' => '2000',
                'currency' => 'RUB',
                'scenario_code' => 'base',
                'budget_kind' => 'bdr',
            ],
        ], $context);

        $this->assertSame(2, $result->summary['rows_total']);
        $this->assertSame(2, $result->summary['rows_invalid']);
        $this->assertSame(0.0, $result->summary['plan_total']);
        $this->assertSame('invalid', $result->rows[0]['validation_status']);
        $this->assertSame('invalid', $result->rows[1]['validation_status']);
        $this->assertContains('В файле есть повторяющиеся строки бюджета.', $result->rows[1]['validation_errors']);
    }

    public function test_import_validator_accepts_valid_row_with_forecast_warning(): void
    {
        $result = (new BudgetImportValidator())->validate([
            [
                'row_number' => 2,
                'article_code' => 'BDR-001',
                'cfo_code' => 'CFO-01',
                'month' => '01.2026',
                'plan_amount' => '1000,25',
                'currency' => 'RUB',
                'scenario_code' => 'base',
                'budget_kind' => 'bdr',
            ],
        ], $this->validationContext());

        $this->assertSame(1, $result->summary['rows_total']);
        $this->assertSame(1, $result->summary['rows_with_warnings']);
        $this->assertSame(1000.25, $result->summary['plan_total']);
        $this->assertSame('warning', $result->rows[0]['validation_status']);
        $this->assertSame('2026-01-01', $result->rows[0]['normalized_payload']['month']);
        $this->assertSame(1000.25, $result->rows[0]['normalized_payload']['plan']);
        $this->assertSame(1000.25, $result->rows[0]['normalized_payload']['forecast']);
        $this->assertContains('Прогнозная сумма не заполнена, будет использована плановая сумма.', $result->rows[0]['validation_warnings']);
    }

    public function test_import_validator_rejects_unavailable_dimensions(): void
    {
        $result = (new BudgetImportValidator())->validate([
            [
                'row_number' => 2,
                'article_code' => 'BDR-001',
                'cfo_code' => 'CFO-01',
                'month' => '2026-01',
                'plan_amount' => '1000',
                'forecast_amount' => '1000',
                'currency' => 'RUB',
                'project_id' => '999',
                'contract_id' => 'abc',
                'counterparty_id' => '44',
                'scenario_code' => 'base',
                'budget_kind' => 'bdr',
            ],
        ], $this->validationContext(
            projectIds: [1001 => true],
            contractIds: [501 => true],
            counterpartyIds: [55 => true],
        ));

        $this->assertSame(1, $result->summary['rows_invalid']);
        $this->assertSame('invalid', $result->rows[0]['validation_status']);
        $this->assertContains('Проект для строки бюджета не найден.', $result->rows[0]['validation_errors']);
        $this->assertContains('Договор для строки бюджета не найден.', $result->rows[0]['validation_errors']);
        $this->assertContains('Контрагент для строки бюджета не найден.', $result->rows[0]['validation_errors']);
    }

    public function test_workflow_transitions_are_validated(): void
    {
        $workflow = new BudgetWorkflowService();

        $this->assertSame(BudgetWorkflowService::STATUS_ON_APPROVAL, $workflow->transition('draft', 'submit', true));
        $this->assertSame(BudgetWorkflowService::STATUS_APPROVED, $workflow->transition('on_approval', 'approve'));
        $this->assertSame(BudgetWorkflowService::STATUS_ACTIVE, $workflow->transition('approved', 'activate'));

        $this->expectException(DomainException::class);
        $workflow->transition('draft', 'activate');
    }

    /**
     * @param array<int, true> $projectIds
     * @param array<int, true> $contractIds
     * @param array<int, true> $counterpartyIds
     */
    private function validationContext(array $projectIds = [], array $contractIds = [], array $counterpartyIds = []): BudgetImportValidationContext
    {
        return new BudgetImportValidationContext(
            organizationId: 10,
            budgetKind: 'bdr',
            versionUuid: 'version-uuid',
            versionStatus: BudgetWorkflowService::STATUS_DRAFT,
            periodStatus: 'open',
            periodStart: CarbonImmutable::parse('2026-01-01'),
            periodEnd: CarbonImmutable::parse('2026-12-31'),
            scenarioCode: 'base',
            currency: 'RUB',
            mappingMode: 'by_code',
            articlesByCode: [
                'bdr-001' => [
                    'id' => 101,
                    'uuid' => 'article-uuid',
                    'code' => 'BDR-001',
                    'name' => 'Выручка',
                    'budget_kind' => 'bdr',
                    'is_leaf' => true,
                    'is_active' => true,
                ],
            ],
            articlesByName: [
                'выручка' => [
                    'id' => 101,
                    'uuid' => 'article-uuid',
                    'code' => 'BDR-001',
                    'name' => 'Выручка',
                    'budget_kind' => 'bdr',
                    'is_leaf' => true,
                    'is_active' => true,
                ],
            ],
            centersByCode: [
                'cfo-01' => [
                    'id' => 201,
                    'uuid' => 'center-uuid',
                    'code' => 'CFO-01',
                    'name' => 'Проектный офис',
                    'is_active' => true,
                    'active_from' => null,
                    'active_to' => null,
                ],
            ],
            centersByName: [
                'проектный офис' => [
                    'id' => 201,
                    'uuid' => 'center-uuid',
                    'code' => 'CFO-01',
                    'name' => 'Проектный офис',
                    'is_active' => true,
                    'active_from' => null,
                    'active_to' => null,
                ],
            ],
            projectIds: $projectIds,
            contractIds: $contractIds,
            counterpartyIds: $counterpartyIds,
        );
    }
}
