<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Planning;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Planning\CompletenessRuleCatalog;
use App\BusinessModules\Addons\EstimateGeneration\Planning\TechnologySystemCatalog;
use App\BusinessModules\Addons\EstimateGeneration\Planning\TechnologyWorkPackageBuilder;
use PHPUnit\Framework\TestCase;

final class PlanningTranslationContractTest extends TestCase
{
    public function test_every_user_visible_catalog_key_has_a_human_readable_russian_translation(): void
    {
        $translations = require dirname(__DIR__, 4).'/lang/ru/estimate_generation.php';
        $translate = static function (string $key) use ($translations): string {
            $segments = explode('.', preg_replace('/^estimate_generation\./', '', $key));
            $value = $translations;
            foreach ($segments as $segment) {
                $value = is_array($value) ? ($value[$segment] ?? null) : null;
            }

            return is_string($value) ? $value : $key;
        };
        $systems = TechnologySystemCatalog::fromArray(require dirname(__DIR__, 4).'/config/estimate-generation-technology-systems.php');
        foreach ($systems->systems as $system) {
            self::assertNotSame($system->nameKey, $translate($system->nameKey));
            self::assertMatchesRegularExpression('/[А-Яа-яЁё]/u', $translate($system->nameKey));
        }
        $rules = CompletenessRuleCatalog::fromArray(require dirname(__DIR__, 4).'/config/estimate-generation-completeness-rules.php');
        $builder = new TechnologyWorkPackageBuilder($translate);
        foreach ($rules->rules() as $rule) {
            self::assertNotSame($rule->impact, $translate($rule->impact));
            $facts = $rule->id === 'base_preparation' ? [
                'foundation_type' => [new Fact(
                    'fact:foundation', 10, 20, 30, 'sha256:'.str_repeat('a', 64), 'entity:project',
                    'foundation_type', 'slab', null, 1.0, 'document', 'confirmed', ['evidence:1'],
                )],
            ] : [];
            $package = $builder->build($rule, $facts);
            foreach ([...$package->works, ...$package->materials, ...$package->machinery] as $item) {
                self::assertNotSame($item['name_key'], $item['label']);
                self::assertMatchesRegularExpression('/[А-Яа-яЁё]/u', $item['label']);
            }
        }
    }
}
