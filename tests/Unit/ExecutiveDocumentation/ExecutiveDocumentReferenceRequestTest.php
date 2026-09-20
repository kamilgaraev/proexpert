<?php

declare(strict_types=1);

namespace Tests\Unit\ExecutiveDocumentation;

use App\BusinessModules\Features\ExecutiveDocumentation\Http\Requests\ExecutiveDocumentReferenceRequest;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

final class ExecutiveDocumentReferenceRequestTest extends TestCase
{
    public function refreshDatabase(): void
    {
    }

    public function test_it_rejects_a_page_size_above_the_reference_limit(): void
    {
        $validator = Validator::make([
            'project_id' => 10,
            'reference_type' => 'completed_works',
            'per_page' => 101,
        ], (new ExecutiveDocumentReferenceRequest())->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('per_page', $validator->errors()->toArray());
    }

    public function test_it_accepts_both_reference_collections_and_date_filters(): void
    {
        foreach (['completed_works', 'journal_entries'] as $referenceType) {
            $validator = Validator::make([
                'project_id' => 10,
                'reference_type' => $referenceType,
                'search' => '2026-09-20',
                'date_from' => '2026-09-01',
                'date_to' => '2026-09-30',
                'page' => 2,
                'per_page' => 100,
            ], (new ExecutiveDocumentReferenceRequest())->rules());

            $this->assertFalse($validator->fails(), $referenceType);
        }
    }
}
