<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Observability;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitPageReservationPolicy;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitPageReservationState;
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
        $state = new DocumentUnitPageReservationState(...[...$this->emptyPage(), ...$changes]);

        $this->expectException(DocumentUnitProcessingException::class);
        $this->expectExceptionMessage('unit_page_lineage_conflict');
        (new DocumentUnitPageReservationPolicy)->assertReservable($state, 5, 'source-v1');
    }

    public static function protectedPages(): array
    {
        return [
            'text' => [['text' => 'whole document OCR']],
            'hash' => [['textHash' => str_repeat('a', 64)]],
            'normalized' => [['normalizedPayload' => ['blocks' => []]]],
            'width zero' => [['width' => 0]],
            'height zero' => [['height' => 0]],
            'rotation zero' => [['rotation' => 0]],
            'confidence zero' => [['confidence' => 0.0]],
            'language codes' => [['languageCodes' => ['ru']]],
            'raw payload path' => [['rawPayloadPath' => 'org-1/ocr/page.json']],
            'quality flags' => [['qualityFlags' => ['low_confidence']]],
            'evidence' => [['hasLineage' => true]],
        ];
    }

    #[Test]
    public function exact_empty_reservation_for_same_unit_and_source_is_allowed(): void
    {
        $state = new DocumentUnitPageReservationState(...[...$this->emptyPage(), 'processingUnitId' => 5, 'sourceVersion' => 'source-v1']);

        (new DocumentUnitPageReservationPolicy)->assertReservable($state, 5, 'source-v1');

        self::addToAssertionCount(1);
    }

    #[Test]
    public function another_unit_on_same_document_page_gets_typed_conflict(): void
    {
        $state = new DocumentUnitPageReservationState(...[...$this->emptyPage(), 'processingUnitId' => 6, 'sourceVersion' => 'source-v1']);

        $this->expectException(DocumentUnitProcessingException::class);
        $this->expectExceptionMessage('unit_page_reservation_conflict');
        (new DocumentUnitPageReservationPolicy)->assertReservable($state, 5, 'source-v1');
    }

    private function emptyPage(): array
    {
        return ['processingUnitId' => null, 'sourceVersion' => null, 'outputVersion' => null,
            'width' => null, 'height' => null, 'rotation' => null, 'languageCodes' => [], 'text' => null,
            'textHash' => null, 'confidence' => null, 'rawPayloadPath' => null, 'normalizedPayload' => [],
            'qualityFlags' => [], 'hasLineage' => false];
    }
}
