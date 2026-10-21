<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Apply\ConfirmedEvaluationDraftProjector;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\EvaluationVatProjection;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ConfirmedEvaluationDraftProjectorTest extends TestCase
{
    public function test_normative_hours_sum_scaled_resources_without_changing_resources_or_money(): void
    {
        $labor = [['quantity' => '0.1'], ['quantity' => '0.2']];
        $machinery = [['quantity' => '0.04'], ['quantity' => '0.06']];
        $item = $this->project(['labor' => $labor, 'machinery' => $machinery]);
        self::assertSame('0.3', $item['labor_hours']);
        self::assertSame('0.1', $item['machinery_hours']);
        self::assertSame($labor, $item['labor']);
        self::assertSame($machinery, $item['machinery']);
        self::assertSame('3', $item['quantity']);
        self::assertSame('30.00', $item['total_cost']);
        self::assertSame('36.60', $item['accepted_source_money']['gross_amount']);
    }

    public function test_empty_resource_groups_have_zero_hours(): void
    {
        $item = $this->project(['labor' => [], 'machinery' => []]);
        self::assertSame('0', $item['labor_hours']);
        self::assertSame('0', $item['machinery_hours']);
    }

    public function test_absent_resource_groups_have_zero_hours(): void
    {
        $item = $this->project([]);
        self::assertSame('0', $item['labor_hours']);
        self::assertSame('0', $item['machinery_hours']);
    }

    #[DataProvider('invalidResourceQuantities')]
    #[DataProvider('invalidResourceGroups')]
    public function test_invalid_resource_quantity_uses_the_existing_draft_rejection(mixed $resources, string $group): void
    {
        $previous = Facade::getFacadeApplication();
        $container = new Container;
        $container->instance('validator', new Factory(new Translator(new ArrayLoader, 'en')));
        Facade::setFacadeApplication($container);
        try {
            $this->project([$group => $resources]);
            self::fail('An invalid accepted resource quantity was projected.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('draft', $exception->errors());
        } finally {
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication($previous);
        }
    }

    public static function invalidResourceQuantities(): iterable
    {
        foreach (['labor', 'machinery'] as $group) {
            foreach ([[], ['quantity' => null], ['quantity' => 'invalid'], ['quantity' => '-0.1'], ['quantity' => '0'], ['quantity' => []]] as $index => $resource) {
                yield $group.' '.$index => [[$resource], $group];
            }
        }
    }

    public static function invalidResourceGroups(): iterable
    {
        foreach (['labor', 'machinery'] as $group) {
            yield $group.' null group' => [null, $group];
            yield $group.' non-array group' => ['invalid', $group];
        }
    }

    private function project(array $resourceGroups): array
    {
        $price = ['source_type' => 'normative', 'verified' => true, 'conditions_missing' => [], 'currency' => 'RUB',
            'source_reference' => 'synthetic:resource-aggregate', 'source_hash' => 'synthetic-hash',
            'calculation_basis' => ['source_hash' => 'synthetic-hash'], 'vat_mode' => 'excluded', 'vat_rate' => '22'];
        $money = (new EvaluationVatProjection)->amount('30.00', $price);
        $item = ['key' => 'equipment-install', 'item_type' => 'priced_work', 'quantity' => '3', 'total_cost' => '30.00',
            ...$resourceGroups,
            'price_snapshot' => ['source_type' => 'regional_resource_aggregate', 'source_reference' => $price['source_reference'], 'final_amount' => '30.00']];
        $fresh = ['local_estimates' => [['sections' => [['work_items' => [$item]]]]]];
        $selected = ['scenario' => ['positions' => [['key' => $item['key'], 'quantity' => '3', 'price_snapshot' => $price, 'money_breakdown' => $money]]],
            'total_by_currency' => ['RUB' => '36.60']];
        $projector = (new ReflectionClass(ConfirmedEvaluationDraftProjector::class))->newInstanceWithoutConstructor();
        $result = $projector->projectVerifiedSelection($fresh, $selected, ['scenario_id' => 'base']);

        return $result['local_estimates'][0]['sections'][0]['work_items'][0];
    }
}
