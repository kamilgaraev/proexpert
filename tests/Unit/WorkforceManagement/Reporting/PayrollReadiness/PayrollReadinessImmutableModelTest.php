<?php

declare(strict_types=1);

namespace Tests\Unit\WorkforceManagement\Reporting\PayrollReadiness;

use App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadiness\Models\PayrollReadinessSnapshotItemRecord;
use App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadiness\Models\PayrollReadinessSnapshotRecord;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PayrollReadinessImmutableModelTest extends TestCase
{
    #[DataProvider('modelProvider')]
    public function test_existing_source_models_reject_save_update_and_delete(string $modelClass): void
    {
        $model = new $modelClass;
        $model->exists = true;

        foreach (['save', 'update', 'delete'] as $operation) {
            try {
                $model->{$operation}();
                self::fail("{$operation} must reject append-only mutation");
            } catch (LogicException $exception) {
                self::assertSame('payroll_readiness_source_is_append_only', $exception->getMessage());
            }
        }
    }

    public static function modelProvider(): array
    {
        return [
            'snapshot' => [PayrollReadinessSnapshotRecord::class],
            'snapshot item' => [PayrollReadinessSnapshotItemRecord::class],
        ];
    }
}
