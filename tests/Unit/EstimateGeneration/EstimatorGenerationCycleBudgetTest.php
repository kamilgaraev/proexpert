<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorGenerationCycleBudget;
use DomainException;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\FileLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory as ValidationFactory;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EstimatorGenerationCycleBudgetTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $container = new Container;
        $translator = new Translator(new FileLoader(new Filesystem, dirname(__DIR__, 3).DIRECTORY_SEPARATOR.'lang'), 'ru');
        $container->instance('app', new class
        {
            public function getLocale(): string
            {
                return 'ru';
            }
        });
        $container->instance('config', new Repository(['app' => ['fallback_locale' => 'ru']]));
        $container->instance('translator', $translator);
        $container->instance('validator', new ValidationFactory($translator, $container));
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
        Container::setInstance($container);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);
        parent::tearDown();
    }

    public function test_a_partial_ceiling_cannot_approve_the_full_generation_cycle(): void
    {
        $this->expectExceptionMessage('estimator_generation_cycle_budget_insufficient');

        EstimatorGenerationCycleBudget::forecast('generation', $this->plan(1080000));
    }

    public function test_the_exact_envelope_keeps_every_frozen_role_and_retry_without_a_replay_discount(): void
    {
        $plan = $this->plan(51000000);
        $forecast = EstimatorGenerationCycleBudget::forecast('generation', $plan);

        self::assertSame('estimator-generation-cycle-preflight:v1', $forecast['contract_version']);
        self::assertSame(51000000, $forecast['maximum_remaining_cycle_cost_micro_rub']);
        self::assertSame(51000000, $forecast['maximum_total_provider_cost_micro_rub']);
        self::assertSame(0, $forecast['included_document_cost_micro_rub']);
        self::assertSame(51, $forecast['maximum_calls']);
        self::assertSame(27000000, $forecast['role_budgets']['estimate_audit']['maximum_cost_micro_rub']);
        self::assertSame(51, array_sum(array_column($forecast['role_budgets'], 'maximum_calls')));
        self::assertTrue($forecast['full_cycle_fits']);
        self::assertTrue($forecast['corrections_included']);
        self::assertTrue($forecast['retries_included']);
        self::assertFalse($forecast['completion_guaranteed']);
        self::assertSame(0, $forecast['replay_discount_micro_rub']);
        self::assertArrayNotHasKey('qa_expense_budget', $forecast);
        self::assertArrayNotHasKey('remaining_units_minor', $forecast);
        self::assertSame($this->plan(51000000), $plan);
    }

    public function test_carried_document_cost_is_included_once_and_cannot_fund_new_calls(): void
    {
        $plan = $this->plan(52000000);
        $plan['document_costs'] = ['cost_micro_rub' => 1000000];
        $forecast = EstimatorGenerationCycleBudget::forecast('generation', $plan);
        self::assertSame(51000000, $forecast['maximum_remaining_cycle_cost_micro_rub']);
        self::assertSame(52000000, $forecast['maximum_total_provider_cost_micro_rub']);
        self::assertSame(1000000, $forecast['included_document_cost_micro_rub']);
        $plan['maximum_provider_cost_micro_rub']--;
        $this->expectExceptionMessage('estimator_generation_cycle_budget_insufficient');

        EstimatorGenerationCycleBudget::forecast('generation', $plan);
    }

    public function test_a_complete_legacy_plan_uses_its_exact_frozen_role_allowances(): void
    {
        $plan = $this->plan(51000000);
        $plan['contract_version'] = 'estimator-processing-plan:v1';
        $before = $plan;
        $forecast = EstimatorGenerationCycleBudget::forecast('generation', $plan);

        self::assertSame(51000000, $forecast['maximum_remaining_cycle_cost_micro_rub']);
        self::assertSame(51, $forecast['maximum_calls']);
        self::assertSame($before, $plan);
    }

    public function test_an_overflowing_role_envelope_cannot_become_an_admissible_budget(): void
    {
        $plan = $this->plan(PHP_INT_MAX);
        $plan['roles']['estimate_audit']['maximum_cost_micro_rub'] = PHP_INT_MAX;
        $this->expectExceptionMessage('estimator_generation_cycle_budget_insufficient');

        EstimatorGenerationCycleBudget::forecast('generation', $plan);
    }

    #[DataProvider('insufficientMessages')]
    public function test_insufficient_validation_message_reports_exact_provider_budget(string $case, string $required, string $available, ?int $limit, string $errorKey): void
    {
        $plan = $this->plan($case === 'large' ? PHP_INT_MAX : ($case === 'documents' ? 51999999 : 50999999));
        if ($case === 'documents') {
            $plan['document_costs'] = ['cost_micro_rub' => 1000000];
        }
        if ($case === 'large') {
            $plan['roles']['estimate_audit']['maximum_cost_micro_rub'] = PHP_INT_MAX;
        }
        if ($case === 'subkopeck') {
            $plan['maximum_provider_cost_micro_rub'] = 1;
            foreach ($plan['roles'] as &$role) {
                $role['maximum_cost_micro_rub'] = 1;
            }
            unset($role);
        }
        if ($limit !== null) {
            $plan['work_ceiling_policy'] = 'global_work_items:v1';
            $plan['work_item_limit'] = $limit;
            $plan['audit_part_limit'] = max(1, $limit);
        }
        if ($case === 'invalid_limit') {
            $plan['work_ceiling_policy'] = 'global_work_items:v1';
            $plan['work_item_limit'] = '32';
            $plan['audit_part_limit'] = 32;
        }
        if ($case === 'legacy_limit') {
            $plan['contract_version'] = 'estimator-processing-plan:v1';
            $plan['work_item_limit'] = 32;
        }
        $before = $plan;
        try {
            (new EstimatorGenerationCycleBudget)->assertFits('generation', $plan, $errorKey);
            self::fail('An insufficient provider envelope was accepted.');
        } catch (ValidationException $error) {
            $capacity = $limit === null ? '' : ' Текущий предел работ: '.$limit.'.';
            $expected = 'Предельные расходы поставщика ИИ для полного плана: '.$required.' ₽. Лимит текущей операции: '.$available.' ₽.'.$capacity
                .' Это не фактическое списание и не баланс вашего кошелька. Запуск остановлен до резервирования. Задайте явный допустимый предел работ и получите новое предложение стоимости.';
            self::assertSame(422, $error->status);
            self::assertSame([$errorKey => [$expected]], $error->errors());
            self::assertSame($before, $plan);
        }
    }

    public static function insufficientMessages(): array
    {
        return [
            'one micro deficit without a guessed work limit' => ['micro', '51', '50,999999', null, 'quote'],
            'included documents and actual plan limit' => ['documents', '51', '50,999999', 32, 'quote'],
            'required above PHP integer range on begin' => ['large', '166020696663409,964526', '9223372036854,775807', 1, 'quote_id'],
            'subkopeck values stay distinguishable' => ['subkopeck', '0,000051', '0,000001', null, 'quote'],
            'invalid work limit is not displayed' => ['invalid_limit', '51', '50,999999', null, 'quote'],
            'historical limit is not asserted as current' => ['legacy_limit', '51', '50,999999', null, 'quote'],
        ];
    }

    public function test_validation_at_the_exact_boundary_keeps_the_frozen_forecast_and_plan(): void
    {
        $plan = $this->plan(51000000);
        $forecast = EstimatorGenerationCycleBudget::forecast('generation', $plan);
        $plan['generation_cycle_preflight'] = $forecast;
        $before = $plan;
        self::assertSame($forecast, (new EstimatorGenerationCycleBudget)->assertFits('generation', $plan));
        self::assertSame($before, $plan);
    }

    public function test_a_changed_frozen_preflight_keeps_the_generic_message_without_budget_numbers(): void
    {
        $plan = $this->plan(51000000);
        $plan['generation_cycle_preflight'] = [];
        try {
            (new EstimatorGenerationCycleBudget)->assertFits('generation', $plan);
            self::fail('A changed frozen forecast was accepted.');
        } catch (ValidationException $error) {
            self::assertSame(['quote' => ['Не удалось подтвердить полный план формирования сметы. Получите новое предложение стоимости. Резерв не создан.']], $error->errors());
        }
    }

    #[DataProvider('invalidPlans')]
    public function test_malformed_frozen_plans_are_not_certified(string $case): void
    {
        $plan = $this->invalidPlan($case);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('estimator_generation_cycle_preflight_unavailable');

        EstimatorGenerationCycleBudget::forecast('generation', $plan);
    }

    #[DataProvider('invalidPlans')]
    public function test_malformed_validation_keeps_the_generic_error_without_fabricated_numbers(string $case): void
    {
        try {
            (new EstimatorGenerationCycleBudget)->assertFits('generation', $this->invalidPlan($case));
            self::fail('A malformed frozen plan was accepted.');
        } catch (ValidationException $error) {
            self::assertSame(['quote' => ['Не удалось подтвердить полный план формирования сметы. Получите новое предложение стоимости. Резерв не создан.']], $error->errors());
        }
    }

    public static function invalidPlans(): array
    {
        return array_map(static fn (string $case): array => [$case], ['contract', 'missing_role', 'unknown_role',
            'zero_calls', 'string_calls', 'large_calls', 'missing_cost', 'negative_cost', 'calls_mismatch', 'document_cost',
            'document_shape', 'document_exceeds_cap', 'maximum', 'zero_maximum', 'negative_maximum', 'zero_cost', 'document_equals_cap']);
    }

    public function test_other_operation_kinds_and_retained_cycles_keep_their_own_contract(): void
    {
        foreach (['documents', 'dialogue', 'analysis', 'rebuild'] as $kind) {
            self::assertNull(EstimatorGenerationCycleBudget::forecast($kind, []));
        }
        self::assertNull(EstimatorGenerationCycleBudget::forecast('generation', ['retained_pipeline_prefix' => []]));
    }

    private function invalidPlan(string $case): array
    {
        $plan = $this->plan(51000000);
        match ($case) {
            'contract' => $plan['contract_version'] = 'older-plan',
            'missing_role' => $plan['roles']['estimate_composer_correction'] = null,
            'unknown_role' => $plan['roles']['unbounded_tool'] = $plan['roles']['vision'],
            'zero_calls' => $plan['roles']['vision']['max_calls'] = 0,
            'string_calls' => $plan['roles']['vision']['max_calls'] = '15',
            'large_calls' => $plan['roles']['vision']['max_calls'] = 10001,
            'missing_cost' => $plan['roles']['vision']['maximum_cost_micro_rub'] = null,
            'negative_cost' => $plan['roles']['vision']['maximum_cost_micro_rub'] = -1,
            'calls_mismatch' => $plan['maximum_calls'] = 50,
            'document_cost' => $plan['document_costs'] = ['cost_micro_rub' => '1'],
            'document_shape' => $plan['document_costs'] = 'invalid',
            'document_exceeds_cap' => $plan['document_costs'] = ['cost_micro_rub' => 51000001],
            'maximum' => $plan['maximum_provider_cost_micro_rub'] = '51000000',
            'zero_maximum' => $plan['maximum_provider_cost_micro_rub'] = 0,
            'negative_maximum' => $plan['maximum_provider_cost_micro_rub'] = -1,
            'zero_cost' => $plan['roles']['vision']['maximum_cost_micro_rub'] = 0,
            'document_equals_cap' => $plan['document_costs'] = ['cost_micro_rub' => 51000000],
        };

        return $plan;
    }

    private function plan(int $maximum): array
    {
        $calls = ['vision' => 15, 'ocr' => 3, 'project_synthesis' => 3, 'estimate_composition' => 3,
            'estimate_audit' => 18, 'estimate_composer_correction' => 6, 'rerank' => 3];
        $roles = [];
        foreach ($calls as $role => $count) {
            $roles[$role] = ['max_calls' => $count, 'maximum_cost_micro_rub' => $role === 'estimate_audit' ? 1500000 : 727272];
        }
        $roles['estimate_composer_correction']['maximum_cost_micro_rub'] = 727276;

        return ['contract_version' => 'estimator-processing-plan:v2', 'maximum_calls' => 51,
            'maximum_provider_cost_micro_rub' => $maximum, 'roles' => $roles];
    }
}
