<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Observability;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitPageReservationPolicy;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitProcessingException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DocumentUnitPageReservationPolicyTest extends TestCase
{
    #[Test]
    #[DataProvider('protectedPages')]
    public function populated_or_lineage_page_requires_source_replacement_before_paid_ocr(array $changes): void
    {
        $state = [...$this->emptyPage(), ...$changes];

        $this->expectException(DocumentUnitProcessingException::class);
        $this->expectExceptionMessage('unit_page_lineage_conflict');
        (new DocumentUnitPageReservationPolicy)->assertReservable(...$state);
    }

    public static function protectedPages(): array
    {
        return [
            'text' => [['text' => 'whole document OCR']],
            'hash' => [['textHash' => str_repeat('a', 64)]],
            'normalized' => [['normalizedPayload' => ['blocks' => []]]],
            'evidence' => [['hasLineage' => true]],
        ];
    }

    private function emptyPage(): array
    {
        return ['processingUnitId' => null, 'sourceVersion' => null, 'text' => null, 'textHash' => null,
            'outputVersion' => null, 'normalizedPayload' => [], 'hasLineage' => false, 'unitId' => 5,
            'unitSourceVersion' => 'source-v1'];
    }
}
