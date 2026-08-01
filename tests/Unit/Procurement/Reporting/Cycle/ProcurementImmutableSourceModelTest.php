<?php

declare(strict_types=1);

namespace Tests\Unit\Procurement\Reporting\Cycle;

use App\BusinessModules\Features\Procurement\Reporting\Cycle\Models\ProcurementCyclePolicyVersion;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Models\ProcurementProcessEvent;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProcurementImmutableSourceModelTest extends TestCase
{
    #[DataProvider('modelProvider')]
    public function test_existing_source_models_reject_save_update_and_delete(string $modelClass): void
    {
        $model = new $modelClass();
        $model->exists = true;

        foreach (['save', 'update', 'delete'] as $operation) {
            try {
                $model->{$operation}();
                self::fail("{$operation} must reject append-only mutation");
            } catch (LogicException $exception) {
                self::assertSame('procurement_reporting_source_is_append_only', $exception->getMessage());
            }
        }
    }

    public static function modelProvider(): array
    {
        return [
            'process event' => [ProcurementProcessEvent::class],
            'policy version' => [ProcurementCyclePolicyVersion::class],
        ];
    }
}
