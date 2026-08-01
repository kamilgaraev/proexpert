<?php

declare(strict_types=1);

namespace Tests\Unit\QualityControl\Reporting\DefectFlow;

use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Models\QualityDefectFlowEventRecord;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Models\QualityDefectFlowGapRecord;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Models\QualityDefectFlowPolicyRecord;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class QualityDefectFlowImmutableModelTest extends TestCase
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
                self::assertSame('quality_defect_flow_source_is_append_only', $exception->getMessage());
            }
        }
    }

    public static function modelProvider(): array
    {
        return [
            'policy' => [QualityDefectFlowPolicyRecord::class],
            'event' => [QualityDefectFlowEventRecord::class],
            'gap' => [QualityDefectFlowGapRecord::class],
        ];
    }
}
