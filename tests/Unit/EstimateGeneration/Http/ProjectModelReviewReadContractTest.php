<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Http;

use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\ShowEstimateGenerationProjectModelReviewRequest;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProjectModelReviewReadContractTest extends TestCase
{
    #[Test]
    public function review_request_accepts_only_the_declared_bounded_filters(): void
    {
        $rules = (new ShowEstimateGenerationProjectModelReviewRequest)->rules();

        foreach (['document_id', 'sheet_id', 'entity_kind', 'status', 'needs_action', 'query', 'cursor', 'per_page', 'state_version'] as $filter) {
            self::assertArrayHasKey($filter, $rules);
        }
        self::assertContains('max:100', $rules['per_page']);
    }
}
