<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import;

use App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateTypeDetectionDTO;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Formats\ImportFormatHandlerInterface;
use App\Models\ImportSession;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class ImportFormatOrchestrator
{
    /** @var ImportFormatHandlerInterface[] */
    private array $handlers = [];

    public function __construct(iterable $handlers)
    {
        foreach ($handlers as $handler) {
            $this->registerHandler($handler);
        }
    }

    public function registerHandler(ImportFormatHandlerInterface $handler): void
    {
        $this->handlers[$handler->getSlug()] = $handler;
    }

    /**
     * Finds the best handler for the given content.
     */
    public function detectHandler(mixed $content, string $extension): ?ImportFormatHandlerInterface
    {
        $bestHandler = null;
        $maxConfidence = 0.0;

        foreach ($this->handlers as $handler) {
            $detection = $handler->canHandle($content, $extension);
            if ($detection->confidence > $maxConfidence) {
                $maxConfidence = $detection->confidence;
                $bestHandler = $handler;
            }
        }

        return $maxConfidence > 0 ? $bestHandler : null;
    }

    /**
     * Get handler by its slug.
     */
    public function getHandler(string $slug): ImportFormatHandlerInterface
    {
        if (!isset($this->handlers[$slug])) {
            throw new InvalidArgumentException("Handler with slug [$slug] not found.");
        }

        return $this->handlers[$slug];
    }
}
