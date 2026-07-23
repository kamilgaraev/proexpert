<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Application\TargetedRebuild;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationTargetedRebuildOperation;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TargetedPackageRebuildOperationModelTest extends TestCase
{
    #[Test]
    public function it_uses_the_stable_operation_uuid_as_the_model_key_and_casts_only_compact_json(): void
    {
        $model = new EstimateGenerationTargetedRebuildOperation;

        self::assertSame('estimate_generation_targeted_rebuild_operations', $model->getTable());
        self::assertSame('operation_id', $model->getKeyName());
        self::assertFalse($model->getIncrementing());
        self::assertSame('string', $model->getKeyType());
        self::assertSame('array', $model->getCasts()['result_delta']);
        self::assertSame('array', $model->getCasts()['safe_arbiter_review']);
    }
}
