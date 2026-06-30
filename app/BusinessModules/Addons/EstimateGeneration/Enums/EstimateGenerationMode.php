<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Enums;

enum EstimateGenerationMode: string
{
    case StrictDocuments = 'strict_documents';
    case AiAssisted = 'ai_assisted';

    public static function fromInput(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom($value) ?? self::StrictDocuments) : self::StrictDocuments;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $mode): string => $mode->value, self::cases());
    }
}
