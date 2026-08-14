<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Observability;

use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureDiagnosticIdentity;
use App\BusinessModules\Addons\EstimateGeneration\Observability\SensitiveDiagnosticSanitizer;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Exceptions\VisionContractException;
use App\BusinessModules\Addons\EstimateGeneration\Vision\PhysicalAttempt\VisionPhysicalAttemptCollision;
use InvalidArgumentException;
use PDOException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FailureDiagnosticIdentityTest extends TestCase
{
    #[Test]
    public function postgres_diagnostics_keep_only_sqlstate_constraint_or_typed_invariant(): void
    {
        $failure = new PDOException(
            'SQLSTATE[P0001]: user material secret-value estimate_generation.project_model_entity_payload_invalid constraint "eg_safe_ck"',
        );
        $failure->errorInfo = ['P0001', 7, 'raw user material'];

        $diagnostics = (new SensitiveDiagnosticSanitizer)->sanitize(
            (new FailureDiagnosticIdentity)->forThrowable($failure, 'document_unit_representation'),
        );

        self::assertSame('P0001', $diagnostics['sql_state']);
        self::assertSame('estimate_generation.project_model_entity_payload_invalid', $diagnostics['database_invariant']);
        self::assertSame('eg_safe_ck', $diagnostics['constraint_identifier']);
        self::assertStringNotContainsString('secret-value', json_encode($diagnostics, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('raw user material', json_encode($diagnostics, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function typed_invariant_codes_partition_breaker_identity_without_hashing_arbitrary_messages(): void
    {
        $identity = new FailureDiagnosticIdentity;
        $missing = $identity->forThrowable(
            new InvalidArgumentException('arbitration_claims_missing'),
            'document_unit_representation',
        );
        $scope = $identity->forThrowable(
            new InvalidArgumentException('arbitration_observer_scope_invalid'),
            'document_unit_representation',
        );
        $privateOne = $identity->forThrowable(new InvalidArgumentException('private one'), 'document_unit_representation');
        $privateTwo = $identity->forThrowable(new InvalidArgumentException('private two'), 'document_unit_representation');

        self::assertNotSame($missing['diagnostic_fingerprint'], $scope['diagnostic_fingerprint']);
        self::assertSame($privateOne['diagnostic_fingerprint'], $privateTwo['diagnostic_fingerprint']);
        self::assertSame('arbitration_claims_missing', $missing['invariant_code']);
        self::assertArrayNotHasKey('invariant_code', $privateOne);
    }

    #[Test]
    public function physical_identity_collision_keeps_a_typed_safe_exception_class(): void
    {
        $diagnostics = (new FailureDiagnosticIdentity)->forThrowable(
            new VisionPhysicalAttemptCollision,
            'vision_physical_claim',
        );

        self::assertSame('vision_physical_attempt_collision', $diagnostics['exception_class']);
        self::assertArrayNotHasKey('invariant_code', $diagnostics);
    }

    #[Test]
    public function distinct_typed_vision_contract_reasons_do_not_share_a_breaker_fingerprint(): void
    {
        $identity = new FailureDiagnosticIdentity;
        $schema = $identity->forThrowable(
            new VisionContractException('invalid_analysis_schema'),
            'document_unit_representation',
        );
        $model = $identity->forThrowable(
            new VisionContractException('vision_model_mismatch'),
            'document_unit_representation',
        );

        self::assertSame('invalid_analysis_schema', $schema['invariant_code']);
        self::assertSame('vision_model_mismatch', $model['invariant_code']);
        self::assertNotSame($schema['diagnostic_fingerprint'], $model['diagnostic_fingerprint']);
    }
}
