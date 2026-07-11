<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\Exceptions;

use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureCategory;
use App\BusinessModules\Addons\EstimateGeneration\Observability\TypedFailureException;
use Throwable;

final class PdfGeometryExtractionException extends TypedFailureException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        string $message,
        public readonly array $context = [],
        ?Throwable $previous = null
    ) {
        parent::__construct(self::category($message), self::code($message), [], $previous);
    }

    private static function category(string $code): FailureCategory
    {
        return match ($code) {
            'pdf_geometry_timeout', 'pdf_geometry_process_failed', 'pdf_geometry_temp_file_failed' => FailureCategory::Recoverable,
            'pdf_geometry_malformed_output', 'pdf_geometry_empty_content' => FailureCategory::UserActionRequired,
            default => FailureCategory::Terminal,
        };
    }

    private static function code(string $code): string
    {
        return preg_match('/\A[a-z][a-z0-9_]{0,79}\z/', $code) === 1 ? $code : 'pdf_geometry_failed';
    }
}
