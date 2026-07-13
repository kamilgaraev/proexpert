<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\ListEstimateGenerationReviewItemsRequest;
use PHPUnit\Framework\TestCase;

final class ListEstimateGenerationReviewItemsRequestTest extends TestCase
{
    public function test_accepts_supported_filters_and_rejects_unknown_values(): void
    {
        $request = new ListEstimateGenerationReviewItemsRequest;

        $rules = $request->rules();

        self::assertSame('in:"blocking","warning","optional"', (string) $rules['severity'][2]);
        self::assertSame(
            'in:"confirm_quantity","select_norm","review_norm","resolve_duplicate","resolve_generic_work","check_price"',
            (string) $rules['required_action'][2]
        );
        self::assertContains('max:255', $rules['search']);
    }
}
