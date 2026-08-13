<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Observability;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitProcessingException;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Exceptions\VisionContractException;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Exceptions\VisionProviderException;
use Illuminate\Database\QueryException;
use InvalidArgumentException;
use LogicException;
use PDOException;
use RuntimeException;
use Throwable;

final readonly class FailureDiagnosticIdentity
{
    private const CLASS_SLUGS = [
        DocumentUnitProcessingException::class => 'document_unit_processing_exception',
        TypedFailureException::class => 'typed_failure_exception',
        VisionProviderException::class => 'vision_provider_exception',
        VisionContractException::class => 'vision_contract_exception',
        QueryException::class => 'query_exception',
        PDOException::class => 'pdo_exception',
        InvalidArgumentException::class => 'invalid_argument_exception',
        LogicException::class => 'logic_exception',
        RuntimeException::class => 'runtime_exception',
    ];

    /** @return array{exception_class: string, root_exception_class: string, exception_chain_fingerprint: string, execution_boundary: string, diagnostic_fingerprint: string} */
    public function forThrowable(Throwable $error, string $executionBoundary): array
    {
        $classes = [];
        do {
            $classes[] = self::CLASS_SLUGS[$error::class] ?? 'unknown_exception';
            $error = $error->getPrevious();
        } while ($error instanceof Throwable);

        $chainFingerprint = 'sha256:'.hash('sha256', implode("\0", $classes));

        return [
            'exception_class' => $classes[0],
            'root_exception_class' => $classes[array_key_last($classes)],
            'exception_chain_fingerprint' => $chainFingerprint,
            'execution_boundary' => $executionBoundary,
            'diagnostic_fingerprint' => 'sha256:'.hash('sha256', $executionBoundary."\0".$chainFingerprint),
        ];
    }
}
