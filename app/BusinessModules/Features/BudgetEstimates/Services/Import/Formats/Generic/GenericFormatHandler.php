<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Formats\Generic;

use App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateTypeDetectionDTO;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Formats\AbstractFormatHandler;
use App\Models\ImportSession;
use Illuminate\Support\Collection;

class GenericFormatHandler extends AbstractFormatHandler
{
    public function getSlug(): string
    {
        return 'generic';
    }

    public function canHandle(mixed $content, string $extension): EstimateTypeDetectionDTO
    {
        // Generic handler is a fallback with low confidence
        $dto = $this->createDetectionDTO(true);
        $dto->confidence = 0.1;
        return $dto;
    }

    public function parse(ImportSession $session, mixed $content): Collection
    {
        // For GenericHandler, we rely on the parser factory and row mapper
        // which are injected or resolved.
        $parserFactory = app(\App\BusinessModules\Features\BudgetEstimates\Services\Import\Parsers\Factory\ParserFactory::class);
        $rowMapper = app(\App\BusinessModules\Features\BudgetEstimates\Services\Import\ImportRowMapper::class);
        $fileStorage = app(\App\BusinessModules\Features\BudgetEstimates\Services\Import\FileStorageService::class);

        $fullPath = $fileStorage->getAbsolutePath($session);
        $parser = $parserFactory->getParser($fullPath);
        
        $structure = $session->options['structure'] ?? [];
        $columnMapping = $session->options['column_mapping'] ?? ($structure['column_mapping'] ?? []);
        
        $parseOptions = [
            'column_mapping' => $columnMapping, 
            'header_row' => $structure['header_row'] ?? null
        ];

        $items = [];
        $sections = [];

        foreach ($parser->getStream($fullPath, $parseOptions) as $rowDTO) {
            if ($rowMapper->isTechnicalRow($rowDTO->rawData)) continue;

            $mappedDTO = $rowMapper->map($rowDTO, $columnMapping);
            
            if ($mappedDTO->isFooter) continue;
             
            if (!$mappedDTO->isSection && 
                ($mappedDTO->quantity === null || $mappedDTO->quantity <= 0) && 
                ($mappedDTO->unitPrice === null || $mappedDTO->unitPrice <= 0) &&
                ($mappedDTO->currentTotalAmount === null || $mappedDTO->currentTotalAmount <= 0)
            ) {
                continue;
            }

            if ($mappedDTO->isSection) {
                 $sections[] = $mappedDTO->toArray();
            } else {
                 $items[] = $mappedDTO->toArray();
            }
        }

        return collect([
            'items' => $items,
            'sections' => $sections
        ]);
    }

    public function applyMapping(ImportSession $session, array $mapping): void
    {
        // Update session options with mapping
        $options = $session->options ?? [];
        $options['column_mapping'] = $mapping;
        $session->update(['options' => $options]);
    }
}
