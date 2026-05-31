<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Runtime;

use InvalidArgumentException;

final class ImportFormatRegistry
{
    /**
     * @var array<string, RuntimeImportFormatHandlerInterface>
     */
    private array $handlers = [];

    /**
     * @param array<int, RuntimeImportFormatHandlerInterface> $handlers
     */
    public function __construct(array $handlers)
    {
        foreach ($handlers as $handler) {
            $this->handlers[$handler->slug()] = $handler;
        }
    }

    /**
     * @return array<string, RuntimeImportFormatHandlerInterface>
     */
    public function all(): array
    {
        return $this->handlers;
    }

    public function bySlug(string $slug): RuntimeImportFormatHandlerInterface
    {
        return $this->handlers[$slug] ?? throw new InvalidArgumentException("Import format handler [{$slug}] is not registered.");
    }

    /**
     * @return array<int, RuntimeImportFormatHandlerInterface>
     */
    public function forExtension(string $extension): array
    {
        $extension = strtolower(ltrim($extension, '.'));

        return array_values(array_filter(
            $this->handlers,
            static fn (RuntimeImportFormatHandlerInterface $handler): bool => in_array($extension, $handler->supportedExtensions(), true)
        ));
    }
}
