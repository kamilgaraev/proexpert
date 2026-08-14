<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Observability;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitProcessingException;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Exceptions\VisionContractException;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Exceptions\VisionProviderException;
use App\BusinessModules\Addons\EstimateGeneration\Vision\PhysicalAttempt\VisionPhysicalAttemptCollision;
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
        VisionPhysicalAttemptCollision::class => 'vision_physical_attempt_collision',
    ];

    /** @return array<string, string> */
    public function forThrowable(Throwable $error, string $executionBoundary): array
    {
        $classes = [];
        $databaseDiagnostics = [];
        do {
            $classes[] = self::CLASS_SLUGS[$error::class] ?? 'unknown_exception';
            $databaseDiagnostics = [...$databaseDiagnostics, ...$this->databaseDiagnostics($error)];
            $typedInvariant = $this->typedInvariantCode($error);
            if ($typedInvariant !== null) {
                $databaseDiagnostics['invariant_code'] = $typedInvariant;
            }
            $error = $error->getPrevious();
        } while ($error instanceof Throwable);

        $chainFingerprint = 'sha256:'.hash('sha256', implode("\0", $classes));

        $diagnosticSeed = json_encode([
            'boundary' => $executionBoundary,
            'chain' => $chainFingerprint,
            'invariant_code' => $databaseDiagnostics['invariant_code'] ?? null,
            'sql_state' => $databaseDiagnostics['sql_state'] ?? null,
            'database_invariant' => $databaseDiagnostics['database_invariant'] ?? null,
            'constraint_identifier' => $databaseDiagnostics['constraint_identifier'] ?? null,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return [
            'exception_class' => $classes[0],
            'root_exception_class' => $classes[array_key_last($classes)],
            'exception_chain_fingerprint' => $chainFingerprint,
            'execution_boundary' => $executionBoundary,
            'diagnostic_fingerprint' => 'sha256:'.hash('sha256', $diagnosticSeed),
            ...$databaseDiagnostics,
        ];
    }

    /** @return array<string, string> */
    private function databaseDiagnostics(Throwable $error): array
    {
        if (! $error instanceof PDOException) {
            return [];
        }
        $sqlState = is_array($error->errorInfo ?? null) && is_string($error->errorInfo[0] ?? null)
            ? strtoupper($error->errorInfo[0])
            : null;
        $message = $error->getMessage();
        preg_match('/\b(estimate_generation\.[a-z0-9_.]{1,120})\b/', $message, $invariant);
        preg_match('/\bconstraint\s+"([a-z][a-z0-9_]{0,62})"/i', $message, $constraint);

        return [
            ...(is_string($sqlState) && preg_match('/\A[0-9A-Z]{5}\z/', $sqlState) === 1
                ? ['sql_state' => $sqlState]
                : []),
            ...(isset($invariant[1]) ? ['database_invariant' => strtolower($invariant[1])] : []),
            ...(isset($constraint[1]) ? ['constraint_identifier' => strtolower($constraint[1])] : []),
        ];
    }

    private function typedInvariantCode(Throwable $error): ?string
    {
        $code = match (true) {
            $error instanceof VisionContractException => $error->reason,
            $error instanceof VisionProviderException => $error->reason,
            $error instanceof DocumentUnitProcessingException => $error->safeCode,
            $error instanceof TypedFailureException => $error->safeCode,
            $error instanceof InvalidArgumentException
                && preg_match('/\A(?:arbitration|observer|observation|project_model|vision|geometry|document_unit|estimate_generation)_[a-z0-9_]{1,64}\z/', $error->getMessage()) === 1 => $error->getMessage(),
            default => null,
        };

        return is_string($code) && preg_match('/\A[a-z][a-z0-9_]{0,79}\z/', $code) === 1
            ? $code
            : null;
    }
}
